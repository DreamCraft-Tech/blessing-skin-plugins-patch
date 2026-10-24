<?php

declare(strict_types=1);
namespace Blessing\WeCom;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use MaxMind\Db\Reader;

class IpLocation
{
    public function databasePath(): string
    {
        return (string) env('WECOM_GEOIP_DATABASE', storage_path('app/wecom-login/geoip-city.mmdb'));
    }

    public function ready(): bool
    {
        $path = $this->databasePath();
        return is_file($path) && is_readable($path) && filemtime($path) >= time() - 62 * 86400;
    }

    public function databaseInfo(): array
    {
        $path = $this->databasePath();
        clearstatcache(true, $path);
        $timestamp = is_file($path) ? filemtime($path) : false;
        return ['ready' => $this->ready(), 'updated_at' => $timestamp ?: null,
            'updated_at_label' => $timestamp ? \Carbon\CarbonImmutable::createFromTimestamp($timestamp, config('app.timezone', 'UTC'))->format('Y-m-d H:i:s T') : '尚未更新'];
    }

    public function city(string $ip): string
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) || !$this->ready()) {return '未知城市';}
        try {
            $reader = new Reader($this->databasePath());
            try {$record = $reader->get($ip);} finally {$reader->close();}
            $name = $record['city']['names']['zh-CN'] ?? $record['city']['names']['en'] ?? '';
            return is_string($name) && trim($name) !== '' ? mb_substr(trim($name), 0, 96) : '未知城市';
        } catch (\Throwable) {return '未知城市';}
    }

    public static function distance(array $a, array $b): float
    {
        $lat = deg2rad($b['latitude'] - $a['latitude']);
        $lon = deg2rad($b['longitude'] - $a['longitude']);
        $value = sin($lat / 2) ** 2 + cos(deg2rad($a['latitude'])) * cos(deg2rad($b['latitude'])) * sin($lon / 2) ** 2;
        return 6371 * 2 * asin(min(1.0, sqrt(max(0.0, $value))));
    }

    private function point(mixed $country, mixed $latitude, mixed $longitude, mixed $city = null): ?array
    {
        if (!is_string($country) || !preg_match('/\A[A-Z]{2}\z/D', $country)
            || !is_numeric($latitude) || !is_numeric($longitude)) {return null;}
        $lat = (float) $latitude;$lon = (float) $longitude;
        if (!is_finite($lat) || !is_finite($lon) || abs($lat) > 90 || abs($lon) > 180) {return null;}
        return ['country' => $country, 'latitude' => $lat, 'longitude' => $lon,
            'city' => is_string($city) ? mb_substr(trim(preg_replace('/[\x00-\x1f\x7f]/u', '', $city) ?? ''), 0, 96) : ''];
    }

    public function verified(string $ip): ?array
    {
        // Never query an online service with local, reserved, malformed or empty addresses.
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) || !$this->ready()) {return null;}
        $canonical = inet_ntop(inet_pton($ip));
        $key = hash_hmac('sha256', $canonical.'|'.filemtime($this->databasePath()), (string) config('app.key'));
        try {
            $reader = new Reader($this->databasePath());
            try {$raw = $reader->get($canonical);} finally {$reader->close();}
            $local = $this->point($raw['country']['iso_code'] ?? null, $raw['location']['latitude'] ?? null, $raw['location']['longitude'] ?? null, $raw['city']['names']['zh-CN'] ?? $raw['city']['names']['en'] ?? null);
            if (!$local) {return null;}
            $cached = DB::table(Installer::GEO_CACHE)->where('ip_hash', $key)->where('expires_at', '>', time())->first();
            if ($cached) {
                $online = json_decode($cached->location_json, true);
                $online = is_array($online) ? $this->point($online['country'] ?? null, $online['latitude'] ?? null, $online['longitude'] ?? null, $online['city'] ?? null) : null;
            } else {
                if (Cache::has('wecom.geo.online.cooldown')) {return null;}
                $response = Http::acceptJson()->connectTimeout(2)->timeout(4)->withOptions(['allow_redirects' => false])
                    ->get('https://ipwho.is/'.rawurlencode($canonical), ['fields' => 'success,ip,country_code,latitude,longitude,city']);
                if (!$response->successful()) {
                    Cache::put('wecom.geo.online.cooldown', true, 300);
                    return null;
                }
                $body = $response->json();
                if (!is_array($body) || ($body['success'] ?? false) !== true || !is_string($body['ip'] ?? null)
                    || @inet_pton($body['ip']) !== inet_pton($canonical)) {return null;}
                $online = $this->point($body['country_code'] ?? null, $body['latitude'] ?? null, $body['longitude'] ?? null, $body['city'] ?? null);
                if (!$online) {return null;}
                // Persist only minimal location fields, never the raw response or raw IP.
                DB::table(Installer::GEO_CACHE)->updateOrInsert(['ip_hash' => $key], [
                    'location_json' => json_encode($online, JSON_THROW_ON_ERROR), 'expires_at' => time() + 86400,
                ]);
                DB::table(Installer::GEO_CACHE)->where('expires_at', '<', time())->delete();
            }
            if (!$online || $online['country'] !== $local['country'] || self::distance($local, $online) > 200) {return null;}
            if ($local['country'] === 'CN') {
                $localEnglish = $raw['city']['names']['en'] ?? null;
                if ($local['city'] === '' || !is_string($localEnglish) || $online['city'] === ''
                    || mb_strtolower(trim($localEnglish)) !== mb_strtolower(trim($online['city']))) {return null;}
            }
            // Use one consistent local city name for comparison, after independent online verification.
            $online['city'] = $local['city'];
            return $online;
        } catch (\Throwable) {
            return null;
        }
    }
}
