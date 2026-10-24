<?php

namespace Blessing\CdnRealIp;

final class Settings
{
    public function values(): array
    {
        $saved = json_decode((string) option('cdn_real_ip_settings', '{}'), true);
        $saved = is_array($saved) ? $saved : [];
        $defaults = ['enabled' => true, 'sync_scheme' => true, 'local_proxies' => [], 'providers' => []];
        foreach (Profiles::all() as $id => $profile) {
            $defaults['providers'][$id] = ['enabled' => $id === 'tencent', 'ranges' => [], 'token_hash' => '', 'updated_at' => null, 'source' => 'manual'];
        }
        $defaults['providers']['custom'] += ['ip_header' => 'X-Forwarded-For', 'ip_mode' => 'chain', 'proto_header' => 'X-Forwarded-Proto'];

        return array_replace_recursive($defaults, $saved);
    }

    public function save(array $values): void
    {
        option(['cdn_real_ip_settings' => json_encode($values, JSON_UNESCAPED_UNICODE)]);
    }

    public function texts(): array
    {
        $s = $this->values();
        $s['local_proxies'] = implode("\n", $s['local_proxies']);
        foreach ($s['providers'] as &$provider) {
            $provider['ranges'] = implode("\n", $provider['ranges']);
            $provider['token_set'] = $provider['token_hash'] !== '';
            unset($provider['token_hash']);
        }unset($provider);

        return $s;
    }
}
