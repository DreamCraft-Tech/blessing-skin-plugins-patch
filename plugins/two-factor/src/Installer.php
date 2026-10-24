<?php

namespace Blessing\TwoFactor;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

final class Installer
{
    public static function install(): void
    {
        if (!Schema::hasTable('two_factor_accounts')) {
            Schema::create('two_factor_accounts', function (Blueprint $t) {
                $t->engine = 'InnoDB';
                $t->unsignedInteger('user_id')->primary();
                $t->string('handle', 64)->unique();
                $t->boolean('enabled')->default(false);
                $t->string('revision', 32);
                $t->text('recovery_codes')->nullable();
                $t->timestamps();
            });
        }
        if (!Schema::hasTable('two_factor_totp')) {
            Schema::create('two_factor_totp', function (Blueprint $t) {
                $t->engine = 'InnoDB';
                $t->bigIncrements('id');
                $t->unsignedInteger('user_id')->index();
                $t->string('name', 80);
                $t->text('secret');
                $t->bigInteger('last_step')->default(-1);
                $t->timestamp('last_used_at')->nullable();
                $t->timestamps();
            });
        }
        if (!Schema::hasTable('two_factor_passkeys')) {
            Schema::create('two_factor_passkeys', function (Blueprint $t) {
                $t->engine = 'InnoDB';
                $t->bigIncrements('id');
                $t->unsignedInteger('user_id')->index();
                $t->string('name', 80);
                $t->char('credential_hash', 64)->unique();
                $t->text('credential_id');
                $t->text('public_key');
                $t->unsignedBigInteger('sign_count')->default(0);
                $t->string('rp_id', 253);
                $t->text('transports');
                $t->boolean('backup_eligible')->default(false);
                $t->timestamp('last_used_at')->nullable();
                $t->timestamps();
            });
        }
    }
}
