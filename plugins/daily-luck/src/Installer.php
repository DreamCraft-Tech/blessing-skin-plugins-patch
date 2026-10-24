<?php

declare(strict_types=1);

namespace Blessing\DailyLuck;

use Illuminate\Support\Facades\Schema;

final class Installer
{
    public const TABLE = 'daily_luck_users';

    public static function install(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function ($table): void {
                $table->engine = 'InnoDB';
                $table->unsignedInteger('user_id')->primary();
                $table->date('viewed_date');
            });
        }
    }
}
