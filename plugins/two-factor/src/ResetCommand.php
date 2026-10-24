<?php

namespace Blessing\TwoFactor;

final class ResetCommand extends \Illuminate\Console\Command
{
    protected $signature = 'two-factor:reset {uid : 用户 UID}';
    protected $description = '重置指定用户的两步验证设备与恢复码';

    public function handle(): int
    {
        $uid = filter_var($this->argument('uid'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$uid || !\App\Models\User::where('uid', $uid)->exists()) {
            $this->error('用户不存在。');

            return 1;
        }
        app(Accounts::class)->reset($uid);
        $this->info('两步验证设备与恢复码已重置。');

        return 0;
    }
}
