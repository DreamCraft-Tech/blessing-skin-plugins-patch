<?php

namespace Blessing\TwoFactor;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class Controller
{
    private function form(Request $r, array $rules): array
    {
        $v = Validator::make($r->all(), $rules);
        if ($v->fails()) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(redirect('user/two-factor')->withErrors($v));
        }

        return $v->validated();
    }

    private function recent(Request $r): void
    {
        if (!app(Accounts::class)->recent((int) $r->user()->uid)) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException($r->expectsJson() ? response()->json(['message' => '请先完成身份确认。'], 403) : redirect('user/two-factor')->with('two_factor_error', '请先完成身份确认。'));
        }
    }

    private function success(string $text)
    {
        return redirect('user/two-factor')->with('two_factor_notice', $text);
    }

    public function account(Request $r)
    {
        $uid = (int) $r->user()->uid;
        $accounts = app(Accounts::class);
        $row = $accounts->account($uid);
        $qr = null;
        $setup = session('two_factor.setup');
        if ($setup && ($setup['uid'] !== $uid || time() - $setup['at'] > 600)) {
            session()->forget('two_factor.setup');
            $setup = null;
        }
        if ($setup) {
            $uri = 'otpauth://totp/'.rawurlencode(app(Settings::class)->values()['rp_name'].':'.$r->user()->email).'?secret='.$setup['secret'].'&issuer='.rawurlencode(app(Settings::class)->values()['rp_name']).'&algorithm=SHA1&digits=6&period=30';
            $qr = (new Writer(new ImageRenderer(new RendererStyle(220), new SvgImageBackEnd())))->writeString($uri);
            $qr = substr($qr, strpos($qr, '<svg'));
        }

        return view('Blessing\\TwoFactor::account', ['account' => $row, 'settings' => app(Settings::class)->values(), 'required' => app(Settings::class)->required($r->user()),
            'recent' => $accounts->recent($uid), 'has_factors' => $accounts->hasFactors($uid), 'totp_devices' => DB::table('two_factor_totp')->where('user_id', $uid)->get(),
            'passkey_devices' => DB::table('two_factor_passkeys')->where('user_id', $uid)->get(), 'setup' => $setup, 'qr' => $qr, 'recovery_codes' => session()->pull('two_factor.recovery_plain', [])]);
    }

    public function confirm(Request $r)
    {
        $data = $this->form($r, ['current_password' => 'required|string|max:255']);
        $uid = (int) $r->user()->uid;
        $valid = app(Accounts::class)->protected($r->user()) ? app(Accounts::class)->proof($uid, $data['current_password']) : $r->user()->verifyPassword($data['current_password']);
        if (!$valid) {
            return redirect('user/two-factor')->with('two_factor_error', '身份确认未通过，请检查后重试。');
        }
        session(['two_factor.recent' => ['uid' => $uid, 'at' => time()]]);

        return $this->success('身份已确认，可以管理验证设备。');
    }

    public function startTotp(Request $r)
    {
        $this->recent($r);
        $settings = app(Settings::class)->values();
        abort_unless($settings['allow_totp'], 403);
        $data = $this->form($r, ['name' => 'required|string|max:80']);
        if (DB::table('two_factor_totp')->where('user_id', $r->user()->uid)->count() >= $settings['max_totp']) {
            return redirect('user/two-factor')->with('two_factor_error', 'TOTP 设备数量已达到上限。');
        }
        session(['two_factor.setup' => ['uid' => (int) $r->user()->uid, 'name' => $data['name'], 'secret' => app(Totp::class)->secret(), 'at' => time()]]);

        return $this->success('请扫描二维码，并输入验证器当前显示的验证码。');
    }

    private function added(int $uid): void
    {
        $accounts = app(Accounts::class);
        $row = $accounts->account($uid);
        if (!$row->recovery_codes) {
            session(['two_factor.recovery_plain' => $accounts->recovery($uid)]);
        }
        $accounts->changed($uid, true);
    }

    public function confirmTotp(Request $r)
    {
        $this->recent($r);
        abort_unless(app(Settings::class)->values()['allow_totp'], 403);
        $data = $this->form($r, ['current_password' => 'required|string|max:24']);
        $setup = session('two_factor.setup');
        $uid = (int) $r->user()->uid;
        $step = $setup && $setup['uid'] === $uid && time() - $setup['at'] <= 600 ? app(Totp::class)->match($setup['secret'], $data['current_password']) : null;
        if ($step === null) {
            return redirect('user/two-factor')->with('two_factor_error', '验证码不正确或设置已过期，请重试。');
        }
        DB::transaction(function () use ($uid, $setup, $step) {
            DB::table('two_factor_accounts')->where('user_id', $uid)->lockForUpdate()->first();
            abort_if(DB::table('two_factor_totp')->where('user_id', $uid)->count() >= app(Settings::class)->values()['max_totp'], 422, 'TOTP 设备数量已达到上限。');
            DB::table('two_factor_totp')->insert(['user_id' => $uid, 'name' => $setup['name'], 'secret' => Crypt::encryptString($setup['secret']), 'last_step' => $step, 'created_at' => now(), 'updated_at' => now()]);
            $this->added($uid);
        });
        session()->forget('two_factor.setup');

        return $this->success('TOTP 验证设备已添加，两步验证已开启。');
    }

    public function createOptions(Request $r)
    {
        $this->recent($r);
        abort_unless(app(Settings::class)->values()['allow_passkeys'], 403);

        return $this->jsonTry(fn () => app(Passkeys::class)->createOptions($r->user()));
    }

    public function registerKey(Request $r)
    {
        $this->recent($r);
        abort_unless(app(Settings::class)->values()['allow_passkeys'], 403);
        $data = $r->validate(['id' => 'required|string|max:2048', 'name' => 'required|string|max:80', 'clientDataJSON' => 'required|string|max:12000', 'attestationObject' => 'required|string|max:140000',
            'transports' => 'sometimes|array|max:8', 'transports.*' => 'in:usb,nfc,ble,internal,hybrid,smart-card']);

        return $this->jsonTry(function () use ($r, $data) {
            app(Passkeys::class)->register($r->user(), $data);
            $this->added((int) $r->user()->uid);

            return ['redirectTo' => url('user/two-factor')];
        });
    }

    public function remove(Request $r)
    {
        $this->recent($r);
        $data = $this->form($r, ['kind' => 'required|in:totp,passkey', 'id' => 'required|integer|min:1']);
        $uid = (int) $r->user()->uid;

        return DB::transaction(function () use ($r, $data, $uid) {
            DB::table('two_factor_accounts')->where('user_id', $uid)->lockForUpdate()->first();
            $table = $data['kind'] === 'totp' ? 'two_factor_totp' : 'two_factor_passkeys';
            $device = DB::table($table)->where('user_id', $uid)->where('id', $data['id'])->first();
            abort_unless($device, 404);
            $count = DB::table('two_factor_totp')->where('user_id', $uid)->count() + DB::table('two_factor_passkeys')->where('user_id', $uid)->count();
            if ($count === 1 && app(Settings::class)->required($r->user())) {
                return redirect('user/two-factor')->with('two_factor_error', '请先添加另一种验证设备，再移除此设备。');
            }
            DB::table($table)->where('id', $device->id)->delete();
            app(Accounts::class)->changed($uid, $count > 1 ? (bool) app(Accounts::class)->account($uid)->enabled : false);
            if ($count === 1) {
                DB::table('two_factor_accounts')->where('user_id', $uid)->update(['recovery_codes' => null]);
            }

            return $this->success('验证设备已移除。');
        });
    }

    public function rename(Request $r)
    {
        $this->recent($r);
        $data = $this->form($r, ['kind' => 'required|in:totp,passkey', 'id' => 'required|integer|min:1', 'name' => 'required|string|max:80']);
        $table = $data['kind'] === 'totp' ? 'two_factor_totp' : 'two_factor_passkeys';
        abort_unless(DB::table($table)->where('user_id', $r->user()->uid)->where('id', $data['id'])->exists(), 404);
        DB::table($table)->where('user_id', $r->user()->uid)->where('id', $data['id'])->update(['name' => $data['name'], 'updated_at' => now()]);

        return $this->success('设备名称已保存。');
    }

    public function recovery(Request $r)
    {
        $this->recent($r);
        abort_unless(app(Accounts::class)->hasFactors((int) $r->user()->uid), 422);
        session(['two_factor.recovery_plain' => app(Accounts::class)->recovery((int) $r->user()->uid)]);

        return $this->success('恢复码已重新生成，请保存本次显示的恢复码。');
    }

    public function toggle(Request $r)
    {
        $this->recent($r);
        $uid = (int) $r->user()->uid;
        $data = $this->form($r, ['enabled' => 'required|boolean']);
        if (!$data['enabled'] && app(Settings::class)->required($r->user())) {
            return redirect('user/two-factor')->with('two_factor_error', '站点要求此账号开启两步验证。');
        }
        abort_if($data['enabled'] && !app(Accounts::class)->hasFactors($uid), 422, '请先添加验证设备。');
        app(Accounts::class)->changed($uid, (bool) $data['enabled']);

        return $this->success($data['enabled'] ? '两步验证已开启。' : '两步验证已关闭。');
    }

    public function challenge()
    {
        $user = app(Flow::class)->pending();

        return view('Blessing\\TwoFactor::challenge', ['has_totp' => DB::table('two_factor_totp')->where('user_id', $user->uid)->exists(), 'has_passkey' => DB::table('two_factor_passkeys')->where('user_id', $user->uid)->exists()]);
    }

    public function code(Request $r)
    {
        $flow = app(Flow::class);
        $user = $flow->pending();
        $code = $r->input('current_password');
        if (!is_string($code) || strlen($code) > 64 || !app(Accounts::class)->proof((int) $user->uid, $code)) {
            $flow->fail();

            return redirect('auth/two-factor')->with('two_factor_error', '验证码或恢复码不正确，请重试。');
        }
        $p = session('two_factor.pending');

        return redirect($flow->finish($user, $p['channel'], $p['remember']));
    }

    public function cancel()
    {
        session()->forget(['two_factor.pending', 'two_factor.webauthn']);

        return redirect('auth/login');
    }

    public function assertionOptions(Request $r)
    {
        $purpose = $r->validate(['purpose' => 'required|in:login,challenge,manage'])['purpose'];
        $s = app(Settings::class)->values();
        if ($purpose === 'login') {
            abort_unless($s['direct_login'], 403);
            $user = null;
        } elseif ($purpose === 'challenge') {
            $user = app(Flow::class)->pending();
        } else {
            $user = auth('web')->user();
            abort_unless($user, 401);
        }

        return $this->jsonTry(fn () => app(Passkeys::class)->getOptions($purpose, $user));
    }

    public function assertionVerify(Request $r)
    {
        $data = $r->validate(['purpose' => 'required|in:login,challenge,manage', 'id' => 'required|string|max:2048', 'clientDataJSON' => 'required|string|max:12000', 'authenticatorData' => 'required|string|max:12000', 'signature' => 'required|string|max:12000', 'userHandle' => 'nullable|string|max:200']);
        $purpose = $data['purpose'];
        $s = app(Settings::class)->values();
        if ($purpose === 'login') {
            abort_unless($s['direct_login'], 403);
            $user = null;
        } elseif ($purpose === 'challenge') {
            $user = app(Flow::class)->pending();
        } else {
            $user = auth('web')->user();
            abort_unless($user, 401);
        }

        return $this->jsonTry(function () use ($purpose, $user, $data) {
            $owner = app(Passkeys::class)->verify($purpose, $user, $data);
            if ($purpose === 'manage') {
                session(['two_factor.recent' => ['uid' => (int) $owner->uid, 'at' => time()]]);

                return ['redirectTo' => url('user/two-factor')];
            }
            $pending = session('two_factor.pending', []);
            $channel = $purpose === 'login' ? 'custom:passkey' : ($pending['channel'] ?? 'custom:two-factor');

            return ['redirectTo' => url(app(Flow::class)->finish($owner, $channel, $purpose === 'challenge' && ($pending['remember'] ?? false)))];
        }, $purpose === 'challenge');
    }

    private function jsonTry(\Closure $callback, bool $failedAttempt = false)
    {
        try {
            return response()->json($callback());
        } catch (\lbuchs\WebAuthn\WebAuthnException|\RuntimeException $e) {
            if ($failedAttempt) {
                app(Flow::class)->fail();
            }

            return response()->json(['message' => $e instanceof \lbuchs\WebAuthn\WebAuthnException ? '通行密钥验证未通过，请重试。' : $e->getMessage()], 422);
        }
    }
}
