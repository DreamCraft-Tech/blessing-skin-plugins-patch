<?php

namespace Blessing\TwoFactor;

final class AuthRows
{
    public static function compose($view): void
    {
        $rows = $view->getData()['rows'] ?? [];
        $rows = array_values(array_filter($rows, fn ($row) => !in_array(str_replace('\\', '', (string) $row), ['BlessingOAuthCore::providers', 'BlessingWeCom::login-row', 'BlessingWeCom::login-providers', 'BlessingTwoFactor::login-row'], true)));
        $index = array_search('auth.rows.login.registration-link', $rows, true);
        array_splice($rows, $index === false ? count($rows) : $index, 0, ['Blessing\\TwoFactor::login-row']);
        $view->with('rows', $rows);
    }

    public static function providers($view): void
    {
        $plugin = app(\App\Services\PluginManager::class)->get('wecom-login');
        $wecom = $plugin && $plugin->isEnabled() && class_exists('Blessing\\WeCom\\Settings') && app('Blessing\\WeCom\\Settings')->ready();
        $s = app(Settings::class)->values();
        $view->with(['providers' => app(\Blessing\Filter::class)->apply('oauth_providers', collect()), 'wecom_ready' => $wecom, 'passkey_login' => $s['direct_login']]);
    }
}
