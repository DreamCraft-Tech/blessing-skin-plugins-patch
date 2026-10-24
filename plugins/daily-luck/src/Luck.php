<?php

declare(strict_types=1);

namespace Blessing\DailyLuck;

use Carbon\CarbonImmutable;

final class Luck
{
    public function today(int $uid, ?CarbonImmutable $at = null): array
    {
        $timezone = config('app.timezone', 'UTC');
        $date = ($at ?? CarbonImmutable::now())->setTimezone($timezone)->format('Y-m-d');
        $hash = hash_hmac('sha256', 'daily-luck:v1|'.$date.'|'.$uid, (string) config('app.key'));
        $score = (int) (hexdec(substr($hash, 0, 8)) % 101);

        $description = $this->describe($score);
        $texts = app(Settings::class)->texts();
        if ($texts) {
            $description['text'] = $texts[(int) (hexdec(substr($hash, 8, 8)) % count($texts))];
        }

        return ['date' => $date, 'timezone' => $timezone, 'score' => $score] + $description;
    }

    public function describe(int $score): array
    {
        return match (true) {
            $score === 100 => ['label' => '幸运满格', 'color' => 'success', 'text' => '今天的你，就是村庄里最闪亮的绿宝石。'],
            $score >= 80 => ['label' => '欧气满满', 'color' => 'success', 'text' => '带上镐子出发吧，今天也许能遇见属于你的钻石矿。'],
            $score >= 60 => ['label' => '顺风顺水', 'color' => 'info', 'text' => '适合约上伙伴，一起把心里的建筑变成现实。'],
            $score >= 40 => ['label' => '平稳发挥', 'color' => 'primary', 'text' => '种种田、修修家，平常的一天也能玩出新花样。'],
            $score >= 20 => ['label' => '慢慢积攒好运', 'color' => 'secondary', 'text' => '先整理背包，吃饱再出发。每一份准备都算数。'],
            $score > 0 => ['label' => '适合轻松一下', 'color' => 'secondary', 'text' => '在营火旁歇一歇，和朋友聊聊下一次冒险。'],
            default => ['label' => '触发隐藏彩蛋', 'color' => 'info', 'text' => '零也是难得一见的数字！今天就从放下第一块方块开始。'],
        };
    }
}
