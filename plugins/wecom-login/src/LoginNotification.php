<?php

declare(strict_types=1);

namespace Blessing\WeCom;

use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Log;

final class LoginNotification
{
    public function handle(Login $event): void
    {
        try {
            if ($event->guard !== 'web' || \Illuminate\Support\Facades\Auth::guard('web')->viaRemember() || !isset($event->user->uid)
                || !app(Settings::class)->notificationsEnabled()) {return;}
            $request = request();
            if ($request->is('api/yggdrasil/*')) {return;}
            $uid = (int) $event->user->uid;
            $key = 'wecom.login_notification.'.$uid;
            if ($request->attributes->get($key)) {return;}
            $binding = app(Bindings::class)->forUser($uid);
            if (!$binding || NotificationPolicy::mode($binding) === 'off') {return;}
            $request->attributes->set($key, true);
            $clean = static fn (string $text, int $limit): string => mb_substr(preg_replace('/[\x00-\x1f\x7f]/u', ' ', $text) ?? '', 0, $limit);
            $method = $request->is('auth/wecom/callback') ? '企业微信扫码' : '网页登录';
            $message = ['site_name' => $clean((string) option('site_name', '皮肤站'), 24), 'uid' => (string) $uid,
                'login_time' => now()->format('Y-m-d H:i:s P'), 'login_method' => $method,
                'ip' => $clean((string) $request->ip(), 45), 'user_agent' => $clean((string) $request->userAgent(), 96)];
            $done = false;
            // Best effort after the HTTP response; no queue worker is required.
            app()->terminating(function () use ($uid, $binding, $message, &$done, $request): void {
                if ($done) {return;}
                $done = true;
                $this->deliver($uid, (int) $binding->id, $message, (string) $request->ip());
            });
        } catch (\Throwable) {
            $this->warn();
        }
    }

    private function deliver(int $uid, int $bindingId, array $message, string $ip): void
    {
        try {
            $settings = app(Settings::class);
            if (!$settings->notificationsEnabled()) {return;}
            $user = User::find($uid);
            $binding = app(Bindings::class)->forUser($uid);
            if (!$user || $user->permission < User::NORMAL || !$binding
                || (int) $binding->id !== $bindingId || NotificationPolicy::mode($binding) === 'off') {return;}
            $values = $settings->requireValid();
            if (strcasecmp($binding->corp_id, $values['corp_id']) !== 0) {return;}
            $decision = app(NotificationPolicy::class)->decide($binding, $ip);
            $current = app(Bindings::class)->forUser($uid);
            if (!$current || (int) $current->id !== $bindingId || NotificationPolicy::mode($current) !== NotificationPolicy::mode($binding)) {return;}
            if ($decision['send']) {
                // Put the reason near the top so it remains visible within the card's size limit.
                $message['reason'] = $decision['reason'];
                $message = app(MessageFormat::class)->render($message);
                app(WeComClient::class)->sendLoginMessage($binding->member_id, $message, $values);
            }
            if ($decision['point'] !== null) {
                \Illuminate\Support\Facades\DB::table(Installer::BINDINGS)->where('id', $bindingId)
                    ->where('notification_mode', 'relaxed')->update(['notification_baseline' => json_encode($decision['point'], JSON_THROW_ON_ERROR)]);
            }
        } catch (\Throwable) {
            $this->warn();
        }
    }

    private function warn(): void
    {
        try {Log::warning('wecom-login: login_notification_failed');} catch (\Throwable) {}
    }
}
