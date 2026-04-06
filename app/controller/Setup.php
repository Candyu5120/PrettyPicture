<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\model\{System as SystemModel, User as UserModel};
use think\Exception;
use app\services\EmailClass;
use think\{Request, Response};

class Setup extends BaseController
{
    public function index(Request $request, string $type = ""): Response
    {
        if ($type === 'basics') {
            $this->seedBasicsDefaults();
        }

        $system = SystemModel::where('type', $type)->select()->toArray();
        
        foreach ($system as &$value) {
            if (!empty($value['extend'])) {
                $value['extend'] = json_decode($value['extend'], true);
            }
            if ($value['attr'] === 'number') {
                $value['value'] = (int)$value['value'];
            }
        }
        
        return $this->create($system, '查询成功', 200);
    }

    public function update(Request $request): Response
    {
        $uid = $request->uid;
        $data = $request->param();
        
        foreach ($data['createData'] as $value) {
            $system = SystemModel::find($value['id']);
            $system->value = $value['value'];
            $system->save();
        }
        
        $this->setLog($uid, "修改了设置", "", "");
        return $this->create([], '成功', 200);
    }

    private function seedBasicsDefaults(): void
    {
        $defaults = [
            ['key' => 'site_name', 'attr' => 'input', 'type' => 'basics', 'title' => '站点名称', 'des' => '站点顶部与页面标题显示名称', 'value' => 'CY图床', 'extend' => null],
            ['key' => 'record_show', 'attr' => 'switch', 'type' => 'basics', 'title' => '显示备案信息', 'des' => '是否在首页展示 ICP 和公安网备信息', 'value' => '0', 'extend' => null],
            ['key' => 'record_icp', 'attr' => 'input', 'type' => 'basics', 'title' => 'ICP备案号', 'des' => '例如：京ICP备12345678号-1', 'value' => '', 'extend' => null],
            ['key' => 'record_public', 'attr' => 'input', 'type' => 'basics', 'title' => '公安网备号', 'des' => '例如：京公网安备11000002000001号', 'value' => '', 'extend' => null],
        ];

        foreach ($defaults as $item) {
            $exists = SystemModel::where('key', $item['key'])->find();
            if (!$exists) {
                (new SystemModel)->save($item);
            }
        }
    }

    public function sendTest(Request $request): Response
    {
        $uid = $request->uid;
        $email = UserModel::where('id', $uid)->value('email');

        try {
            (new EmailClass)->send_mail($email, "邮箱对接成功", "您的邮箱对接成功");
            return $this->create([], '发送成功', 200);
        } catch (Exception $e) {
            return $this->create([], $e->getMessage(), 400);
        }
    }
}
