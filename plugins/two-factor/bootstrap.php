<?php

use App\Services\Hook;
use Blessing\TwoFactor\Guard;
use Blessing\TwoFactor\Installer;
use Blessing\TwoFactor\Menus;
use Blessing\TwoFactor\PolicyGate;
use Blessing\TwoFactor\Routes;

return function (Blessing\Filter $filter, Illuminate\Contracts\Events\Dispatcher $events): void {
    require_once __DIR__.'/vendor/autoload.php';
    Installer::install();
    $auth = app('auth');
    $auth->extend('two-factor-session', function ($app, $name, $config) {
        $guard = new Guard($name, $app['auth']->createUserProvider($config['provider'] ?? null), $app['session.store']);
        $guard->setCookieJar($app['cookie']);
        $guard->setDispatcher($app['events']);
        $guard->setRequest($app->refresh('request', $guard, 'setRequest'));
        if (isset($config['remember'])) {
            $guard->setRememberDuration($config['remember']);
        }

        return $guard;
    });
    config(['auth.guards.web.driver' => 'two-factor-session']);
    $auth->forgetGuards();
    app(Illuminate\Contracts\Http\Kernel::class)->appendMiddlewareToGroup('web', PolicyGate::class);
    Hook::addRoute(fn () => Routes::register());
    $events->listen(App\Events\ConfigureUserMenu::class, [Menus::class, 'register']);
    $filter->add('auth_page_rows:login', function (array $rows): array {
        if (!in_array('Blessing\\TwoFactor::login-row', $rows, true)) {
            $index = array_search('auth.rows.login.registration-link', $rows, true);
            array_splice($rows, $index === false ? count($rows) : $index, 0, ['Blessing\\TwoFactor::login-row']);
        }

        return $rows;
    });
    $composed = false;
    Illuminate\Support\Facades\View::creator('auth.login', function () use (&$composed) {
        if (!$composed) {
            Illuminate\Support\Facades\View::composer('auth.login', fn ($view) => Blessing\TwoFactor\AuthRows::compose($view));
            $composed = true;
        }
    });
    Illuminate\Support\Facades\View::composer('Blessing\\TwoFactor::login-row', fn ($view) => Blessing\TwoFactor\AuthRows::providers($view));
    App\Models\User::deleted(function ($user) {foreach (['two_factor_totp', 'two_factor_passkeys', 'two_factor_accounts'] as $table) {Illuminate\Support\Facades\DB::table($table)->where('user_id', $user->uid)->delete(); }});
    if (app()->runningInConsole()) {
        app(Illuminate\Contracts\Console\Kernel::class)->registerCommand(new Blessing\TwoFactor\ResetCommand());
    }
};
