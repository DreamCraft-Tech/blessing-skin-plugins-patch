<?php

namespace Blessing\TwoFactor;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class Configuration
{
    public function render()
    {
        return view('Blessing\\TwoFactor::config', ['settings' => app(Settings::class)->values(), 'can_save' => auth()->user()->permission >= \App\Models\User::SUPER_ADMIN]);
    }

    public function save(Request $r)
    {
        $v = Validator::make($r->all(), ['policy' => 'required|in:optional,admins,all', 'allow_totp' => 'required|boolean', 'allow_passkeys' => 'required|boolean', 'direct_login' => 'required|boolean',
            'origin' => 'required|url|max:300', 'rp_name' => 'required|string|max:100', 'user_verification' => 'required|in:required,preferred', 'attachment' => 'required|in:any,platform,cross-platform',
            'max_passkeys' => 'required|integer|min:1|max:50', 'max_totp' => 'required|integer|min:1|max:10', 'timeout' => 'required|integer|min:15|max:120', 'challenge_ttl' => 'required|integer|min:60|max:300|gte:timeout',
            'totp_window' => 'required|integer|min:0|max:1', 'reauth_seconds' => 'required|integer|min:60|max:600']);
        if ($v->fails()) {
            return redirect('admin/plugins/config/two-factor')->withErrors($v)->withInput($r->except('_token'));
        }
        $data = $v->validated();
        foreach (['allow_totp', 'allow_passkeys', 'direct_login'] as $key) {
            $data[$key] = (bool) $data[$key];
        }
        if (!$data['allow_totp'] && !$data['allow_passkeys'] && $data['policy'] !== 'optional') {
            return redirect('admin/plugins/config/two-factor')->with('two_factor_error', '要求开启两步验证时，请至少允许添加一种验证设备。');
        }
        $parts = parse_url($data['origin']);
        if (!$parts || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || !in_array($parts['path'] ?? '', ['', '/'], true)
            || (($parts['scheme'] ?? '') !== 'https' && !(($parts['scheme'] ?? '') === 'http' && ($parts['host'] ?? '') === 'localhost'))) {
            return redirect('admin/plugins/config/two-factor')->with('two_factor_error', '站点地址应为 HTTPS 域名地址，例如 https://skin.example.com。');
        }
        $old = app(Settings::class)->values();
        if (rtrim($old['origin'], '/') !== rtrim($data['origin'], '/') && \Illuminate\Support\Facades\DB::table('two_factor_passkeys')->exists()) {
            return redirect('admin/plugins/config/two-factor')->with('two_factor_error', '已有通行密钥关联当前站点域名。请先完成域名迁移与设备重新注册，再修改此地址。');
        }
        $data['origin'] = rtrim($data['origin'], '/');
        option(['two_factor_settings' => json_encode($data, JSON_UNESCAPED_UNICODE)]);

        return redirect('admin/plugins/config/two-factor')->with('two_factor_notice', '两步验证策略已保存。');
    }
}
