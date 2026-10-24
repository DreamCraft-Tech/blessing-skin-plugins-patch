<?php

declare(strict_types=1);
namespace Blessing\WeCom;

final class NotificationPolicy
{
    public static function mode(object $binding): string
    {
        $mode = $binding->notification_mode ?? null;
        if ($mode === null) {return !empty($binding->notify_login) ? 'strict' : 'off';}
        return in_array($mode, ['strict', 'relaxed', 'off'], true) ? $mode : 'off';
    }

    public function decide(object $binding, string $ip): array
    {
        $mode = self::mode($binding);
        if ($mode === 'off') {return ['send' => false, 'point' => null, 'reason' => ''];}
        if ($mode === 'strict') {return ['send' => true, 'point' => null, 'reason' => '严格模式'];}
        $point = app(IpLocation::class)->verified($ip);
        if (!$point) {return ['send' => true, 'point' => null, 'reason' => '定位缺失或校验不一致，提醒确认'];}
        $baseline = json_decode((string) ($binding->notification_baseline ?? ''), true);
        if (!is_array($baseline) || !isset($baseline['country'], $baseline['latitude'], $baseline['longitude'])
            || !is_string($baseline['country']) || !is_numeric($baseline['latitude']) || !is_numeric($baseline['longitude'])
            || abs((float) $baseline['latitude']) > 90 || abs((float) $baseline['longitude']) > 180) {
            return ['send' => true, 'point' => $point, 'reason' => '首次定位，建立登录地基准'];
        }
        if ($point['country'] === 'CN' && $baseline['country'] === 'CN') {
            if (empty($point['city']) || empty($baseline['city'])) {return ['send' => true, 'point' => $point, 'reason' => '城市信息缺失，提醒确认'];}
            $changed = $point['city'] !== $baseline['city'];
            return ['send' => $changed, 'point' => $point, 'reason' => $changed ? '中国境内登录城市发生变化，请确认本人操作' : '登录城市相同'];
        }
        $distance = IpLocation::distance($baseline, $point);
        $changed = $baseline['country'] !== $point['country'] || $distance >= 300;
        return ['send' => $changed, 'point' => $point, 'reason' => $changed ? '登录地变化较大，请确认本人操作' : '登录地变化较小'];
    }
}
