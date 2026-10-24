<?php

use App\Services\Hook;
use Blessing\CdnRealIp\NormalizeClient;
use Blessing\CdnRealIp\Resolver;
use Blessing\CdnRealIp\Routes;

return function (Blessing\Filter $filter): void {
    app(Illuminate\Contracts\Http\Kernel::class)->prependMiddleware(NormalizeClient::class);
    $filter->add('client_ip', function ($ip) {
        $request = request();
        $result = $request->attributes->get('cdn_real_ip.result');
        if (!$result) {
            $result = app(Resolver::class)->resolve($request);
        }

return $result['status'] === 'disabled' ? $ip : ($result['ip'] ?? $ip);
    });
    Hook::addRoute(fn () => Routes::register());
};
