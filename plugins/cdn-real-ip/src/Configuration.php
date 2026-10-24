<?php

namespace Blessing\CdnRealIp;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

final class Configuration
{
    public function render(Request $request)
    {
        $profiles = Profiles::all();
        $settings = app(Settings::class)->texts();

        return view('Blessing\\CdnRealIp::config', ['settings' => $settings, 'profiles' => $profiles,
            'result' => $request->attributes->get('cdn_real_ip.result') ?? app(Resolver::class)->resolve($request),
            'raw_headers' => $request->attributes->get('cdn_real_ip.raw_headers', []), 'can_save' => $request->user()->permission >= \App\Models\User::SUPER_ADMIN,
            'simulation' => session('cdn_real_ip.simulation')]);
    }

    private function failure(string $text)
    {
        return redirect('admin/plugins/config/cdn-real-ip')->with('cdn_real_ip.error', $text);
    }

    public function save(Request $request)
    {
        $rules = ['enabled' => 'required|boolean', 'sync_scheme' => 'required|boolean', 'local_proxies' => 'nullable|string|max:524288',
            'providers' => 'required|array:'.implode(',', array_keys(Profiles::all())), 'tokens' => 'sometimes|array:'.implode(',', array_keys(Profiles::all()))];
        foreach (Profiles::all() as $id => $profile) {
            $rules['providers.'.$id.'.enabled'] = 'required|boolean';
            $rules['providers.'.$id.'.ranges'] = 'nullable|string|max:524288';
            $rules['providers.'.$id.'.clear_token'] = 'sometimes|boolean';
            $rules['tokens.'.$id] = 'nullable|string|min:32|max:128|regex:/\A[A-Za-z0-9_-]+\z/D';
        }
        $rules['providers.custom.ip_header'] = 'required|string|max:80';
        $rules['providers.custom.ip_mode'] = 'required|in:single,chain';
        $rules['providers.custom.proto_header'] = 'nullable|string|max:80';
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return $this->failure($validator->errors()->first());
        }
        $data = $validator->validated();
        $old = app(Settings::class)->values();
        $new = $old;
        $new['enabled'] = (bool) $data['enabled'];
        $new['sync_scheme'] = (bool) $data['sync_scheme'];
        try {
            $new['local_proxies'] = Addresses::ranges($data['local_proxies'] ?? '');
            foreach (Profiles::all() as $id => $profile) {
                $rule = $data['providers'][$id];
                $ranges = Addresses::ranges($rule['ranges'] ?? '');
                $token = $data['tokens'][$id] ?? null;
                $hash = $old['providers'][$id]['token_hash'];
                if (!empty($rule['clear_token'])) {
                    $hash = '';
                }if ($token) {
                    $hash = hash('sha256', $token);
                }
                if ($rule['enabled'] && !$ranges && $hash === '') {
                    return $this->failure($profile['name'].'：请填写回源 IP / CIDR，或设置回源验证值。');
                }
                $changed = $ranges !== $old['providers'][$id]['ranges'];
                $source = $old['providers'][$id]['source'];
                $at = $old['providers'][$id]['updated_at'];
                if ($changed) {
                    $source = 'manual';
                    $at = now()->toIso8601String();
                    $staged = session('cdn_real_ip.cloudflare');
                    if ($id === 'cloudflare' && $staged && ($staged['uid'] ?? null) === (int) $request->user()->uid && time() - $staged['at'] < 1800 && $staged['ranges'] === $ranges) {
                        $source = 'cloudflare';
                    }
                }
                $new['providers'][$id] = array_replace($old['providers'][$id], ['enabled' => (bool) $rule['enabled'], 'ranges' => $ranges, 'token_hash' => $hash, 'source' => $source, 'updated_at' => $at]);
            }
            foreach (['ip_header', 'proto_header'] as $key) {
                $header = trim($data['providers']['custom'][$key] ?? '');
                if (($key === 'ip_header' && $header === '') || ($header !== '' && (!preg_match('/\A[A-Za-z][A-Za-z0-9-]{0,79}\z/D', $header) || in_array(strtolower($header), ['host', 'cookie', 'authorization', 'proxy-authorization', 'set-cookie', 'x-csrf-token', 'x-xsrf-token', 'x-blessing-cdn-auth', 'content-length'], true)))) {
                    return $this->failure('自定义请求头名称不正确，请填写客户端地址或协议头。');
                }
                $new['providers']['custom'][$key] = $header;
            }
            $new['providers']['custom']['ip_mode'] = $data['providers']['custom']['ip_mode'];
        } catch (\InvalidArgumentException $e) {
            return $this->failure($e->getMessage());
        }
        app(Settings::class)->save($new);
        session()->forget('cdn_real_ip.cloudflare');

        return redirect('admin/plugins/config/cdn-real-ip')->with('cdn_real_ip.notice', 'CDN 客户端地址配置已保存。');
    }

    public function simulate(Request $request)
    {
        $v = Validator::make($request->all(), ['peer' => 'required|ip', 'headers' => 'required|string|max:16384', 'native_scheme' => 'required|in:http,https']);
        if ($v->fails()) {
            return $this->failure($v->errors()->first());
        }$data = $v->validated();
        $headers = json_decode($data['headers'], true);
        if (!is_array($headers) || count($headers) > 32) {
            return $this->failure('请使用 JSON 对象填写请求头。');
        }
        $r = Request::create('http://diagnostic.invalid/');
        $r->server->set('REMOTE_ADDR', $data['peer']);
        $r->server->set('HTTPS', $data['native_scheme'] === 'https' ? 'on' : 'off');
        foreach ($headers as $name => $value) {
            if (!is_string($name) || !is_string($value) || strlen($name) > 80 || strlen($value) > 8192 || preg_match('/[\r\n]/', $value)) {
                return $this->failure('模拟请求头格式不正确。');
            }$r->headers->set($name, $value);
        }
        $result = app(Resolver::class)->resolve($r);

        return redirect('admin/plugins/config/cdn-real-ip')->with('cdn_real_ip.simulation', $result);
    }

    public function cloudflare(Request $request)
    {
        try {
            $response = Http::acceptJson()->connectTimeout(5)->timeout(20)->withOptions(['allow_redirects' => false])->get('https://api.cloudflare.com/client/v4/ips');
            if (!$response->successful() || strlen($response->body()) > 524288 || $response->json('success') !== true) {
                throw new \RuntimeException();
            }
            $v4 = $response->json('result.ipv4_cidrs');
            $v6 = $response->json('result.ipv6_cidrs');
            if (!is_array($v4) || !is_array($v6) || !$v4 || !$v6) {
                throw new \RuntimeException();
            }
            $ranges = Addresses::ranges(implode("\n", array_merge($v4, $v6)));
            if (!$ranges) {
                throw new \RuntimeException();
            }
            session(['cdn_real_ip.cloudflare' => ['uid' => (int) $request->user()->uid, 'at' => time(), 'ranges' => $ranges]]);

            return response()->json(['ranges' => implode("\n", $ranges), 'count' => count($ranges), 'updated_at' => now()->toIso8601String()])->header('Cache-Control', 'no-store, private');
        } catch (\Throwable $e) {
            return response()->json(['message' => '官方地址列表获取失败，请稍后重试。'], 502);
        }
    }
}
