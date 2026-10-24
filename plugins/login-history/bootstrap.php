<?php

declare(strict_types=1);
use App\Services\Hook;
use App\Events\ConfigureUserMenu;
use Blessing\LoginHistory\Menus;
use Blessing\LoginHistory\Observer;
use Blessing\LoginHistory\Controller;
use Illuminate\Auth\Events\Login;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Route;

return function (Dispatcher $events): void {
    $events->listen(ConfigureUserMenu::class, [Menus::class,'appendUserItem']);
    $events->listen(Login::class, Observer::class.'@web');
    $events->listen(RouteMatched::class, Observer::class.'@route');
    Hook::addRoute(function (): void {
        Route::get('user/login-history', [Controller::class,'index'])
            ->middleware(['web','authorize','throttle:60,1'])->name('login-history.index');
    });
};
