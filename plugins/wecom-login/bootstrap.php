<?php

declare(strict_types=1);

use App\Services\Hook;
use Blessing\Filter;
use Blessing\WeCom\Routes;
use Blessing\WeCom\Bindings;
use Illuminate\Support\Facades\View;
use Blessing\WeCom\Settings;
use Blessing\WeCom\Menus;
use App\Events\ConfigureUserMenu;
use Illuminate\Contracts\Events\Dispatcher;

return function (Filter $filter, Dispatcher $events): void {
    require_once __DIR__.'/vendor/autoload.php';
    // Enabled installations and interrupted enable callbacks must also repair missing tables.
    \Blessing\WeCom\Installer::install();

    \App\Models\User::deleted(function ($user): void {
        \Illuminate\Support\Facades\DB::table(\Blessing\WeCom\Installer::BINDINGS)->where('user_id', $user->uid)->delete();
        \Illuminate\Support\Facades\DB::table(\Blessing\WeCom\Installer::STATES)->where('user_id', $user->uid)->delete();
    });

    config(['wecom' => [
        'corp_id' => env('WECOM_CORP_ID', ''),
        'agent_id' => env('WECOM_AGENT_ID', ''),
        'redirect_uri' => env('WECOM_REDIRECT_URI', ''),
        'secret' => env('WECOM_SECRET', ''),
    ]]);
    Hook::addRoute(fn () => Routes::register());
    $events->listen(\Illuminate\Auth\Events\Login::class, [\Blessing\WeCom\LoginNotification::class, 'handle']);
    $events->listen(ConfigureUserMenu::class, [Menus::class, 'appendUserItem']);
    $filter->add('grid:user.index', function (array $grid): array {
        $grid['widgets'][0][0][] = 'Blessing\\WeCom::widgets.account';
        return $grid;
    });
    View::composer('Blessing\\WeCom::widgets.account', function ($view): void {
        $uid = auth()->id();
        $view->with([
            'wecom_binding' => $uid ? app(Bindings::class)->forUser((int) $uid) : null,
            'wecom_ready' => app(Settings::class)->ready(),
        ]);
    });
    $filter->add('auth_page_rows:login', function (array $rows): array {
        if (session('wecom_error')) {array_unshift($rows, 'Blessing\\WeCom::auth-error');}
        return $rows;
    });
    View::composer('auth.login', function ($view): void {
        $rows = $view->getData()['rows'] ?? [];
        $rows = array_values(array_filter($rows, static function ($row): bool {
            $key = is_string($row) ? str_replace('\\', '', $row) : '';
            return !in_array($key, ['BlessingOAuthCore::providers', 'BlessingWeCom::login-row', 'BlessingWeCom::login-providers'], true);
        }));
        $index = array_search('auth.rows.login.registration-link', $rows, true);
        if ($index === false) {
            $form = array_search('auth.rows.login.form', $rows, true);
            $index = $form === false ? count($rows) : $form + 1;
        }
        array_splice($rows, $index, 0, ['Blessing\\WeCom::login-providers']);
        $view->with('rows', $rows);
    });
    View::composer('Blessing\\WeCom::login-providers', function ($view) use ($filter): void {
        $view->with(['providers' => $filter->apply('oauth_providers', collect()), 'wecom_ready' => app(Settings::class)->ready()]);
    });
};
