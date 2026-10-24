<?php

declare(strict_types=1);

namespace Blessing\WeCom;

use App\Events\UserLoggedIn;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class Controller extends \Illuminate\Routing\Controller
{
    public function __construct(
        private Settings $settings,
        private StateStore $states,
        private WeComClient $client,
        private Bindings $bindings,
    ) {
    }

    private function failure(Failure $failure)
    {
        return response()->view('WeCom::message', ['message' => $failure->getMessage()], $failure->status);
    }

    public function login(Request $request)
    {
        if ($request->user()) {
            return redirect('/user/wecom');
        }
        return $this->start($request, 'login');
    }

    public function bind(Request $request)
    {
        if ($this->bindings->forUser((int) $request->user()->uid)) {
            return $this->failure(new Failure('当前账号已绑定企业微信，请先解绑再更换。', 409));
        }
        return $this->start($request, 'bind');
    }

    private function start(Request $request, string $flow)
    {
        try {
            $settings = $this->settings->requireValid();
            $state = $this->states->issue($request, $flow, $this->settings->fingerprint($settings));
            return redirect()->away($this->client->authorizationUrl($settings, $state));
        } catch (Failure $failure) {
            return $this->failure($failure);
        }
    }

    public function callback(Request $request)
    {
        try {
            $settings = $this->settings->requireValid();
            $state = $this->states->consume($request, $this->settings->fingerprint($settings));
            $code = $request->query('code');
            if (!is_string($code) || $code === '' || strlen($code) > 512) {
                throw new Failure('扫码已取消或授权码无效，请重新发起扫码。');
            }
            $member = $this->client->member($code, $settings);
            if ($state->flow === 'bind') {
                $this->bindings->bind($request->user(), $member);
                $this->states->clear($request);
                $request->session()->regenerate();
                return redirect('/user/wecom')->with('wecom_notice', '企业微信绑定成功，下次可使用企业微信扫码登录。');
            }
            $request->session()->put('last_requested_path', '/user/wecom');
            $user = $this->bindings->loginUser($member);
            event('auth.login.ready', [$user]);
            Auth::login($user, false);
            $this->states->clear($request);
            $request->session()->regenerate();
            event('auth.login.succeeded', [$user]);
            event(new UserLoggedIn($user));
            return redirect('/user');
        } catch (Failure $failure) {
            return $this->failure($failure);
        }
    }

    public function account(Request $request)
    {
        return view('WeCom::account', [
            'binding' => $this->bindings->forUser((int) $request->user()->uid),
            'ready' => $this->settings->ready(),
        ]);
    }

    public function unbind(Request $request)
    {
        $data = $request->validate(['password' => 'required|string|max:1024']);
        try {
            $this->bindings->unbind($request->user(), $data['password']);
            $this->states->clear($request);
            $request->session()->regenerate();
            return redirect('/user/wecom')->with('wecom_notice', '已解除企业微信绑定，当前皮肤站账号仍可正常使用。');
        } catch (Failure $failure) {
            return $this->failure($failure);
        }
    }
}
