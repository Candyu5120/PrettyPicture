<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\model\{User as UserModel, Code as CodeModel, System as SystemModel, Role as RoleModel, Storage as StorageModel};
use think\Exception;
use app\services\{AuthToken, EmailClass, OidcClient};
use think\{Request, Response};
use think\facade\Validate;
use app\validate\Register as RegisterValidate;
use think\exception\ValidateException;

class Account extends BaseController
{
    private const OIDC_STATE_TTL = 600;
    private const OIDC_MEMBER_ROLE_NAME = 'CY团队成员';
    private const OIDC_MEMBER_CAPACITY_BYTES = 1073741824;

    public function login(Request $request): Response
    {
        $data = $request->param();
        $ip = $request->ip();

        try {
            $user = UserModel::login($data['username'], $data['password']);
            $token = (new AuthToken)->createToken($user['id']);
            $this->setLog($user['id'], "登录了系统", $this->city($ip), $ip);
            return $this->create($token, '登录成功', 200);
        } catch (Exception $e) {
            return $this->create([], $e->getMessage(), 400);
        }
    }

    public function register(Request $request): Response
    {
        $data = $request->param();
        $ip = $request->ip();
        $system = SystemModel::where('type', 'basics')->column('value', 'key');
        
        if ($system['is_reg'] != 1) {
            return $this->create(null, '管理员已关闭用户注册', 400);
        }

        $needEmailVerify = isset($system['reg_email_verify']) && $system['reg_email_verify'] == 1;

        try {
            $validator = Validate(RegisterValidate::class);
            if (!$needEmailVerify) {
                $validator->remove('code', 'require');
            }
            $validator->check($data);
        } catch (ValidateException $exception) {
            return $this->create(null, $exception->getError(), 400);
        }

        if ($needEmailVerify) {
            $res = CodeModel::where('email', $data['email'])
                ->where('code', $data['code'])
                ->where('create_time', '>', time() - 600)
                ->find();
            if (!$res) {
                return $this->create(null, '验证码错误', 400);
            }
        }

        if (UserModel::where("email", $data['email'])->find()) {
            return $this->create(null, '用户已存在', 400);
        }

        $role = RoleModel::where('default', 1)->find();
        $userold = UserModel::onlyTrashed()->where("email", $data['email'])->find();
        
        if ($userold) {
            $userold->restore();
            $userold = UserModel::where("email", $data['email'])->find();
            $userold->role_id = $role['id'];
            $userold->password = password_hash($data['password'], PASSWORD_DEFAULT);
            $userold->username = $data['username'];
            $userold->email = $data['email'];
            $userold->Secret_key = md5($data['email'] . $data['password']);
            $userold->avatar = $data['avatar'] ?? '';
            $userold->capacity = (int)$system['init_quota'] * 1024 * 1024 * 1024;
            $userold->state = 1;
            $userold->reg_ip = $ip;
            $userold->save();
            $this->setLog($userold['id'], "(注册)加入了系统", $this->city($ip), $ip);
        } else {
            $user = new UserModel;
            $user->save([
                'role_id'    => $role['id'],
                'password'   => password_hash($data['password'], PASSWORD_DEFAULT),
                'username'   => $data['username'],
                'email'      => $data['email'],
                'Secret_key' => md5($data['email'] . $data['password']),
                'avatar'     => $data['avatar'] ?? '',
                'capacity'   => (int)$system['init_quota'] * 1024 * 1024 * 1024,
                'state'      => 1,
                'reg_ip'     => $ip,
            ]);
            $this->setLog($user['id'], "(注册)加入了系统", $this->city($ip), $ip);
        }
        
        return $this->create(null, '注册成功', 200);
    }

    public function forget(Request $request): Response
    {
        $data = $request->param();

        $res = CodeModel::where('email', $data['email'])
            ->where('code', $data['code'])
            ->where('create_time', '>', time() - 600)
            ->find();
            
        if (!$res) {
            return $this->create(null, '验证码错误', 400);
        }

        if (!$this->cellemail($data['email'])) {
            return $this->create(null, '用户不存在', 400);
        }

        if (empty($data['password']) || strlen($data['password']) < 6) {
            return $this->create(null, '密码至少6位', 400);
        }

        $user = UserModel::where('email', $data['email'])->find();
        $user->password = password_hash($data['password'], PASSWORD_DEFAULT);
        $user->save();

        return $this->create(null, '密码重置成功', 200);
    }

    public function cellemail(string $email = ""): bool
    {
        return !Validate::rule(['email' => 'unique:user,email'])->check(['email' => $email]);
    }

    public function sendCode(Request $request): Response
    {
        $data = $request->param();
        $ip = $request->ip();
        
        if (!preg_match('/^\w+([-+.]\w+)*@\w+([-.]\w+)*\.\w+([-.]\w+)*$/', $data['email'])) {
            return $this->create(null, '请输入正确的邮箱', 400);
        }

        $code_q = CodeModel::where('email', $data['email'])->order('id desc')->find();
        if ($code_q && (time() - (int)$code_q['create_time']) < 60) {
            return $this->create(null, '发送频繁', 400);
        }

        $code = mt_rand(1000, 9999);

        try {
            (new EmailClass)->send_mail($data['email'], "CY图床验证", '您的验证码为：' . $code);
            (new CodeModel)->save([
                'email' => $data['email'],
                'code'  => $code,
                'ip'    => $ip
            ]);
            return $this->create([], '发送成功', 200);
        } catch (Exception $e) {
            return $this->create([], $e->getMessage(), 400);
        }
    }

    public function oidcStart(): Response
    {
        $config = $this->getOidcConfig();
        if (($config['oidc_enabled'] ?? '0') !== '1') {
            return $this->create([], 'OIDC登录未开启', 400);
        }

        try {
            $state = $this->buildOidcState();
            $url = (new OidcClient($config))->buildAuthorizeUrl($state);
            return $this->create(['url' => $url], '成功', 200);
        } catch (Exception $e) {
            return $this->create([], $e->getMessage(), 400);
        }
    }

    public function oidcCallback(Request $request): Response
    {
        $code = (string)$request->get('code', '');
        $state = (string)$request->get('state', '');
        $redirectBase = rtrim($request->domain(), '/') . '/#/oidc/callback';

        try {
            $token = $this->handleOidcExchange($code, $state, $request->ip());
            return redirect($redirectBase . '?token=' . urlencode($token));
        } catch (Exception $e) {
            return redirect($redirectBase . '?error=' . urlencode($e->getMessage()));
        }
    }

    private function handleOidcExchange(string $code, string $state, string $ip): string
    {
        if ($code === '' || $state === '') {
            throw new Exception('OIDC回调参数不完整');
        }

        if (!$this->verifyOidcState($state)) {
            throw new Exception('OIDC状态校验失败，请重试');
        }

        $config = $this->getOidcConfig();
        if (($config['oidc_enabled'] ?? '0') !== '1') {
            throw new Exception('OIDC登录未开启');
        }

        $email = (new OidcClient($config))->fetchEmailByCode($code);
        $user = UserModel::where('email', $email)->find();

        if (!$user) {
            $autoDomain = strtolower(trim((string)($config['oidc_auto_domain'] ?? 'cyteam.cn')));
            $emailDomain = strtolower((string)substr(strrchr($email, '@') ?: '', 1));

            if ($autoDomain === '' || $emailDomain !== $autoDomain) {
                throw new Exception($email . '在系统中不存在，请联系管理员');
            }

            $user = $this->createOrRestoreOidcUser($email, $ip);
        }

        if ((int)$user['state'] === 0) {
            throw new Exception('你的账户已被停用，请联系管理员！');
        }

        $token = (new AuthToken)->createToken((int)$user['id']);
        $this->setLog((int)$user['id'], '通过OIDC登录了系统', $this->city($ip), $ip);

        return $token;
    }

    private function createOrRestoreOidcUser(string $email, string $ip): UserModel
    {
        $role = $this->getOrCreateOidcMemberRole();
        $baseName = strstr($email, '@', true) ?: 'member';
        $username = $this->buildUniqueUsername($baseName);

        $userold = UserModel::onlyTrashed()->where('email', $email)->find();
        if ($userold) {
            $userold->restore();
            $userold = UserModel::where('email', $email)->find();
            $userold->role_id = $role['id'];
            $userold->username = $username;
            $userold->password = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
            $userold->Secret_key = sha1($email . microtime(true));
            $userold->capacity = self::OIDC_MEMBER_CAPACITY_BYTES;
            $userold->state = 1;
            $userold->reg_ip = $ip;
            $userold->save();
            $this->setLog((int)$userold['id'], '(OIDC)加入了系统', $this->city($ip), $ip);
            return $userold;
        }

        $user = new UserModel;
        $user->save([
            'role_id' => $role['id'],
            'password' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
            'username' => $username,
            'email' => $email,
            'Secret_key' => sha1($email . microtime(true)),
            'avatar' => '',
            'capacity' => self::OIDC_MEMBER_CAPACITY_BYTES,
            'state' => 1,
            'reg_ip' => $ip,
        ]);
        $this->setLog((int)$user['id'], '(OIDC)加入了系统', $this->city($ip), $ip);

        return $user;
    }

    private function getOrCreateOidcMemberRole(): RoleModel
    {
        $role = RoleModel::where('name', self::OIDC_MEMBER_ROLE_NAME)->find();
        if ($role) {
            return $role;
        }

        $storageId = (int)(StorageModel::min('id') ?? 0);
        if ($storageId <= 0) {
            throw new Exception('系统未配置存储桶，无法创建默认成员角色');
        }

        RoleModel::where('default', 1)->update(['default' => 0]);

        return RoleModel::create([
            'storage_id' => $storageId,
            'name' => self::OIDC_MEMBER_ROLE_NAME,
            'is_add' => 1,
            'is_del_own' => 1,
            'is_read' => 1,
            'is_del_all' => 0,
            'is_read_all' => 0,
            'is_admin' => 0,
            'default' => 1,
        ]);
    }

    private function buildUniqueUsername(string $rawName): string
    {
        $cleanName = preg_replace('/[^a-zA-Z0-9_\x{4e00}-\x{9fa5}]/u', '', $rawName) ?: 'member';
        $cleanName = mb_substr($cleanName, 0, 8);

        $username = $cleanName;
        $suffix = 1;
        while (UserModel::where('username', $username)->find()) {
            $extra = (string)$suffix;
            $prefixLength = max(1, 8 - strlen($extra));
            $username = mb_substr($cleanName, 0, $prefixLength) . $extra;
            $suffix++;
            if ($suffix > 9999) {
                $username = 'member' . time();
                break;
            }
        }

        return $username;
    }

    private function getOidcConfig(): array
    {
        return SystemModel::where('type', 'oidc')->column('value', 'key');
    }

    private function buildOidcState(): string
    {
        $payload = [
            'ts' => time(),
            'nonce' => bin2hex(random_bytes(16)),
        ];
        $encoded = rtrim(strtr(base64_encode(json_encode($payload, JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
        $sign = hash_hmac('sha256', $encoded, TokenKey);

        return $encoded . '.' . $sign;
    }

    private function verifyOidcState(string $state): bool
    {
        $parts = explode('.', $state, 2);
        if (count($parts) !== 2) {
            return false;
        }

        [$encoded, $sign] = $parts;
        $expected = hash_hmac('sha256', $encoded, TokenKey);
        if (!hash_equals($expected, $sign)) {
            return false;
        }

        $base64 = strtr($encoded, '-_', '+/');
        $padding = strlen($base64) % 4;
        if ($padding > 0) {
            $base64 .= str_repeat('=', 4 - $padding);
        }

        $json = base64_decode($base64, true);
        if ($json === false) {
            return false;
        }

        $payload = json_decode($json, true);
        if (!is_array($payload) || empty($payload['ts'])) {
            return false;
        }

        return (time() - (int)$payload['ts']) <= self::OIDC_STATE_TTL;
    }
}
