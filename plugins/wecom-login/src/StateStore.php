<?php

declare(strict_types=1);

namespace Blessing\WeCom;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class StateStore
{
    private function sessionHash(Request $request, bool $create): string
    {
        $key = $request->session()->get('wecom.session_key');
        if ((!is_string($key) || strlen($key) !== 64) && $create) {
            $key = bin2hex(random_bytes(32));
            $request->session()->put('wecom.session_key', $key);
        }
        if (!is_string($key) || strlen($key) !== 64) {
            throw new Failure('授权会话已失效，请重新发起扫码。');
        }
        return hash('sha256', $key);
    }

    public function issue(Request $request, string $flow, string $context): string
    {
        $state = bin2hex(random_bytes(32));
        $session = $this->sessionHash($request, true);
        DB::table(Installer::STATES)->where('expires_at', '<=', time())->delete();
        // A new attempt invalidates previous attempts in the same browser session.
        DB::table(Installer::STATES)->where('session_hash', $session)->delete();
        DB::table(Installer::STATES)->insert([
            'state_hash' => hash('sha256', $state), 'session_hash' => $session,
            'context_hash' => $context, 'flow' => $flow,
            'user_id' => $flow === 'bind' ? $request->user()?->uid : null,
            'expires_at' => time() + 300,
        ]);
        return $state;
    }

    public function consume(Request $request, string $context): object
    {
        $state = $request->query('state');
        if (!is_string($state) || !preg_match('/\A[0-9a-f]{64}\z/D', $state)) {
            throw new Failure('授权状态无效，请重新发起扫码。');
        }
        $session = $this->sessionHash($request, false);
        // Row lock + deletion make state consumption atomic across workers.
        $row = DB::transaction(function () use ($state, $session) {
            $row = DB::table(Installer::STATES)->where('state_hash', hash('sha256', $state))
                ->lockForUpdate()->first();
            if (!$row || !hash_equals($row->session_hash, $session)) {
                throw new Failure('授权状态无效，请从原浏览器重新发起扫码。');
            }
            DB::table(Installer::STATES)->where('state_hash', $row->state_hash)->delete();
            return $row;
        });
        if ((int) $row->expires_at <= time() || !hash_equals($row->context_hash, $context)) {
            throw new Failure('授权已过期或应用配置已变化，请重新扫码。');
        }
        if ($row->flow === 'bind') {
            if (!$request->user() || (int) $row->user_id !== (int) $request->user()->uid) {
                throw new Failure('绑定期间登录账号已变化，请重新发起绑定。', 403);
            }
        } elseif ($row->flow !== 'login' || $request->user()) {
            throw new Failure('登录状态已变化，请重新发起操作。', 409);
        }
        return $row;
    }

    public function clear(Request $request): void
    {
        $key = $request->session()->get('wecom.session_key');
        if (is_string($key)) {
            DB::table(Installer::STATES)->where('session_hash', hash('sha256', $key))->delete();
        }
        $request->session()->forget('wecom.session_key');
    }
}
