<?php

use App\Services\Hook;
use Blessing\DailyLuck\Configuration;
use Blessing\DailyLuck\Installer;
use Blessing\DailyLuck\Settings;
use Blessing\DailyLuck\Viewed;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;

return function (Blessing\Filter $filter, Illuminate\Contracts\Events\Dispatcher $events): void {
    Installer::install();
    App\Models\User::deleted(fn ($user) => Illuminate\Support\Facades\DB::table(Installer::TABLE)->where('user_id', $user->uid)->delete());
    $filter->add('grid:user.index', function (array $grid): array {
        $column = app(Settings::class)->values()['position'] === 'left' ? 0 : 1;
        $grid['widgets'][0][$column] ??= [];
        if (!in_array('Blessing\\DailyLuck::card', $grid['widgets'][0][$column], true)) {
            $grid['widgets'][0][$column][] = 'Blessing\\DailyLuck::card';
        }

        return $grid;
    });
    View::composer(['user.index', 'Blessing\\DailyLuck::card'], function ($view): void {
        $user = auth()->user();
        $view->with(['daily_luck' => $user ? app(Viewed::class)->result((int) $user->uid) : null, 'luck_settings' => app(Settings::class)->values()]);
    });
    $events->listen(RequestHandled::class, function (RequestHandled $event): void {
        if ($event->request->is('user', 'user/daily-luck/*')) {
            $event->response->headers->set('Cache-Control', 'no-store, private');
        }
    });
    Hook::addRoute(function (): void {
        Route::post('user/daily-luck/reveal', [Viewed::class, 'reveal'])
            ->middleware(['web', 'authorize', 'throttle:10,1,daily-luck.reveal']);
        Route::post('admin/daily-luck/config', [Configuration::class, 'save'])
            ->middleware(['web', 'authorize', 'role:super-admin', 'throttle:10,1,daily-luck.save']);
    });
};
