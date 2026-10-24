<?php

declare(strict_types=1);

namespace Blessing\DailyLuck;

final class Settings
{
    public function values(): array
    {
        $saved = json_decode((string) option('daily_luck_settings', '{}'), true);

        return array_replace(['title' => '每日人品', 'position' => 'right', 'display' => 'reveal'], is_array($saved) ? $saved : []);
    }
}
