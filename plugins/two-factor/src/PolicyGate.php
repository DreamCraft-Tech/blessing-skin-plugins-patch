<?php

namespace Blessing\TwoFactor;

final class PolicyGate
{
    public function handle($request, \Closure $next)
    {
        $response = null;
        if (!$request->is('auth/two-factor*') && ($user = auth('web')->user()) && app(Settings::class)->required($user) && !app(Accounts::class)->hasFactors((int) $user->uid)
            && !$request->is('user/two-factor*', 'auth/bind*', 'auth/logout', 'app/*', 'plugins/*')) {
            $response = $request->expectsJson() ? response()->json(['message' => '请先设置两步验证。', 'redirectTo' => url('user/two-factor')], 403) : redirect('user/two-factor')->with('two_factor_notice', '请添加一种验证设备，完成账号保护设置。');
        }
        $response ??= $next($request);
        if ($request->is('user/two-factor*', 'auth/two-factor*')) {
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('Referrer-Policy', 'same-origin');
            $response->headers->set('X-Frame-Options', 'DENY');
        }

        return $response;
    }
}
