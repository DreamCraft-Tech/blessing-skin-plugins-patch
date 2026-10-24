<?php

declare(strict_types=1);
namespace Blessing\WeCom;

use Illuminate\Support\Facades\Http;
use MaxMind\Db\Reader;

final class GeoDatabaseUpdater
{
    private const CHUNK = 4194304;
    private const DECODE_CHUNK = 8388608;

    private function directory(): string {return dirname(app(IpLocation::class)->databasePath());}
    private function part(array $state, string $suffix): string {return $this->directory().'/geoip-'.$state['id'].'.'.$suffix;}

    private function locked(callable $callback): array
    {
        $dir = $this->directory();
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {throw new \RuntimeException('城市库目录不可写。');}
        $lock = fopen($dir.'/geoip-update.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {if ($lock) {fclose($lock);}throw new \RuntimeException('正在处理更新，请稍后重试。');}
        try {return $callback();} finally {flock($lock, LOCK_UN);fclose($lock);}
    }

    private function read(): ?array
    {
        $file = $this->directory().'/geoip-update.json';
        $state = is_file($file) ? json_decode(file_get_contents($file), true) : null;
        return is_array($state) && isset($state['id'], $state['phase']) ? $state : null;
    }

    private function save(array $state): array
    {
        $state['updated_at'] = time();
        $path = $this->directory().'/geoip-update.json';
        if (file_put_contents($path.'.tmp', json_encode($state, JSON_THROW_ON_ERROR)) === false || !rename($path.'.tmp', $path)) {throw new \RuntimeException('无法保存更新进度。');}
        return $state;
    }

    public function status(): array {return $this->locked(fn () => $this->read() ?? ['phase' => 'idle', 'message' => '尚未开始更新。']);}

    public function start(): array
    {
        return $this->locked(function (): array {
            $old = $this->read();
            if ($old && !in_array($old['phase'], ['complete', 'error'], true) && $old['updated_at'] > time() - 7200) {return $old;}
            if ($old) {$this->cleanup($old);}
            return $this->save(['id' => bin2hex(random_bytes(16)), 'phase' => 'preparing', 'month_offset' => 0,
                'downloaded' => 0, 'total' => 0, 'decoded' => 0, 'progress' => 0, 'message' => '正在检查城市库下载。']);
        });
    }

    public function step(string $id): array
    {
        return $this->locked(function () use ($id): array {
            $state = $this->read();
            if (!$state || !hash_equals($state['id'], $id)) {throw new \RuntimeException('更新任务已变化，请刷新进度。');}
            if (in_array($state['phase'], ['complete', 'error'], true)) {return $state;}
            try {
                if ($state['phase'] === 'preparing') {$state = $this->prepare($state);}
                elseif ($state['phase'] === 'downloading') {$state = $this->download($state);}
                elseif ($state['phase'] === 'decompressing') {$state = $this->decompress($state);}
                elseif ($state['phase'] === 'validating') {$state = $this->finish($state);}
                else {throw new \RuntimeException('无效的更新状态。');}
            } catch (\Throwable) {
                $state['phase'] = 'error';
                $state['message'] = '更新失败，请检查下载网络、磁盘空间和 storage 目录写权限后重试。';
                $this->cleanup($state);
            }
            return $this->save($state);
        });
    }

    private function prepare(array $state): array
    {
        $month = (new \DateTimeImmutable('first day of this month'))->modify('-'.$state['month_offset'].' months')->format('Y-m');
        $state['url'] = 'https://download.db-ip.com/free/dbip-city-lite-'.$month.'.mmdb.gz';
        $response = Http::connectTimeout(3)->timeout(12)->withOptions(['allow_redirects' => false])->head($state['url']);
        if ($response->status() === 404 && $state['month_offset'] < 2) {$state['month_offset']++;return $state;}
        $size = $response->header('Content-Length');
        if (!$response->successful() || !ctype_digit((string) $size) || (int) $size <= 0 || (int) $size > 200 * 1024 * 1024) {throw new \RuntimeException('下载信息不可用。');}
        $state['total'] = (int) $size;$state['month'] = $month;
        $state['validator'] = $response->header('ETag') ?: $response->header('Last-Modified');
        $state['phase'] = 'downloading';$state['message'] = '正在分段下载 '.$month.' 城市库。';
        return $state;
    }

    private function download(array $state): array
    {
        $offset = $state['downloaded'];$end = min($state['total'] - 1, $offset + self::CHUNK - 1);
        $headers = ['Range' => 'bytes='.$offset.'-'.$end];
        if (!empty($state['validator'])) {$headers['If-Range'] = $state['validator'];}
        $response = Http::connectTimeout(3)->timeout(18)->withHeaders($headers)
            ->withOptions(['allow_redirects' => false, 'stream' => true])->get($state['url']);
        $body = $response->toPsrResponse()->getBody();
        try {
            if ($response->status() === 206) {
                if ($response->header('Content-Range') !== 'bytes '.$offset.'-'.$end.'/'.$state['total']) {throw new \RuntimeException('分段下载校验失败。');}
            } elseif (!($response->status() === 200 && $offset === 0 && $state['total'] <= self::CHUNK)) {throw new \RuntimeException('下载服务器未提供有效分段。');}
            $file = fopen($this->part($state, 'gz.part'), $offset === 0 ? 'wb' : 'r+b');
            if (!$file) {throw new \RuntimeException('无法写入下载文件。');}
            try {
                fseek($file, $offset);$remaining = $end - $offset + 1;
                while ($remaining > 0 && !$body->eof()) {
                    $chunk = $body->read(min(65536, $remaining));
                    if ($chunk === '' || fwrite($file, $chunk) !== strlen($chunk)) {throw new \RuntimeException('下载或磁盘写入中断。');}
                    $remaining -= strlen($chunk);
                }
                if ($remaining !== 0) {throw new \RuntimeException('分段下载不完整。');}
            } finally {fclose($file);}
        } finally {$body->close();}
        $state['downloaded'] = $end + 1;$state['progress'] = (int) floor(70 * $state['downloaded'] / $state['total']);
        if ($state['downloaded'] === $state['total']) {$state['phase'] = 'decompressing';$state['message'] = '下载完成，正在解压城市库。';}
        return $state;
    }

    private function decompress(array $state): array
    {
        $input = gzopen($this->part($state, 'gz.part'), 'rb');
        $output = fopen($this->part($state, 'mmdb.part'), $state['decoded'] === 0 ? 'wb' : 'r+b');
        if (!$input || !$output) {if ($input) {gzclose($input);}if ($output) {fclose($output);}throw new \RuntimeException('无法解压。');}
        try {
            if (gzseek($input, $state['decoded']) !== 0 || fseek($output, $state['decoded']) !== 0) {throw new \RuntimeException('解压进度不可恢复。');}
            $written = 0;
            while (!gzeof($input) && $written < self::DECODE_CHUNK) {
                $chunk = gzread($input, min(65536, self::DECODE_CHUNK - $written));
                if ($chunk === false || ($chunk === '' && !gzeof($input))) {throw new \RuntimeException('压缩数据无效。');}
                $written += strlen($chunk);
                if ($state['decoded'] + $written > 512 * 1024 * 1024 || fwrite($output, $chunk) !== strlen($chunk)) {throw new \RuntimeException('城市库过大或磁盘空间不足。');}
            }
            $state['decoded'] += $written;$state['progress'] = 75;
            if (gzeof($input)) {$state['phase'] = 'validating';$state['progress'] = 95;$state['message'] = '正在校验城市库。';}
        } finally {gzclose($input);fclose($output);}
        return $state;
    }

    private function finish(array $state): array
    {
        $reader = new Reader($this->part($state, 'mmdb.part'));
        try {if (stripos($reader->metadata()->databaseType, 'city') === false) {throw new \RuntimeException('数据库类型不正确。');}} finally {$reader->close();}
        chmod($this->part($state, 'mmdb.part'), 0644);
        if (!rename($this->part($state, 'mmdb.part'), app(IpLocation::class)->databasePath())) {throw new \RuntimeException('无法替换城市库。');}
        $this->cleanup($state);$state['phase'] = 'complete';$state['progress'] = 100;$state['message'] = '城市库已更新，可以使用宽松登录提醒。';
        return $state;
    }

    private function cleanup(array $state): void
    {
        foreach (['gz.part', 'mmdb.part'] as $suffix) {$path = $this->part($state, $suffix);if (is_file($path)) {unlink($path);}}
    }
}
