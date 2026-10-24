<?php

declare(strict_types=1);

namespace Blessing\LoginHistory;

use Illuminate\Http\Request;

final class WebChannels
{
    public static function detect(Request $request, bool $viaRemember): string
    {
        if ($viaRemember) {return 'remember';}
        // Optional integration point for other server-side authentication plugins.
        $marked = $request->attributes->get('login_history.web_channel');
        if (is_string($marked) && preg_match('/\A(?:password|wecom|oauth(?::[a-z0-9][a-z0-9_-]{0,63})?|custom:[a-z0-9][a-z0-9_-]{0,63})\z/D', $marked)) {
            return $marked;
        }
        $route = $request->route();
        $action = ltrim((string) $route?->getActionName(), '\\');
        if ($action === 'App\\Http\\Controllers\\AuthController@handleLogin') {return 'password';}
        if ($action === 'Blessing\\WeCom\\Controller@callback') {return 'wecom';}
        if ($action === 'Blessing\\OAuthCore\\AuthController@callback') {
            $driver = $route->parameter('driver');
            if (is_string($driver) && preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}\z/D', $driver)) {
                return 'oauth:'.strtolower($driver);
            }
            return 'oauth';
        }
        return 'unknown';
    }

    public static function label(?string $channel, string $backend = ''): string
    {
        $labels = ['password' => '密码登录', 'wecom' => '企业微信', 'custom:passkey' => '通行密钥', 'custom:two-factor' => '两步验证', 'remember' => '记住我 · 自动恢复会话',
            'oauth' => 'OAuth（渠道未提供）', 'oauth:github' => 'GitHub', 'oauth:google' => 'Google',
            'oauth:live' => 'Microsoft Live', 'oauth:microsoft' => 'Microsoft', 'oauth:littleskin' => 'LittleSkin',
            'unknown' => '其他 / 未识别渠道', 'legacy' => '旧版记录（渠道信息不完整）'];
        if ($channel === null || $channel === '') {
            return match ($backend) {
                'wecom' => '企业微信（旧版记录）', 'oauth' => 'OAuth（旧版未记录渠道）',
                'remember' => '记住我（旧版未区分登录与恢复）', default => $labels['legacy'],
            };
        }
        if (isset($labels[$channel])) {return $labels[$channel];}
        if (str_starts_with($channel, 'oauth:')) {return 'OAuth · '.substr($channel, 6);}
        if (str_starts_with($channel, 'custom:')) {return '第三方 · '.substr($channel, 7);}
        return $labels['unknown'];
    }
}
