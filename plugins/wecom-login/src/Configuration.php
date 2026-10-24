<?php

declare(strict_types=1);

namespace Blessing\WeCom;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;

final class Configuration
{
    public function render(Settings $settings)
    {
        $values = $settings->values();
        return view('Blessing\\WeCom::config', [
            'corp_id' => request()->old('corp_id', $values['corp_id']), 'agent_id' => request()->old('agent_id', $values['agent_id']),
            'redirect_uri' => request()->old('redirect_uri', $values['redirect_uri']), 'secret_set' => $values['secret'] !== '',
            'secret_source' => $settings->secretSource(),
            'geo_ready' => app(IpLocation::class)->ready(),
            'login_notifications_enabled' => (bool) request()->old('login_notifications_enabled', $settings->notificationsEnabled()),
        ]);
    }

    private function failure(Request $request, string $message)
    {
        $input = $request->only(['corp_id', 'agent_id', 'redirect_uri', 'login_notifications_enabled']);
        $input = array_filter($input, static fn ($value) => is_string($value) || is_bool($value) || is_int($value));
        $input['login_notifications_enabled'] = $request->boolean('login_notifications_enabled');
        return redirect('/admin/plugins/config/wecom-login')->with('wecom_error', $message)->withInput($input);
    }

    public function save(Request $request, Settings $settings)
    {
        // Do not flash submitted application secrets into the session on errors.
        $validator = Validator::make($request->only(['corp_id', 'agent_id', 'redirect_uri', 'secret', 'use_env_secret', 'login_notifications_enabled']), [
            'corp_id' => 'required|string|max:128',
            'agent_id' => 'required|string|max:20',
            'redirect_uri' => 'required|string|max:2048',
            'secret' => 'nullable|string|max:512',
            'use_env_secret' => 'sometimes|boolean',
            'login_notifications_enabled' => 'sometimes|boolean',
        ]);
        if ($validator->fails()) {
            return $this->failure($request, '配置字段格式不正确，请检查企业 ID、应用 ID、回调地址及 Secret 长度。');
        }
        $values = $validator->validated();
        if (!$settings->validPublicSettings($values)) {
            return $this->failure($request, 'CorpID / AgentID 或回调地址格式不正确。回调必须为 HTTPS，路径为 /auth/wecom/callback，不能带查询参数。');
        }
        $secret = trim((string) ($values['secret'] ?? ''));
        $useEnvironment = $request->boolean('use_env_secret');
        if ($secret !== '' && $useEnvironment) {
            return $this->failure($request, '填写新 Secret 和恢复环境变量配置不能同时选择。');
        }
        if ($secret !== '') {
            app('options')->set('wecom_secret_encrypted', Crypt::encryptString($secret));
        } elseif ($useEnvironment) {
            app('options')->set('wecom_secret_encrypted', '');
        }
        app('options')->set('wecom_login_notifications_enabled', $request->boolean('login_notifications_enabled') ? '1' : '0');
        unset($values['secret'], $values['use_env_secret'], $values['login_notifications_enabled']);
        foreach ($values as $key => $value) {
            app('options')->set('wecom_'.$key, trim($value));
        }
        // Persisted options are cached by some installations; invalidate that cache.
        if (is_file(storage_path('options.php'))) {
            unlink(storage_path('options.php'));
        }
        return redirect('/admin/plugins/config/wecom-login')->with('wecom_notice', '企业微信配置已保存。');
    }
}
