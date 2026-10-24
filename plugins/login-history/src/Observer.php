<?php

declare(strict_types=1);
namespace Blessing\LoginHistory;

use Illuminate\Auth\Events\Login;
use Illuminate\Routing\Events\RouteMatched;

final class Observer
{
    public function web(Login $event): void
    {
        if ($event->guard !== 'web' || !isset($event->user->uid)) {return;}
        $request = request();
        if ($request->is('api/yggdrasil/*')) {return;}
        $channel = WebChannels::detect($request, \Illuminate\Support\Facades\Auth::guard('web')->viaRemember());
        $backend = str_starts_with($channel, 'oauth') ? 'oauth' : (in_array($channel, ['password', 'wecom', 'remember'], true) ? $channel : 'web');
        app(Recorder::class)->record((int) $event->user->uid, 'web', 'login', $backend, $request, [], $channel);
    }

    public function route(RouteMatched $event): void
    {
        if (!$event->request->isMethod('POST')) {return;}
        $action = ltrim($event->route->getActionName(), '\\');
        foreach (['Yggdrasil\\Controllers\\' => 'yggdrasil-api', 'LittleSkin\\YggdrasilConnect\\Controllers\\' => 'yggdrasil-connect'] as $prefix => $backend) {
            foreach (['AuthController@authenticate' => 'authenticate', 'AuthController@refresh' => 'refresh', 'SessionController@joinServer' => 'join'] as $suffix => $operation) {
                if ($action === $prefix.$suffix) {
                    $event->request->attributes->set('login_history.backend', $backend);
                    $event->request->attributes->set('login_history.operation', $operation);
                    $event->route->middleware(CaptureYggdrasil::class);
                    return;
                }
            }
        }
    }
}
