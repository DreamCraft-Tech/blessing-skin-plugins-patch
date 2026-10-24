<?php

declare(strict_types=1);
namespace Blessing\LoginHistory;
use Illuminate\Support\Facades\Schema;

final class Installer
{
    public const ENTRIES = 'login_history_entries';
    public const PROFILES = 'login_history_profiles';
    public static function install(): void
    {
        if (!Schema::hasTable(self::ENTRIES)) {
            Schema::create(self::ENTRIES, function ($table): void {
                $table->engine = 'InnoDB';
                $table->bigIncrements('id');
                $table->unsignedInteger('user_id');
                $table->string('source', 16);
                $table->string('action', 24);
                $table->string('backend', 24);
                $table->string('ip', 45)->default('');
                $table->string('user_agent', 512)->default('');
                $table->dateTime('occurred_at')->index();
                $table->index(['user_id', 'occurred_at']);
            });
        }
        if (!Schema::hasTable(self::PROFILES)) {
            Schema::create(self::PROFILES, function ($table): void {
                $table->engine = 'InnoDB';
                $table->bigIncrements('id');
                $table->unsignedBigInteger('entry_id');
                $table->unsignedInteger('player_id')->nullable()->index();
                $table->string('profile_uuid', 36)->default('');
                $table->string('player_name', 255)->default('');
                $table->boolean('selected')->default(false);
                $table->foreign('entry_id')->references('id')->on(self::ENTRIES)->onDelete('cascade');
            });
        }
        foreach ([[self::ENTRIES, ['occurred_at']], [self::ENTRIES, ['user_id', 'occurred_at']], [self::PROFILES, ['player_id']]] as [$name, $columns]) {
            if (!Schema::hasIndex($name, $columns)) {
                Schema::table($name, fn ($table) => $table->index($columns));
            }
        }
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            $hasCascade = collect(Schema::getForeignKeys(self::PROFILES))->contains(
                fn ($key) => $key['columns'] === ['entry_id']
            );
            if (!$hasCascade) {
                Schema::table(self::PROFILES, fn ($table) => $table->foreign('entry_id')->references('id')->on(self::ENTRIES)->onDelete('cascade'));
            }
        }
    }
}
