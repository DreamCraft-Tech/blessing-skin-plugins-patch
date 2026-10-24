<?php

declare(strict_types=1);

namespace Blessing\WeCom;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

final class WeComClient
{
    public function authorizationUrl(array $settings, string $state): string
    {
        return 'https://open.work.weixin.qq.com/wwopen/sso/qrConnect?'.http_build_query([
            'appid' => $settings['corp_id'], 'agentid' => $settings['agent_id'],
            'redirect_uri' => $settings['redirect_uri'], 'state' => $state, 'lang' => 'zh',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    private function request(string $path, array $parameters): array
    {
        try {
            $response = Http::acceptJson()->connectTimeout(5)->timeout(10)
                ->withOptions(['allow_redirects' => false])
                ->get('https://qyapi.weixin.qq.com/cgi-bin/'.$path, $parameters);
            $body = $response->json();
            if (!$response->successful() || !is_array($body) || !array_key_exists('errcode', $body)
                || !is_numeric($body['errcode'])) {
                throw new \RuntimeException();
            }
            return $body;
        } catch (\Throwable) {
            // Never propagate HTTP exception URLs: they contain application secrets/tokens.
            throw new Failure('企业微信服务暂时不可用，请稍后重新扫码。', 502);
        }
    }

    private function cacheKey(array $settings): string
    {
        return 'wecom.token.'.hash('sha256', $settings['corp_id']."\0".$settings['agent_id']."\0".$settings['secret']);
    }

    private function token(array $settings): string
    {
        $key = $this->cacheKey($settings);
        $encrypted = Cache::get($key);
        if (is_string($encrypted)) {
            try {
                $token = Crypt::decryptString($encrypted);
                if ($token !== '') {
                    return $token;
                }
            } catch (\Throwable) {
                Cache::forget($key);
            }
        }
        $body = $this->request('gettoken', [
            'corpid' => $settings['corp_id'], 'corpsecret' => $settings['secret'],
        ]);
        if ((int) $body['errcode'] !== 0 || !is_string($body['access_token'] ?? null)
            || $body['access_token'] === '' || (int) ($body['expires_in'] ?? 0) <= 0) {
            throw new Failure('企业微信应用凭据无效或服务器 IP 未获授权，请联系管理员。', 502);
        }
        $ttl = max(1, min(7200, (int) $body['expires_in']) - 60);
        Cache::put($key, Crypt::encryptString($body['access_token']), $ttl);
        return $body['access_token'];
    }

    private function authenticated(string $path, array $parameters, array $settings): array
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $body = $this->request($path, array_merge($parameters, ['access_token' => $this->token($settings)]));
            if (in_array((int) $body['errcode'], [40001, 40014, 42001], true) && $attempt === 0) {
                Cache::forget($this->cacheKey($settings));
                continue;
            }
            if ((int) $body['errcode'] !== 0) {
                throw new Failure('企业微信授权失败或成员不在应用可见范围，请检查应用配置后重新扫码。', 403);
            }
            return $body;
        }
        throw new Failure('企业微信授权失败，请重新扫码。', 403);
    }

    public function member(string $code, array $settings): array
    {
        $identity = $this->authenticated('auth/getuserinfo', ['code' => $code], $settings);
        $id = $identity['userid'] ?? $identity['UserId'] ?? null;
        if (!is_string($id) || $id === '' || strlen($id) > 128) {
            throw new Failure('仅支持本企业内部成员，外部联系人不能使用此登录方式。', 403);
        }
        // The application's token must be allowed to read this member (visible scope).
        $member = $this->authenticated('user/get', ['userid' => $id], $settings);
        $returned = $member['userid'] ?? null;
        if (!is_string($returned) || strcasecmp($returned, $id) !== 0
            || (isset($member['enable']) && (int) $member['enable'] !== 1)
            || (isset($member['status']) && (int) $member['status'] !== 1)) {
            throw new Failure('该企业微信成员已停用、未激活或无法访问此应用。', 403);
        }
        return [
            'corp_id' => $settings['corp_id'], 'member_id' => $returned,
            'member_name' => is_string($member['name'] ?? null) ? mb_substr($member['name'], 0, 255) : '',
        ];
    }
}
