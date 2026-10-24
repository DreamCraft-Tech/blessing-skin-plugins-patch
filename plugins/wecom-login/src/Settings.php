<?php

declare(strict_types=1);

namespace Blessing\WeCom;

final class Settings
{
    public function values(): array
    {
        return [
            'corp_id' => trim((string) option('wecom_corp_id', config('wecom.corp_id', ''))),
            'agent_id' => trim((string) option('wecom_agent_id', config('wecom.agent_id', ''))),
            'redirect_uri' => trim((string) option('wecom_redirect_uri', config('wecom.redirect_uri', ''))),
            'secret' => trim((string) config('wecom.secret', '')),
        ];
    }

    public function requireValid(): array
    {
        $values = $this->values();
        if (!$this->validPublicSettings($values) || $values['secret'] === '') {
            throw new Failure('企业微信登录尚未配置完成，请联系管理员。', 503);
        }
        return $values;
    }

    public function ready(): bool
    {
        try {
            $this->requireValid();
            return true;
        } catch (Failure) {
            return false;
        }
    }

    public function validPublicSettings(array $values): bool
    {
        if (!preg_match('/\A[A-Za-z0-9_-]{4,128}\z/D', $values['corp_id'] ?? '')
            || !preg_match('/\A[1-9][0-9]{0,19}\z/D', $values['agent_id'] ?? '')) {
            return false;
        }
        $uri = (string) ($values['redirect_uri'] ?? '');
        $parts = parse_url($uri);
        return filter_var($uri, FILTER_VALIDATE_URL) !== false
            && is_array($parts)
            && ($parts['scheme'] ?? '') === 'https'
            && !empty($parts['host'])
            && ($parts['path'] ?? '') === '/auth/wecom/callback'
            && !isset($parts['user']) && !isset($parts['pass'])
            && !isset($parts['query']) && !isset($parts['fragment']);
    }

    public function fingerprint(array $values): string
    {
        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
    }
}
