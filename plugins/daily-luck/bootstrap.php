<?php

use App\Services\Hook;
use Blessing\DailyLuck\Configuration;
use Blessing\DailyLuck\Luck;
use Blessing\DailyLuck\Settings;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;

return function (Blessing\Filter $filter, Illuminate\Contracts\Events\Dispatcher $events): void {
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
        $view->with(['daily_luck' => $user ? app(Luck::class)->today((int) $user->uid) : null, 'luck_settings' => app(Settings::class)->values()]);
    });
    $events->listen(RequestHandled::class, function (RequestHandled $event): void {
        if ($event->request->is('user')) {
            $event->response->headers->set('Cache-Control', 'no-store, private');
        }
    });
    Hook::addRoute(function (): void {
        Route::post('admin/daily-luck/config', [Configuration::class, 'save'])
            ->middleware(['web', 'authorize', 'role:super-admin', 'throttle:10,1,daily-luck.save']);
    });
};
