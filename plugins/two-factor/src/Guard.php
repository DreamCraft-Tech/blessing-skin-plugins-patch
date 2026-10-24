<?php

namespace Blessing\TwoFactor;

use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Authenticatable;

final class Guard extends SessionGuard
{
    public function getName()
    {
        return 'login_'.$this->name.'_'.sha1(SessionGuard::class);
    }

    public function getRecallerName()
    {
        return 'remember_'.$this->name.'_'.sha1(SessionGuard::class);
    }

    public function login(Authenticatable $user, $remember = false)
    {
        if (!$this->request->attributes->get('two_factor.completed') && app(Accounts::class)->protected($user)) {
            $this->challenge($user, (bool) $remember);
        }
        parent::login($user, $remember);
        app(Accounts::class)->mark((int) $user->uid);
    }

    public function user()
    {
        $user = parent::user();
        if ($user && !$this->request->attributes->get('two_factor.completed') && app(Accounts::class)->protected($user)) {
            $verified = $this->session->get('two_factor.verified', []);
            $row = app(Accounts::class)->account((int) $user->uid);
            if (($verified['uid'] ?? null) !== (int) $user->uid || !hash_equals($row->revision, (string) ($verified['revision'] ?? '')) || !hash_equals(app(Settings::class)->revision(), (string) ($verified['policy'] ?? ''))) {
                $this->challenge($user, false);
            }
        }
        if ($user && !app(Accounts::class)->protected($user)) {
            $verified = $this->session->get('two_factor.verified', []);
            $account = \Illuminate\Support\Facades\DB::table('two_factor_accounts')->where('user_id', $user->uid)->first();
            if ($account && ($verified['uid'] ?? null) === (int) $user->uid && !hash_equals($account->revision, (string) ($verified['revision'] ?? ''))) {
                $this->user = null;
                $this->session->forget([$this->getName(), 'two_factor.verified', 'two_factor.recent']);
                throw new \Illuminate\Http\Exceptions\HttpResponseException(redirect('auth/login'));
            }
        }

        return $user;
    }

    protected function userFromRecaller($recaller)
    {
        $user = parent::userFromRecaller($recaller);
        if ($user && app(Accounts::class)->protected($user)) {
            $this->challenge($user, false);
        }

        return $user;
    }

    private function challenge($user, bool $remember): never
    {
        $this->user = null;
        $this->session->forget($this->getName());
        $this->getCookieJar()->queue($this->getCookieJar()->forget($this->getRecallerName()));
        app(Flow::class)->begin($user, $remember);
    }
}
