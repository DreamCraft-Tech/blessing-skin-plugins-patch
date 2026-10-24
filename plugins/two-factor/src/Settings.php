<?php

namespace Blessing\TwoFactor;

use App\Models\User;

final class Settings
{
    public function values(): array
    {
        return array_replace(['policy' => 'optional', 'allow_totp' => true, 'allow_passkeys' => true, 'direct_login' => true,
            'origin' => rtrim((string) config('app.url'), '/'), 'rp_name' => (string) option('site_name', 'Blessing Skin'),
            'user_verification' => 'required', 'attachment' => 'any', 'max_passkeys' => 10, 'max_totp' => 5,
            'timeout' => 60, 'challenge_ttl' => 120, 'totp_window' => 1, 'reauth_seconds' => 300],
            json_decode((string) option('two_factor_settings', '{}'), true) ?: []);
    }

    public function required(User $user): bool
    {
        return $this->values()['policy'] === 'all' || ($this->values()['policy'] === 'admins' && $user->isAdmin());
    }

    public function revision(): string
    {
        return hash('sha256', json_encode($this->values()));
    }

    public function origin(): string
    {
        $url = rtrim($this->values()['origin'], '/');
        $parts = parse_url($url);
        if (!$parts || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || !in_array($parts['path'] ?? '', ['', '/'], true)
            || (($parts['scheme'] ?? '') !== 'https' && !(($parts['scheme'] ?? '') === 'http' && $parts['host'] === 'localhost'))) {
            throw new \RuntimeException('请在插件配置中设置站点 HTTPS 地址。');
        }

        return $url;
    }

    public function rpId(): string
    {
        return (string) parse_url($this->origin(), PHP_URL_HOST);
    }
}
