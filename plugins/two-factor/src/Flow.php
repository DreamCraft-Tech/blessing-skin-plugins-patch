<?php

namespace Blessing\TwoFactor;

use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Auth;

final class Flow
{
    public function channel(): string
    {
        $marked = request()->attributes->get('login_history.web_channel');
        if (is_string($marked) && preg_match('/\A(?:password|wecom|oauth(?::[a-z0-9][a-z0-9_-]{0,63})?|custom:[a-z0-9][a-z0-9_-]{0,63})\z/D', $marked)) {
            return $marked;
        }
        $route = request()->route();
        $action = ltrim((string) $route?->getActionName(), '\\');
        if ($action === 'App\\Http\\Controllers\\AuthController@handleLogin') {
            return 'password';
        }
        if ($action === 'Blessing\\WeCom\\Controller@callback') {
            return 'wecom';
        }
        if ($action === 'Blessing\\OAuthCore\\AuthController@callback') {
            $driver = (string) $route->parameter('driver');

            return preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/D', $driver) ? 'oauth:'.strtolower($driver) : 'oauth';
        }

        return 'custom:two-factor';
    }

    public function begin(User $user, bool $remember): never
    {
        session()->forget(['two_factor.pending', 'two_factor.verified', 'two_factor.recent', 'two_factor.webauthn']);
        session()->regenerate();
        session(['two_factor.pending' => ['uid' => (int) $user->uid, 'remember' => $remember, 'at' => time(), 'attempts' => 0, 'channel' => $this->channel(), 'revision' => app(Accounts::class)->account((int) $user->uid)->revision, 'policy' => app(Settings::class)->revision()]]);
        $url = url('auth/two-factor');
        $response = request()->expectsJson() || request()->is('auth/login') ? response()->json(['code' => 0, 'message' => '请完成两步验证。', 'data' => ['redirectTo' => $url]]) : redirect($url);
        throw new HttpResponseException($response);
    }

    public function pending(): User
    {
        $p = session('two_factor.pending', []);
        $user = User::find($p['uid'] ?? 0);
        if (!$user || $user->permission < User::NORMAL || time() - ($p['at'] ?? 0) > 300 || ($p['attempts'] ?? 0) >= 5 || !app(Accounts::class)->protected($user) || !hash_equals(app(Settings::class)->revision(), (string) ($p['policy'] ?? '')) || !hash_equals(app(Accounts::class)->account((int) $user->uid)->revision, (string) ($p['revision'] ?? ''))) {
            session()->forget(['two_factor.pending', 'two_factor.webauthn']);
            throw new HttpResponseException(redirect('auth/login')->with('two_factor_error', '验证已过期，请重新登录。'));
        }

        return $user;
    }

    public function fail(): void
    {
        $p = session('two_factor.pending', []);
        if ($p) {
            $p['attempts'] = ($p['attempts'] ?? 0) + 1;
            session(['two_factor.pending' => $p]);
            if ($p['attempts'] >= 5) {
                session()->forget(['two_factor.pending', 'two_factor.webauthn']);
            }
        }
    }

    public function finish(User $user, string $channel, bool $remember = false): string
    {
        abort_if($user->permission < User::NORMAL, 403);
        request()->attributes->set('two_factor.completed', true);
        request()->attributes->set('login_history.web_channel', $channel);
        session()->forget(['two_factor.pending', 'two_factor.webauthn']);
        Auth::guard('web')->login($user, $remember);
        event('auth.login.succeeded', [$user]);
        event(new \App\Events\UserLoggedIn($user));
        $target = session()->pull('last_requested_path', '/user');

        return $this->target($target);
    }

    public function target(mixed $target): string
    {
        if (!is_string($target) || str_contains(rawurldecode($target), chr(92)) || preg_match('/[\\x00-\\x1f]/', rawurldecode($target))) {
            return '/user';
        }
        if (str_starts_with($target, '/') && !str_starts_with(rawurldecode($target), '//')) {
            return $target;
        }
        $parts = parse_url($target);
        $site = parse_url(url('/'));
        if ($parts && $site && !isset($parts['user']) && !isset($parts['pass'])
            && ($parts['scheme'] ?? '') === ($site['scheme'] ?? '') && ($parts['host'] ?? '') === ($site['host'] ?? '')
            && ($parts['port'] ?? null) === ($site['port'] ?? null)) {
            $path = $parts['path'] ?? '/user';
            if (str_starts_with($path, '/') && !str_starts_with(rawurldecode($path), '//')) {
                return $path.(isset($parts['query']) ? '?'.$parts['query'] : '');
            }
        }

        return '/user';
    }
}
