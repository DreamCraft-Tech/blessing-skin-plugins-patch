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
        if (app(Settings::class)->ready()) {
            $rows[] = 'Blessing\\WeCom::login-row';
        }
        return $rows;
    });
};
