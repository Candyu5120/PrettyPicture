<?php

declare(strict_types=1);

namespace app\services;

use GuzzleHttp\Client;
use think\Exception;

class OidcClient
{
    private Client $http;

    public function __construct(private readonly array $config)
    {
        $this->http = new Client([
            'timeout' => 10,
            'http_errors' => false,
        ]);
    }

    public function buildAuthorizeUrl(string $state): string
    {
        $discovery = $this->getDiscovery();
        $authorizeEndpoint = $discovery['authorization_endpoint'] ?? '';
        if ($authorizeEndpoint === '') {
            throw new Exception('OIDC配置不完整：缺少authorization_endpoint');
        }

        $query = http_build_query([
            'client_id' => $this->config['oidc_client_id'] ?? '',
            'redirect_uri' => $this->config['oidc_redirect_uri'] ?? '',
            'response_type' => 'code',
            'scope' => $this->config['oidc_scope'] ?? 'openid email profile',
            'state' => $state,
        ]);

        return $authorizeEndpoint . (str_contains($authorizeEndpoint, '?') ? '&' : '?') . $query;
    }

    public function fetchEmailByCode(string $code): string
    {
        $discovery = $this->getDiscovery();
        $token = $this->exchangeToken($discovery, $code);
        $userinfo = $this->fetchUserinfo($discovery, $token['access_token'] ?? '');
        $email = strtolower(trim((string)($userinfo['email'] ?? '')));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('OIDC返回的邮箱无效');
        }

        if (array_key_exists('email_verified', $userinfo)) {
            $verified = $userinfo['email_verified'];
            $isVerified = $verified === true || $verified === 1 || $verified === '1' || $verified === 'true';
            if (!$isVerified) {
                throw new Exception('OIDC邮箱未验证，请先完成邮箱验证');
            }
        }

        return $email;
    }

    private function getDiscovery(): array
    {
        $issuer = rtrim((string)($this->config['oidc_issuer'] ?? ''), '/');
        if ($issuer === '') {
            throw new Exception('请先配置OIDC Issuer');
        }

        $url = $issuer . '/.well-known/openid-configuration';
        $response = $this->http->get($url);
        if ($response->getStatusCode() !== 200) {
            throw new Exception('OIDC discovery获取失败');
        }

        $data = json_decode((string)$response->getBody(), true);
        if (!is_array($data)) {
            throw new Exception('OIDC discovery返回格式错误');
        }

        return $data;
    }

    private function exchangeToken(array $discovery, string $code): array
    {
        $tokenEndpoint = $discovery['token_endpoint'] ?? '';
        if ($tokenEndpoint === '') {
            throw new Exception('OIDC配置不完整：缺少token_endpoint');
        }

        $response = $this->http->post($tokenEndpoint, [
            'headers' => ['Accept' => 'application/json'],
            'form_params' => [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->config['oidc_redirect_uri'] ?? '',
                'client_id' => $this->config['oidc_client_id'] ?? '',
                'client_secret' => $this->config['oidc_client_secret'] ?? '',
            ],
        ]);

        $data = json_decode((string)$response->getBody(), true);
        if ($response->getStatusCode() !== 200 || !is_array($data)) {
            throw new Exception('OIDC令牌交换失败');
        }

        if (empty($data['access_token'])) {
            throw new Exception((string)($data['error_description'] ?? 'OIDC未返回access_token'));
        }

        return $data;
    }

    private function fetchUserinfo(array $discovery, string $accessToken): array
    {
        $userinfoEndpoint = $discovery['userinfo_endpoint'] ?? '';
        if ($userinfoEndpoint === '') {
            throw new Exception('OIDC配置不完整：缺少userinfo_endpoint');
        }

        $response = $this->http->get($userinfoEndpoint, [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Accept' => 'application/json',
            ],
        ]);

        $data = json_decode((string)$response->getBody(), true);
        if ($response->getStatusCode() !== 200 || !is_array($data)) {
            throw new Exception('OIDC用户信息获取失败');
        }

        return $data;
    }
}
