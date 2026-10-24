<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {http_response_code(404);exit;}
require dirname(__DIR__).'/vendor/autoload.php';
$options = getopt('', ['site-root:']);
$root = realpath($options['site-root'] ?? dirname(__DIR__, 3));
if (!$root || !is_file($root.'/artisan')) {fwrite(STDERR, "请使用 --site-root=/path/to/skin 指定网站项目根目录。\n");exit(1);}
if (!extension_loaded('curl') || !extension_loaded('zlib')) {fwrite(STDERR, "需要 PHP curl 与 zlib 扩展。\n");exit(1);}
$directory = $root.'/storage/app/wecom-login';
if (!is_dir($directory) && !mkdir($directory, 0755, true)) {throw new RuntimeException('无法创建城市库目录。');}
$lock = fopen($directory.'/geoip-update.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {throw new RuntimeException('另一个城市库更新正在运行。');}
$compressed = tempnam($directory, 'geoip-gz-');
$decoded = tempnam($directory, 'geoip-mmdb-');
try {
    $downloaded = false;
    // The current month's release may not yet exist; try two previous months too.
    for ($offset = 0; $offset < 3; $offset++) {
        $month = (new DateTimeImmutable('first day of this month'))->modify('-'.$offset.' months')->format('Y-m');
        $url = 'https://download.db-ip.com/free/dbip-city-lite-'.$month.'.mmdb.gz';
        $output = fopen($compressed, 'wb');
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_FILE => $output, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 600, CURLOPT_FAILONERROR => true,
            CURLOPT_NOPROGRESS => false, CURLOPT_USERAGENT => 'Blessing-WeCom-GeoIP-Updater/1.4',
            CURLOPT_XFERINFOFUNCTION => static fn ($handle, $total, $received, $uploadTotal, $uploaded) => $received > 200 * 1024 * 1024 ? 1 : 0]);
        $success = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        fclose($output);
        if ($success && $status === 200) {$downloaded = true;break;}
        if ($status !== 404) {throw new RuntimeException('下载城市库失败，HTTP '.$status.'。现有数据库保持不变。');}
    }
    if (!$downloaded) {throw new RuntimeException('未找到最近三个月的 DB-IP 城市库。');}
    $input = gzopen($compressed, 'rb');$output = fopen($decoded, 'wb');$bytes = 0;
    try {
        while (!gzeof($input)) {
            $chunk = gzread($input, 65536);
            if ($chunk === false) {throw new RuntimeException('解压失败。');}
            $bytes += strlen($chunk);
            if ($bytes > 512 * 1024 * 1024 || fwrite($output, $chunk) !== strlen($chunk)) {throw new RuntimeException('城市库过大或磁盘空间不足。');}
        }
    } finally {gzclose($input);fclose($output);}
    $reader = new MaxMind\Db\Reader($decoded);
    try {
        if (stripos($reader->metadata()->databaseType, 'city') === false) {throw new RuntimeException('下载结果不是城市级数据库。');}
    } finally {$reader->close();}
    chmod($decoded, 0644);
    if (!rename($decoded, $directory.'/geoip-city.mmdb')) {throw new RuntimeException('无法替换城市库。');}
    echo "本地城市库更新完成：".$directory."/geoip-city.mmdb\n";
    echo "数据来源 https://db-ip.com/db/lite.php ，CC BY 4.0。建议每月更新。\n";
} finally {
    if (is_file($compressed)) {unlink($compressed);}
    if (is_file($decoded)) {unlink($decoded);}
    flock($lock, LOCK_UN);fclose($lock);
}
