<?php

declare(strict_types=1);
namespace Blessing\LoginHistory;

use MaxMind\Db\Reader;

final class GeoLocation
{
    private array $results = [];
    private ?Reader $reader = null;
    private bool $opened = false;

    public function __construct(private ?string $path = null) {}

    public function label(string $ip): string
    {
        if (isset($this->results[$ip])) {return $this->results[$ip];}
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {return '归属地待查询';}
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE)) {return '内网或保留地址';}
        try {
            if (!$this->opened) {
                $this->opened = true;
                $path = $this->path ?? (string) env('WECOM_GEOIP_DATABASE', storage_path('app/wecom-login/geoip-city.mmdb'));
                if (is_file($path) && is_readable($path)) {$this->reader = new Reader($path);}
            }
            if (!$this->reader) {return $this->results[$ip] = '归属地待查询';}
            $data = $this->reader->get($ip);
            $parts = [];
            foreach ([$data['country'] ?? [], $data['subdivisions'][0] ?? [], $data['city'] ?? []] as $part) {
                $name = $part['names']['zh-CN'] ?? $part['names']['en'] ?? '';
                if (is_string($name) && trim($name) !== '') {$parts[] = mb_substr(trim($name), 0, 96);}
            }
            return $this->results[$ip] = ($parts ? implode(' · ', array_unique($parts)) : '归属地待查询');
        } catch (\Throwable) {return $this->results[$ip] = '归属地待查询';}
    }

    public function __destruct()
    {
        $this->reader?->close();
    }
}
