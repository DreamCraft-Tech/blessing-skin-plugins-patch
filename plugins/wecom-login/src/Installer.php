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
                $table->foreign('user_id')->references('uid')->on('users')->onDelete('cascade');
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
                $table->foreign('user_id')->references('uid')->on('users')->onDelete('cascade');
            });
        }
    }
}
