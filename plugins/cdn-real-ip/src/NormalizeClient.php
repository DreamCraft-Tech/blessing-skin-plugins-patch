<?php

namespace Blessing\CdnRealIp;

use Illuminate\Support\Facades\URL;

final class NormalizeClient
{
    public function handle($request, \Closure $next)
    {
        if ($request->attributes->has('cdn_real_ip.result')) {
            return $next($request);
        }
        $settings = app(Settings::class)->values();
        $raw = [];
        foreach (Profiles::headers() as $name) {
            if ($request->headers->has($name)) {
                $raw[$name] = mb_substr(implode(',', $request->headers->all($name)), 0, 8192);
            }
        }
        if ($request->headers->has('X-Blessing-CDN-Auth')) {
            $raw['X-Blessing-CDN-Auth'] = '已携带（已隐藏）';
        }
        $result = app(Resolver::class)->resolve($request, $settings);
        $request->attributes->set('cdn_real_ip.raw_headers', $raw);
        $request->attributes->set('cdn_real_ip.result', $result);
        if ($settings['enabled']) {
            if ($result['ip']) {
                $request->server->set('REMOTE_ADDR', $result['ip']);
            }
            $headers = Profiles::headers();
            $custom = $settings['providers']['custom'];
            $headers[] = $custom['ip_header'];
            $headers[] = $custom['proto_header'];
            foreach (array_unique(array_filter($headers)) as $name) {
                $request->headers->remove($name);
                $request->server->remove('HTTP_'.strtoupper(str_replace('-', '_', $name)));
            }
            if ($result['ip']) {
                $request->headers->set('X-Forwarded-For', $result['ip']);
                $request->server->set('HTTP_X_FORWARDED_FOR', $result['ip']);
            }
            $scheme = $result['scheme'];
            $request->headers->set('X-Forwarded-Proto', $scheme);
            $request->server->set('HTTP_X_FORWARDED_PROTO', $scheme);
            $request->server->set('HTTPS', $scheme === 'https' ? 'on' : 'off');
            $request->server->set('REQUEST_SCHEME', $scheme);
            if ($result['proto_header']) {
                $request->server->set('SERVER_PORT', $scheme === 'https' ? 443 : 80);
            }
            URL::forceScheme(option('force_ssl') ? 'https' : $scheme);
        }
        $response = $next($request);
        if ($request->is('admin/plugins/config/cdn-real-ip', 'admin/cdn-real-ip/*')) {
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('Referrer-Policy', 'same-origin');
        }

        return $response;
    }
}
