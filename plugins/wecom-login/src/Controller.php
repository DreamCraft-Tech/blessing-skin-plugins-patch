<?php

declare(strict_types=1);

namespace Blessing\WeCom;

use App\Events\UserLoggedIn;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

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
        return redirect(request()->user() ? '/user/wecom' : '/auth/login')
            ->with('wecom_error', $failure->getMessage());
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
        $binding = $this->bindings->forUser((int) $request->user()->uid);
        return view('Blessing\\WeCom::account', [
            'binding' => $binding,
            'notification_mode' => $binding ? NotificationPolicy::mode($binding) : 'off',
            'geo_ready' => app(IpLocation::class)->ready(),
            'ready' => $this->settings->ready(),
            'notifications_enabled' => $this->settings->notificationsEnabled(),
        ]);
    }

    public function notifications(Request $request)
    {
        $input = $request->only(['notification_mode', 'notify_login']);
        $validator = Validator::make($input, ['notification_mode' => 'required_without:notify_login|in:strict,relaxed,off', 'notify_login' => 'sometimes|boolean']);
        if ($validator->fails()) {return $this->failure(new Failure('通知设置格式不正确，请重新选择后保存。', 422));}
        $data = $validator->validated();
        if (!$this->bindings->forUser((int) $request->user()->uid)) {
            return $this->failure(new Failure('请先绑定企业微信。', 409));
        }
        $mode = $data['notification_mode'] ?? (!empty($data['notify_login']) ? 'strict' : 'off');
        $binding = $this->bindings->forUser((int) $request->user()->uid);
        $update = ['notification_mode' => $mode, 'notify_login' => $mode !== 'off'];
        if ($mode !== NotificationPolicy::mode($binding)) {$update['notification_baseline'] = null;}
        \Illuminate\Support\Facades\DB::table(Installer::BINDINGS)
            ->where('user_id', $request->user()->uid)->update($update);
        return redirect('/user/wecom')->with('wecom_notice', '登录通知偏好已保存。');
    }

    public function unbind(Request $request)
    {
        $validator = Validator::make($request->only('password'), ['password' => 'required|string|max:1024']);
        if ($validator->fails()) {return $this->failure(new Failure('请输入皮肤站密码，长度不能超过 1024 个字符。', 422));}
        $data = $validator->validated();
        try {
            $this->bindings->unbind($request->user(), $data['password']);
            $this->states->clear($request);
            $request->session()->regenerate();
            return redirect('/user/wecom')->with('wecom_notice', '企业微信绑定已解除。');
        } catch (Failure $failure) {
            return $this->failure($failure);
        }
    }
}
