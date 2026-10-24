<?php

declare(strict_types=1);

namespace Blessing\WeCom;

use Closure;
use Illuminate\Http\Request;

final class PrivacyHeaders
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        return $response;
    }
}
