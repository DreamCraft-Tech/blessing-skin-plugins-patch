<?php

namespace Blessing\CdnRealIp;

use Symfony\Component\HttpFoundation\IpUtils;

final class Addresses
{
    public static function ip(mixed $value): ?string
    {
        if (!is_string($value) || strlen($value) > 100) {
            return null;
        }$value = trim($value);
        if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
            return self::canonical($value);
        }
        if (preg_match('/\A\[([^\]]+)\](?::([0-9]{1,5}))?\z/D', $value, $m)) {
            $ip = $m[1];
            $port = $m[2] ?? null;
        } elseif (preg_match('/\A([0-9.]+):([0-9]{1,5})\z/D', $value, $m)) {
            $ip = $m[1];
            $port = $m[2];
        } else {
            return null;
        }
        if (($port !== null && ((int) $port < 1 || (int) $port > 65535)) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

return self::canonical($ip);
    }

    private static function canonical(string $ip): string
    {
        $packed = inet_pton($ip);
        if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat(chr(0), 10).chr(255).chr(255)) {
            return inet_ntop(substr($packed, 12));
        }

        return inet_ntop($packed);
    }

    public static function chain(?string $value): ?array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }
        if (strlen($value) > 8192) {
            return null;
        }$parts = explode(',', $value);
        if (count($parts) > 32) {
            return null;
        }
        $ips = [];
        foreach ($parts as $part) {
            $ip = self::ip($part);
            if ($ip === null) {
                return null;
            }$ips[] = $ip;
        }

return $ips;
    }

    public static function ranges(string $text): array
    {
        if (strlen($text) > 524288) {
            throw new \InvalidArgumentException('地址列表不能超过 512 KiB。');
        }
        $text = preg_replace('/#[^\r\n]*/', '', $text);
        $parts = preg_split('/[\s,;]+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        if (count($parts) > 10000) {
            throw new \InvalidArgumentException('地址列表不能超过 10000 条。');
        }
        $out = [];
        foreach ($parts as $entry) {
            $bits = explode('/', $entry);
            if (count($bits) > 2 || filter_var($bits[0], FILTER_VALIDATE_IP) === false) {
                throw new \InvalidArgumentException('无效的 IP / CIDR：'.mb_substr($entry, 0, 80));
            }
            $binary = inet_pton($bits[0]);
            $max = strlen($binary) * 8;
            $prefix = $max;
            if (isset($bits[1])) {
                if (!preg_match('/\A[0-9]{1,3}\z/D', $bits[1]) || (int) $bits[1] < 1 || (int) $bits[1] > $max) {
                    throw new \InvalidArgumentException('CIDR 前缀长度不正确：'.mb_substr($entry, 0, 80));
                }$prefix = (int) $bits[1];
            }
            $masked = '';
            for ($i = 0; $i < strlen($binary); $i++) {
                $remaining = $prefix - $i * 8;
                $mask = $remaining >= 8 ? 255 : ($remaining <= 0 ? 0 : ((255 << (8 - $remaining)) & 255));
                $masked .= chr(ord($binary[$i]) & $mask);
            }
            $address = inet_ntop($masked);
            if ($prefix === $max && ($address === '0.0.0.0' || $address === '::')) {
                throw new \InvalidArgumentException('请填写具体代理地址或网段。');
            }
            $out[] = $prefix === $max ? $address : $address.'/'.$prefix;
        }

return array_values(array_unique($out));
    }

    public static function contains(string $ip, array $ranges): bool
    {
        return $ranges && IpUtils::checkIp($ip, $ranges);
    }

    public static function specificity(string $ip, array $ranges): int
    {
        $best = -1;
        foreach ($ranges as $range) {
            if (IpUtils::checkIp($ip, $range)) {
                $best = max($best, str_contains($range, '/') ? (int) explode('/', $range)[1] : (str_contains($range, ':') ? 128 : 32));
            }
        }

return $best;
    }
}
