<?php

namespace Blessing\CdnRealIp;

use Illuminate\Http\Request;

final class Resolver
{
    private function header(Request $request, string $name): ?string
    {
        $values = $request->headers->all($name);

        return $values ? implode(',', $values) : null;
    }

    public function resolve(Request $request, ?array $settings = null): array
    {
        $settings ??= app(Settings::class)->values();
        $peer = Addresses::ip($request->server('REMOTE_ADDR'));
        $native = in_array(strtolower((string) $request->server('HTTPS', 'off')), ['on', '1', 'true'], true) ? 'https' : 'http';
        $out = ['peer' => $peer, 'ip' => $peer, 'scheme' => $native, 'provider' => null, 'ip_header' => null, 'proto_header' => null, 'status' => 'direct', 'message' => '沿用服务器连接地址。', 'chain' => [], 'scheme_note' => null];
        if (!$settings['enabled']) {
            $out['status'] = 'disabled';
            $out['message'] = '插件处理已关闭。';

            return $out;
        }
        if (!$peer) {
            $out['status'] = 'invalid_peer';
            $out['message'] = '服务器未提供有效的连接地址。';

            return $out;
        }
        $xff = Addresses::chain($this->header($request, 'X-Forwarded-For'));
        $out['chain'] = $xff ?? [];
        $token = ($this->header($request, 'X-Blessing-CDN-Auth') ?? '');
        $token = is_string($token) && strlen($token) <= 256 ? $token : '';
        $active = [];
        foreach (Profiles::all() as $id => $profile) {
            $rule = $settings['providers'][$id];
            if (!$rule['enabled']) {
                continue;
            }
            $hasToken = $rule['token_hash'] !== '';
            $tokenOk = $hasToken && hash_equals($rule['token_hash'], hash('sha256', $token));
            if ($hasToken && !$tokenOk) {
                continue;
            }
            $active[$id] = ['profile' => $profile, 'rule' => $rule, 'token' => $tokenOk];
        }
        $candidate = $peer;
        $remaining = $xff;
        $matched = null;
        for ($hop = 0; $hop <= 32; $hop++) {
            $choices = [];
            foreach ($active as $id => $data) {
                $score = Addresses::specificity($candidate, $data['rule']['ranges']);
                if ($score >= 0 || ($data['token'] && !$data['rule']['ranges'])) {
                    $choices[$id] = $score >= 0 ? $score : 0;
                }
            }
            if ($choices) {
                $max = max($choices);
                $ids = array_keys(array_filter($choices, fn ($n) => $n === $max));
                if (count($ids) !== 1) {
                    $out['status'] = 'ambiguous';
                    $out['message'] = '同一地址匹配多条同优先级规则，请调整地址范围。';

                    return $out;
                }
                $matched = $ids[0];
                break;
            }
            if (!Addresses::contains($candidate, $settings['local_proxies']) || !$remaining) {
                break;
            }
            $candidate = array_pop($remaining);
        }
        if ($matched === null) {
            $out['status'] = 'untrusted';
            $out['message'] = '连接地址尚未匹配已配置的 CDN / 代理规则。';

            return $out;
        }
        $data = $active[$matched];
        $profile = $data['profile'];
        $rule = $data['rule'];
        if ($matched === 'custom') {
            $profile['ip'] = $rule['ip_header'];
            $profile['mode'] = $rule['ip_mode'];
            $profile['proto'] = $rule['proto_header'];
        }
        $out['provider'] = $matched;
        $header = $profile['ip'];
        $raw = $this->header($request, $header);
        $client = null;
        if ($matched === 'cloudflare') {
            $v4 = Addresses::ip($raw);
            $v6 = Addresses::ip($this->header($request, 'CF-Connecting-IPv6'));
            if ($v4 && Addresses::contains($v4, ['240.0.0.0/4']) && $v6 && str_contains($v6, ':')) {
                $client = $v6;
                $header = 'CF-Connecting-IPv6';
            }
        }
        if (!$client && $profile['mode'] === 'single') {
            if ($raw !== null) {
                $client = Addresses::ip($raw);
            } elseif (in_array($matched, ['edgeone', 'aliyun', 'volcengine'], true)) {
                $header = 'X-Forwarded-For';
                $raw = $this->header($request, $header);
                $profile['mode'] = 'chain';
            }
        }
        if ($profile['mode'] === 'chain') {
            $chain = $header === 'X-Forwarded-For' ? $xff : Addresses::chain($raw);
            if ($chain) {
                if ($matched === 'tencent' && $data['token'] && !$rule['ranges']) {
                    $client = $chain[0];
                } else {
                    $trusted = $settings['local_proxies'];
                    foreach ($active as $entry) {
                        $trusted = array_merge($trusted, $entry['rule']['ranges']);
                    }
                    $trusted[] = $peer;
                    $trusted[] = $candidate;
                    foreach (array_reverse($chain) as $ip) {
                        if (!Addresses::contains($ip, $trusted)) {
                            $client = $ip;
                            break;
                        }
                    }
                }
            }
        }
        if (!$client) {
            $out['status'] = 'invalid_header';
            $out['message'] = '已匹配 CDN，但客户端地址头缺失或格式不正确。';
        } else {
            $out['ip'] = $client;
            $out['ip_header'] = $header;
            $out['status'] = 'resolved';
            $out['message'] = '已按可信规则还原客户端地址。';
        }
        if ($settings['sync_scheme'] && $profile['proto'] !== '') {
            $proto = $this->header($request, $profile['proto']);
            if ($proto !== null) {
                $proto = strtolower(trim($proto));
                if ($matched === 'edgeone' && $proto === 'quic') {
                    $proto = 'https';
                }
                if (in_array($proto, ['http', 'https'], true)) {
                    $out['scheme'] = $proto;
                    $out['proto_header'] = $profile['proto'];
                } else {
                    $out['scheme_note'] = '协议头格式不正确，沿用回源连接协议。';
                }
            } else {
                $out['scheme_note'] = '未收到客户端协议头，沿用回源连接协议。';
            }
        }

        return $out;
    }
}
