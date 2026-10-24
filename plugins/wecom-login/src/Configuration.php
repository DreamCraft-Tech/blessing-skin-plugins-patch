<?php

declare(strict_types=1);

namespace Blessing\WeCom;

use Illuminate\Http\Request;

final class Configuration
{
    public function render(Settings $settings)
    {
        $values = $settings->values();
        return view('Blessing\\WeCom::config', [
            'corp_id' => $values['corp_id'], 'agent_id' => $values['agent_id'],
            'redirect_uri' => $values['redirect_uri'], 'secret_set' => $values['secret'] !== '',
        ]);
    }

    public function save(Request $request, Settings $settings)
    {
        $values = $request->validate([
            'corp_id' => 'required|string|max:128',
            'agent_id' => 'required|string|max:20',
            'redirect_uri' => 'required|string|max:2048',
        ]);
        if (!$settings->validPublicSettings($values)) {
            return response()->view('Blessing\\WeCom::message', [
                'message' => 'CorpID / AgentID 或回调地址格式不正确。回调必须为 HTTPS，路径为 /auth/wecom/callback，不能带查询参数。',
            ], 422);
        }
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
