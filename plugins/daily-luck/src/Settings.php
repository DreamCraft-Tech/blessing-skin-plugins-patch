<?php

declare(strict_types=1);

namespace Blessing\DailyLuck;

final class Settings
{
    public function texts(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $this->values()['texts'])), fn ($line) => $line !== ''));
    }

    public function values(): array
    {
        $saved = json_decode((string) option('daily_luck_settings', '{}'), true);

        return array_replace(['title' => '今日人品与千万别点', 'luck_enabled' => true, 'prank_enabled' => true, 'texts' => '', 'effect' => 'mixed', 'motion' => 'gentle', 'duration' => 30, 'position' => 'right', 'display' => 'reveal'], is_array($saved) ? $saved : []);
    }
}
