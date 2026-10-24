<?php

namespace Blessing\TwoFactor;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class Accounts
{
    public function account(int $uid): object
    {
        $row = DB::table('two_factor_accounts')->where('user_id', $uid)->first();
        if (!$row) {
            DB::table('two_factor_accounts')->insertOrIgnore(['user_id' => $uid, 'handle' => bin2hex(random_bytes(32)), 'revision' => bin2hex(random_bytes(16)), 'created_at' => now(), 'updated_at' => now()]);
            $row = DB::table('two_factor_accounts')->where('user_id', $uid)->first();
        }

        return $row;
    }

    public function hasFactors(int $uid): bool
    {
        return DB::table('two_factor_totp')->where('user_id', $uid)->exists() || DB::table('two_factor_passkeys')->where('user_id', $uid)->exists();
    }

    public function protected(User $user): bool
    {
        $row = DB::table('two_factor_accounts')->where('user_id', $user->uid)->first();

        return $row && ($row->enabled || app(Settings::class)->required($user)) && $this->hasFactors((int) $user->uid);
    }

    public function proof(int $uid, string $code): bool
    {
        return DB::transaction(function () use ($uid, $code) {
            $account = DB::table('two_factor_accounts')->where('user_id', $uid)->lockForUpdate()->first();
            if (!$account) {
                return false;
            }
            foreach (DB::table('two_factor_totp')->where('user_id', $uid)->lockForUpdate()->get() as $device) {
                $step = app(Totp::class)->match(Crypt::decryptString($device->secret), $code, (int) $device->last_step);
                if ($step !== null) {
                    DB::table('two_factor_totp')->where('id', $device->id)->update(['last_step' => $step, 'last_used_at' => now(), 'updated_at' => now()]);

                    return true;
                }
            }
            $hash = hash('sha256', strtolower(str_replace('-', '', trim($code))));
            $codes = json_decode($account->recovery_codes ?? '[]', true);
            foreach ($codes as $i => $stored) {
                if (hash_equals($stored, $hash)) {
                    unset($codes[$i]);
                    DB::table('two_factor_accounts')->where('user_id', $uid)->update(['recovery_codes' => json_encode(array_values($codes)), 'updated_at' => now()]);

                    return true;
                }
            }

            return false;
        });
    }

    public function recovery(int $uid): array
    {
        $this->account($uid);
        $plain = [];
        for ($i = 0; $i < 10; $i++) {
            $plain[] = implode('-', str_split(bin2hex(random_bytes(12)), 6));
        }
        DB::table('two_factor_accounts')->where('user_id', $uid)->update(['recovery_codes' => json_encode(array_map(fn ($s) => hash('sha256', str_replace('-', '', $s)), $plain)), 'updated_at' => now()]);

        return $plain;
    }

    public function changed(int $uid, ?bool $enabled = null): void
    {
        $this->account($uid);
        $data = ['revision' => bin2hex(random_bytes(16)), 'updated_at' => now()];
        if ($enabled !== null) {
            $data['enabled'] = $enabled;
        }
        DB::table('two_factor_accounts')->where('user_id', $uid)->update($data);
        $this->mark($uid);
        User::where('uid', $uid)->update(['remember_token' => null]);
    }

    public function mark(int $uid): void
    {
        session(['two_factor.verified' => ['uid' => $uid, 'revision' => $this->account($uid)->revision, 'policy' => app(Settings::class)->revision()], 'two_factor.recent' => ['uid' => $uid, 'at' => time()]]);
    }

    public function recent(int $uid): bool
    {
        $r = session('two_factor.recent', []);

        return ($r['uid'] ?? null) === $uid && time() - ($r['at'] ?? 0) < (int) app(Settings::class)->values()['reauth_seconds'];
    }

    public function reset(int $uid): void
    {
        DB::transaction(function () use ($uid) {
            foreach (['two_factor_totp', 'two_factor_passkeys'] as $table) {
                DB::table($table)->where('user_id', $uid)->delete();
            }DB::table('two_factor_accounts')->where('user_id', $uid)->update(['enabled' => false, 'recovery_codes' => null, 'revision' => bin2hex(random_bytes(16)), 'updated_at' => now()]);
            User::where('uid', $uid)->update(['remember_token' => null]);
        });
    }
}
