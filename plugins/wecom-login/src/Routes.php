<?php

declare(strict_types=1);

namespace Blessing\WeCom;

use Illuminate\Support\Facades\Route;

final class Routes
{
    public static function register(): void
    {
        Route::middleware(['web', PrivacyHeaders::class])->group(function (): void {
            Route::get('auth/wecom/login', [Controller::class, 'login'])
                ->middleware('throttle:10,1')->name('wecom.login');
            Route::get('auth/wecom/callback', [Controller::class, 'callback'])
                ->middleware('throttle:30,1')->name('wecom.callback');
            Route::middleware('authorize')->group(function (): void {
                Route::get('user/wecom', [Controller::class, 'account'])->name('wecom.account');
                Route::post('user/wecom/bind', [Controller::class, 'bind'])
                    ->middleware('throttle:10,1')->name('wecom.bind');
                Route::post('user/wecom/notifications', [Controller::class, 'notifications'])
                    ->middleware('throttle:10,1')->name('wecom.notifications');
                Route::post('user/wecom/unbind', [Controller::class, 'unbind'])
                    ->middleware('throttle:5,1')->name('wecom.unbind');
                Route::middleware('role:admin')->prefix('admin/wecom/geoip')->group(function (): void {
                    Route::get('status', [GeoDatabaseController::class, 'status'])->middleware('throttle:60,1');
                    Route::post('start', [GeoDatabaseController::class, 'start'])->middleware('throttle:5,1');
                    Route::post('step', [GeoDatabaseController::class, 'step'])->middleware('throttle:180,1');
                });
                Route::post('admin/wecom/config', [Configuration::class, 'save'])
                    ->middleware('role:admin')->name('wecom.config.save');
            });
        });
    }
}
