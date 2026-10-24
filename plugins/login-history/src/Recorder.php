<?php

declare(strict_types=1);
namespace Blessing\LoginHistory;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

final class Recorder
{
    public const DAYS = 90;
    public static function warn(string $message): void
    {
        try {Log::warning($message);} catch (\Throwable) {}
    }
    public static function text(mixed $value, int $length): string
    {
        return is_string($value) ? mb_substr(preg_replace('/[\x00-\x1f\x7f]/u', '', $value) ?? '', 0, $length) : '';
    }
    public function record(int $uid, string $source, string $action, string $backend, Request $request, array $profiles = []): void
    {
        try {
            if (!User::where('uid', $uid)->exists()) {return;}
            $ip = $request->ip();
            DB::transaction(function () use ($uid, $source, $action, $backend, $request, $profiles, $ip): void {
                $id = DB::table(Installer::ENTRIES)->insertGetId([
                    'user_id' => $uid, 'source' => $source, 'action' => $action, 'backend' => $backend,
                    'ip' => filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '',
                    'user_agent' => self::text($request->userAgent(), 512), 'occurred_at' => now('UTC'),
                ]);
                foreach (array_slice($profiles, 0, 1000) as $profile) {
                    // Accept only an explicit allowlist; never persist requests or token bodies.
                    DB::table(Installer::PROFILES)->insert([
                        'entry_id' => $id, 'player_id' => $profile['player_id'] ?? null,
                        'profile_uuid' => self::text($profile['profile_uuid'] ?? '', 36),
                        'player_name' => self::text($profile['player_name'] ?? '', 255),
                        'selected' => (bool) ($profile['selected'] ?? false),
                    ]);
                }
            });
            $connection = DB::connection();
            $key = 'login-history.prune.'.hash('sha256', $connection->getDatabaseName().'|'.$connection->getTablePrefix());
            if (Cache::add($key, true, 3600)) {
                DB::table(Installer::ENTRIES)->where('occurred_at', '<', now('UTC')->subDays(self::DAYS))->delete();
            }
        } catch (\Throwable) {
            // History failures must not break authentication or leak credential-bearing traces.
            self::warn('Login history could not be recorded. Check plugin tables and database permissions.');
        }
    }
}
