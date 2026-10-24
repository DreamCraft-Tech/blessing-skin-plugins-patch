<?php

namespace Blessing\CdnRealIp;

use Illuminate\Support\Facades\Route;

final class Routes
{
    public static function register(): void
    {
        Route::middleware(['web', 'authorize', 'role:super-admin'])->prefix('admin/cdn-real-ip')->group(function () {
            Route::post('config', [Configuration::class, 'save'])->middleware('throttle:10,1,cdn.save');
            Route::post('simulate', [Configuration::class, 'simulate'])->middleware('throttle:20,1,cdn.simulate');
            Route::post('cloudflare', [Configuration::class, 'cloudflare'])->middleware('throttle:3,1,cdn.cloudflare');
        });
    }
}
