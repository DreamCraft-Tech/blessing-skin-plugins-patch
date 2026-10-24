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
            if (!$binding || !$binding->notify_login) {return;}
            $request->attributes->set($key, true);
            $clean = static fn (string $text, int $limit): string => mb_substr(preg_replace('/[\x00-\x1f\x7f]/u', ' ', $text) ?? '', 0, $limit);
            $method = $request->is('auth/wecom/callback') ? '企业微信扫码' : '网页登录';
            $message = $clean((string) option('site_name', '皮肤站'), 80)." 登录通知\n"
                .'账号：UID '.$uid."\n时间：".now()->format('Y-m-d H:i:s P')
                ."\n方式：".$method."\nIP：".$clean((string) $request->ip(), 45)
                ."\n设备：".$clean((string) $request->userAgent(), 160)
                ."\n若非本人操作，请及时修改皮肤站密码并检查账号。";
            $done = false;
            // Best effort after the HTTP response; no queue worker is required.
            app()->terminating(function () use ($uid, $binding, $message, &$done): void {
                if ($done) {return;}
                $done = true;
                $this->deliver($uid, (int) $binding->id, $message);
            });
        } catch (\Throwable) {
            $this->warn();
        }
    }

    private function deliver(int $uid, int $bindingId, string $message): void
    {
        try {
            $settings = app(Settings::class);
            if (!$settings->notificationsEnabled()) {return;}
            $user = User::find($uid);
            $binding = app(Bindings::class)->forUser($uid);
            if (!$user || $user->permission < User::NORMAL || !$binding
                || (int) $binding->id !== $bindingId || !$binding->notify_login) {return;}
            $values = $settings->requireValid();
            if (strcasecmp($binding->corp_id, $values['corp_id']) !== 0) {return;}
            app(WeComClient::class)->sendLoginMessage($binding->member_id, $message, $values);
        } catch (\Throwable) {
            $this->warn();
        }
    }

    private function warn(): void
    {
        try {Log::warning('wecom-login: login_notification_failed');} catch (\Throwable) {}
    }
}
