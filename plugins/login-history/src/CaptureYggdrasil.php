<?php

declare(strict_types=1);
namespace Blessing\LoginHistory;

use App\Models\Player;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;

final class CaptureYggdrasil
{
    private function legacyOwner(Request $request, string $operation): ?int
    {
        if ($operation === 'authenticate') {
            $name = $request->input('username');
            if (!is_string($name)) {return null;}
            if (filter_var($name, FILTER_VALIDATE_EMAIL)) {return User::where('email', $name)->value('uid');}
            return Player::where('name', $name)->value('uid');
        }
        $token = $request->input('accessToken');
        if (!is_string($token) || strlen($token) > 8192) {return null;}
        // Resolve before refresh revokes its old token. Never retain the token in the record.
        $cached = Cache::get('yggdrasil-token-'.$token);
        return is_object($cached) && is_string($cached->owner ?? null)
            ? User::where('email', $cached->owner)->value('uid') : null;
    }

    private function profile(int $uid, array $value, bool $selected): array
    {
        $name = is_string($value['name'] ?? null) ? $value['name'] : '';
        $player = $name !== '' ? Player::where('uid', $uid)->where('name', $name)->first() : null;
        return ['player_id' => $player?->pid, 'player_name' => $name,
            'profile_uuid' => is_string($value['id'] ?? null) ? $value['id'] : '', 'selected' => $selected];
    }

    private function joinProfile(int $uid, mixed $uuid): array
    {
        if (!is_string($uuid) || strlen($uuid) > 36 || !Schema::hasTable('uuid')) {return [];}
        $mapping = DB::table('uuid')->where('uuid', $uuid)->first();
        if (!$mapping) {return [];}
        $player = isset($mapping->pid) ? Player::where('pid', $mapping->pid)->where('uid', $uid)->first()
            : Player::where('name', $mapping->name ?? '')->where('uid', $uid)->first();
        return $player ? [['player_id' => $player->pid, 'player_name' => $player->name,
            'profile_uuid' => $uuid, 'selected' => true]] : [];
    }

    public function handle(Request $request, Closure $next)
    {
        $backend = $request->attributes->get('login_history.backend');
        $operation = $request->attributes->get('login_history.operation');
        $uid = null;
        try {
            $uid = $backend === 'yggdrasil-connect' ? $request->user()?->uid : $this->legacyOwner($request, $operation);
        } catch (\Throwable) {
            Recorder::warn('Login history could not resolve the Yggdrasil account.');
        }
        $response = $next($request);
        if (!$uid || !in_array($operation, ['authenticate', 'refresh', 'join'], true)) {return $response;}
        if (($operation === 'join' && $response->getStatusCode() !== 204)
            || ($operation !== 'join' && $response->getStatusCode() !== 200)) {return $response;}
        try {
            $profiles = [];
            if ($operation === 'join') {
                $profiles = $this->joinProfile((int) $uid, $request->input('selectedProfile'));
                if (!$profiles) {return $response;}
            } else {
                $body = json_decode($response->getContent(), true);
                // Check the genuine protocol success shape, never store returned credentials.
                if (!is_array($body) || !is_string($body['accessToken'] ?? null) || $body['accessToken'] === '') {return $response;}
                $selected = is_array($body['selectedProfile'] ?? null) ? $body['selectedProfile'] : null;
                $available = is_array($body['availableProfiles'] ?? null) ? $body['availableProfiles'] : [];
                $seen = [];
                if ($selected) {
                    $profiles[] = $this->profile((int) $uid, $selected, true);
                    $seen[(string) ($selected['id'] ?? '')] = true;
                }
                foreach ($available as $candidate) {
                    if (is_array($candidate) && !isset($seen[(string) ($candidate['id'] ?? '')])) {
                        $profiles[] = $this->profile((int) $uid, $candidate, false);
                        $seen[(string) ($candidate['id'] ?? '')] = true;
                    }
                }
            }
            app(Recorder::class)->record((int) $uid, 'yggdrasil', $operation, $backend, $request, $profiles);
        } catch (\Throwable) {
            Recorder::warn('Login history could not capture a Yggdrasil response.');
        }
        return $response;
    }
}
