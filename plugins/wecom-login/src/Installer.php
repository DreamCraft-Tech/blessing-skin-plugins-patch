<?php

declare(strict_types=1);

namespace Blessing\WeCom;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

final class Installer
{
    public const BINDINGS = 'wecom_bindings';
    public const STATES = 'wecom_oauth_states';

    public static function install(): void
    {
        if (!Schema::hasTable(self::BINDINGS)) {
            Schema::create(self::BINDINGS, function (Blueprint $table): void {
                $table->engine = 'InnoDB';
                $table->bigIncrements('id');
                $table->unsignedInteger('user_id')->unique();
                $table->string('corp_id', 128);
                $table->string('member_id', 128);
                $table->string('member_name', 255)->default('');
                $table->char('identity_hash', 64)->unique();
                $table->timestamp('created_at');
            });
        }
        if (!Schema::hasTable(self::STATES)) {
            Schema::create(self::STATES, function (Blueprint $table): void {
                $table->engine = 'InnoDB';
                $table->char('state_hash', 64)->primary();
                $table->char('session_hash', 64)->index();
                $table->char('context_hash', 64);
                $table->string('flow', 8);
                $table->unsignedInteger('user_id')->nullable();
                $table->unsignedBigInteger('expires_at')->index();
            });
        }
        if (!Schema::hasColumn(self::BINDINGS, 'notify_login')) {
            Schema::table(self::BINDINGS, fn (Blueprint $table) => $table->boolean('notify_login')->default(false));
        }
        // MySQL DDL is not transactional: an earlier failed FK may have left tables without indexes.
        foreach ([
            [self::BINDINGS, ['user_id'], true],
            [self::BINDINGS, ['identity_hash'], true],
            [self::STATES, ['state_hash'], true],
            [self::STATES, ['session_hash'], false],
            [self::STATES, ['expires_at'], false],
        ] as [$name, $columns, $unique]) {
            if (!Schema::hasIndex($name, $columns, $unique ? 'unique' : null)) {
                Schema::table($name, function (Blueprint $table) use ($columns, $unique): void {
                    $unique ? $table->unique($columns) : $table->index($columns);
                });
            }
        }
    }
}
