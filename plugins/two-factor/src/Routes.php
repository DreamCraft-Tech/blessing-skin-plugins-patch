<?php

namespace Blessing\TwoFactor;

use Illuminate\Support\Facades\Route;

final class Routes
{
    public static function register(): void
    {
        Route::middleware('web')->group(function () {
            Route::get('auth/two-factor', [Controller::class, 'challenge'])->name('twofactor.challenge');
            Route::post('auth/two-factor/code', [Controller::class, 'code'])->middleware('throttle:10,1,tf.1');
            Route::post('auth/two-factor/cancel', [Controller::class, 'cancel'])->middleware('throttle:10,1,tf.2');
            Route::post('auth/two-factor/passkey/options', [Controller::class, 'assertionOptions'])->middleware('throttle:15,1,tf.3');
            Route::post('auth/two-factor/passkey/verify', [Controller::class, 'assertionVerify'])->middleware('throttle:10,1,tf.4');
            Route::middleware('authorize')->prefix('user/two-factor')->group(function () {
                Route::get('', [Controller::class, 'account'])->name('twofactor.account');
                Route::post('confirm', [Controller::class, 'confirm'])->middleware('throttle:5,1,tf.5');
                Route::post('totp/start', [Controller::class, 'startTotp'])->middleware('throttle:5,1,tf.6');
                Route::post('totp/confirm', [Controller::class, 'confirmTotp'])->middleware('throttle:5,1,tf.7');
                Route::post('passkeys/options', [Controller::class, 'createOptions'])->middleware('throttle:10,1,tf.8');
                Route::post('passkeys/register', [Controller::class, 'registerKey'])->middleware('throttle:5,1,tf.9');
                Route::post('devices/remove', [Controller::class, 'remove'])->middleware('throttle:5,1,tf.10');
                Route::post('devices/rename', [Controller::class, 'rename'])->middleware('throttle:10,1,tf.11');
                Route::post('recovery', [Controller::class, 'recovery'])->middleware('throttle:5,1,tf.12');
                Route::post('toggle', [Controller::class, 'toggle'])->middleware('throttle:5,1,tf.13');
            });
            Route::post('admin/two-factor/config', [Configuration::class, 'save'])->middleware(['authorize', 'role:super-admin', 'throttle:10,1,tf.14']);
        });
    }
}
