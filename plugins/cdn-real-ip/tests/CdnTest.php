<?php

use App\Events\ConfigureRoutes;
use App\Models\User;
use App\Services\Plugin;
use App\Services\PluginManager;
use Blessing\CdnRealIp\Addresses;
use Blessing\CdnRealIp\Resolver;
use Blessing\CdnRealIp\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

final class CdnTest extends Tests\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $path = dirname(__DIR__);
        $plugin = new Plugin($path, json_decode(file_get_contents($path.'/package.json'), true));
        $manager = app(PluginManager::class);
        $manager->all()->put('cdn-real-ip', $plugin);
        self::assertTrue($manager->enable($plugin));
        (new ReflectionProperty($manager, 'booted'))->setValue($manager, false);
        $manager->boot();
        event(new ConfigureRoutes(app('router')));
        app('router')->getRoutes()->refreshNameLookups();
        Route::middleware('web')->get('test/cdn-client', function (Request $r) {return response()->json(['ip' => $r->ip(), 'ips' => $r->ips(), 'secure' => $r->isSecure(), 'scheme' => $r->getScheme(), 'url' => url('user'), 'filter' => app(Blessing\Filter::class)->apply('client_ip', '9.9.9.9'), 'peer' => $r->attributes->get('cdn_real_ip.result')['peer'], 'header' => $r->header('X-Forwarded-For')]); });
        $settings = app(Settings::class)->values();
        $settings['providers']['tencent']['ranges'] = ['192.0.2.0/24'];
        app(Settings::class)->save($settings);
    }

    private function config(array $changes = []): array
    {
        $s = app(Settings::class)->values();
        foreach ($changes as $key => $value) {
            $s[$key] = $value;
        }app(Settings::class)->save($s);

        return $s;
    }

    private function provider(string $id, array $ranges = ['192.0.2.0/24'], string $token = ''): array
    {
        $s = app(Settings::class)->values();
        foreach ($s['providers'] as &$rule) {
            $rule['enabled'] = false;
        }unset($rule);
        $s['providers'][$id]['enabled'] = true;
        $s['providers'][$id]['ranges'] = $ranges;
        $s['providers'][$id]['token_hash'] = $token !== '' ? hash('sha256', $token) : '';
        app(Settings::class)->save($s);

        return $s;
    }

    private function resolve(string $peer, array $headers = [], array $settings = []): array
    {
        $r = Request::create('http://skin.example.test/');
        $r->server->set('REMOTE_ADDR', $peer);
        foreach ($headers as $key => $value) {
            $r->headers->set($key, $value);
        }

return app(Resolver::class)->resolve($r, $settings ?: null);
    }

    public function testTencentRestoresIpProtocolFilterAndLaravelUrl(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.4'])->withHeaders(['X-Forwarded-For' => '203.0.113.8', 'X-Forwarded-Proto' => 'https'])->get('/test/cdn-client')
            ->assertOk()->assertJsonPath('ip', '203.0.113.8')->assertJsonPath('filter', '203.0.113.8')->assertJsonPath('peer', '192.0.2.4')->assertJsonPath('scheme', 'https')->assertJsonPath('secure', true)->assertJsonPath('url', url('user'));
    }

    public function testDirectRequestsCannotForgeAnyVendorOrProtocolHeader(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.22'])->withHeaders(['X-Forwarded-For' => '1.1.1.1', 'X-Forwarded-Proto' => 'https', 'CF-Connecting-IP' => '8.8.8.8', 'X-Forwarded-Host' => 'evil.example', 'Forwarded' => 'for=9.9.9.9;proto=https;host=evil.example'])->get('/test/cdn-client')
            ->assertOk()->assertJsonPath('ip', '198.51.100.22')->assertJsonPath('filter', '198.51.100.22')->assertJsonPath('secure', false)->assertJsonPath('scheme', 'http')->assertJsonPath('header', '198.51.100.22');
    }

    public function testXffIsWalkedRightToLeftUntilFirstUntrustedHop(): void
    {
        $r = $this->resolve('192.0.2.4', ['X-Forwarded-For' => '1.1.1.1,203.0.113.8', 'X-Forwarded-Proto' => 'https']);
        self::assertSame('203.0.113.8', $r['ip']);
        $s = $this->config(['local_proxies' => ['10.10.0.0/16']]);
        $r = $this->resolve('10.10.0.2', ['X-Forwarded-For' => '1.1.1.1,203.0.113.8,192.0.2.4,10.10.0.1']);
        self::assertSame('203.0.113.8', $r['ip']);
        self::assertSame('tencent', $r['provider']);
        $r = $this->resolve('10.11.0.2', ['X-Forwarded-For' => '203.0.113.8,192.0.2.4']);
        self::assertSame('10.11.0.2', $r['ip']);
        self::assertSame('untrusted', $r['status']);
    }

    public function testProviderHeadersAndIpv6AreRecognized(): void
    {
        foreach (['edgeone' => ['EO-Connecting-IP', 'X-Forwarded-Proto'], 'aliyun' => ['Ali-Cdn-Real-Ip', 'X-Client-Scheme'], 'cloudflare' => ['CF-Connecting-IP', 'X-Forwarded-Proto'], 'volcengine' => ['X-Real-IP', 'X-Forwarded-Proto'], 'huawei' => ['X-Forwarded-For', 'X-Forwarded-Proto'], 'custom' => ['X-Forwarded-For', 'X-Forwarded-Proto']] as $id => [$ip,$proto]) {
            $this->provider($id);
            $r = $this->resolve('192.0.2.4', [$ip => '2001:db8:1::8', $proto => 'https']);
            self::assertSame('2001:db8:1::8', $r['ip'], $id);
            self::assertSame('https', $r['scheme'], $id);
        }
        $this->provider('edgeone');
        self::assertSame('https', $this->resolve('192.0.2.4', ['EO-Connecting-IP' => '203.0.113.8', 'X-Forwarded-Proto' => 'quic'])['scheme']);
    }

    public function testCloudflarePseudoIpv4UsesRealIpv6(): void
    {
        $this->provider('cloudflare');
        $r = $this->resolve('192.0.2.4', ['CF-Connecting-IP' => '240.1.1.1', 'CF-Connecting-IPv6' => '2001:db8::9']);
        self::assertSame('2001:db8::9', $r['ip']);
        self::assertSame('CF-Connecting-IPv6', $r['ip_header']);
        self::assertSame('203.0.113.9', $this->resolve('192.0.2.4', ['CF-Connecting-IP' => '203.0.113.9', 'CF-Connecting-IPv6' => '2001:db8::10'])['ip']);
    }

    public function testSingleHeadersNeverUseUntrustedXffPrefix(): void
    {
        $this->provider('edgeone');
        $r = $this->resolve('192.0.2.4', ['EO-Connecting-IP' => '203.0.113.8', 'X-Forwarded-For' => '1.1.1.1,2.2.2.2']);
        self::assertSame('203.0.113.8', $r['ip']);
        $r = $this->resolve('192.0.2.4', ['EO-Connecting-IP' => 'bad', 'X-Forwarded-For' => '1.1.1.1']);
        self::assertSame('192.0.2.4', $r['ip']);
        self::assertSame('invalid_header', $r['status']);
    }

    public function testTokenOnlyAndCombinedTrustAndWrongToken(): void
    {
        $token = str_repeat('a', 64);
        $this->provider('tencent', [], $token);
        self::assertSame('203.0.113.8', $this->resolve('198.51.100.4', ['X-Forwarded-For' => '203.0.113.8', 'X-Blessing-CDN-Auth' => $token])['ip']);
        self::assertSame('198.51.100.4', $this->resolve('198.51.100.4', ['X-Forwarded-For' => '203.0.113.8', 'X-Blessing-CDN-Auth' => 'bad'])['ip']);
        $this->provider('tencent', ['192.0.2.0/24'], $token);
        self::assertSame('198.51.100.4', $this->resolve('198.51.100.4', ['X-Forwarded-For' => '203.0.113.8', 'X-Blessing-CDN-Auth' => $token])['ip']);
        self::assertSame('192.0.2.4', $this->resolve('192.0.2.4', ['X-Forwarded-For' => '203.0.113.8'])['ip']);
    }

    public function testMalformedAddressesChainsAndProtocolsAreNotAccepted(): void
    {
        foreach (['bad', 'unknown', '1.2.3.999', '203.0.113.8,', '203.0.113.8, bad', str_repeat('203.0.113.8,', 34)] as $value) {
            $r = $this->resolve('192.0.2.4', ['X-Forwarded-For' => $value]);
            self::assertSame('192.0.2.4', $r['ip']);
        }
        self::assertSame('http', $this->resolve('192.0.2.4', ['X-Forwarded-For' => '203.0.113.8', 'X-Forwarded-Proto' => 'https,http'])['scheme']);
        self::assertSame('http', $this->resolve('192.0.2.4', ['X-Forwarded-For' => '203.0.113.8', 'X-Forwarded-Proto' => 'javascript'])['scheme']);
    }

    public function testDuplicateHeadersAndIpv4MappedIpv6(): void
    {
        self::assertSame('192.0.2.4', Addresses::ip('::ffff:192.0.2.4'));
        $r = $this->resolve('::ffff:192.0.2.4', ['X-Forwarded-For' => ['1.1.1.1', '203.0.113.8']]);
        self::assertSame('203.0.113.8', $r['ip']);
        $this->provider('cloudflare');
        $r = $this->resolve('192.0.2.4', ['CF-Connecting-IP' => ['1.1.1.1', '2.2.2.2']]);
        self::assertSame('invalid_header', $r['status']);
    }

    public function testAddressPortsAndCanonicalRanges(): void
    {
        self::assertSame('203.0.113.8', Addresses::ip('203.0.113.8:12345'));
        self::assertSame('2001:db8::8', Addresses::ip('[2001:db8::8]:443'));
        self::assertNull(Addresses::ip('203.0.113.8:99999'));
        self::assertSame(['192.0.2.0/24', '2001:db8::/32'], Addresses::ranges("192.0.2.123/24, 2001:db8::1/32\n#comment\n192.0.2.0/24"));
        foreach (['0.0.0.0/0', '::/0', '*', 'evil.example', '192.0.2.1/33', '::/129', '1.1.1.1/x'] as $entry) {
            try {
                Addresses::ranges($entry);
                $this->fail('Invalid range accepted');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testMultiProviderMatchesByNetworkAndRejectsAmbiguousRules(): void
    {
        $s = app(Settings::class)->values();
        $s['providers']['cloudflare']['enabled'] = true;
        $s['providers']['cloudflare']['ranges'] = ['198.51.100.0/24'];
        app(Settings::class)->save($s);
        self::assertSame('cloudflare', $this->resolve('198.51.100.9', ['CF-Connecting-IP' => '203.0.113.9'])['provider']);
        $s['providers']['cloudflare']['ranges'] = ['192.0.2.0/24'];
        app(Settings::class)->save($s);
        self::assertSame('ambiguous', $this->resolve('192.0.2.9', ['X-Forwarded-For' => '203.0.113.9', 'CF-Connecting-IP' => '203.0.113.9'])['status']);
        $s['providers']['cloudflare']['ranges'] = ['192.0.2.9'];
        app(Settings::class)->save($s);
        self::assertSame('cloudflare', $this->resolve('192.0.2.9', ['CF-Connecting-IP' => '203.0.113.9'])['provider']);
    }

    public function testConfigurationAndDiagnosticDoNotExposeOriginSecret(): void
    {
        $token = str_repeat('b', 64);
        $this->provider('tencent', ['192.0.2.0/24'], $token);
        $user = User::factory()->superAdmin()->create();
        $this->actingAs($user);
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.4'])->withHeaders(['X-Forwarded-For' => '203.0.113.8', 'X-Forwarded-Proto' => 'https', 'X-Blessing-CDN-Auth' => $token])->get('/admin/plugins/config/cdn-real-ip')->assertOk()->assertSee('当前请求诊断')->assertSee('203.0.113.8')->assertSee('已携带（已隐藏）')->assertDontSee($token)->assertHeader('Cache-Control', 'no-store, private');
    }

    private function form(): array
    {
        $s = app(Settings::class)->texts();
        foreach ($s['providers'] as &$p) {
            unset($p['token_set']);
        }unset($p);

        return $s;
    }

    public function testSuperAdminCanSaveAndTokensAreHashed(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $data = $this->form();
        $token = str_repeat('c', 64);
        $data['tokens'] = ['tencent' => $token];
        $this->post('/admin/cdn-real-ip/config', $data)->assertRedirect('/admin/plugins/config/cdn-real-ip')->assertSessionHas('cdn_real_ip.notice');
        self::assertSame(hash('sha256', $token), app(Settings::class)->values()['providers']['tencent']['token_hash']);
        self::assertStringNotContainsString($token, (string) option('cdn_real_ip_settings'));
        $data = $this->form();
        $this->post('/admin/cdn-real-ip/config', $data)->assertSessionHas('cdn_real_ip.notice');
        self::assertSame(hash('sha256', $token), app(Settings::class)->values()['providers']['tencent']['token_hash']);
        $data['providers']['tencent']['clear_token'] = 1;
        $this->post('/admin/cdn-real-ip/config', $data)->assertSessionHas('cdn_real_ip.notice');
        self::assertSame('', app(Settings::class)->values()['providers']['tencent']['token_hash']);
    }

    public function testConfigValidationDoesNotSaveBroadRangesOrFlashTokens(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $data = $this->form();
        $data['providers']['tencent']['ranges'] = '0.0.0.0/0';
        $token = str_repeat('d', 64);
        $data['tokens'] = ['tencent' => $token];
        $this->post('/admin/cdn-real-ip/config', $data)->assertSessionHas('cdn_real_ip.error')->assertSessionMissing('_old_input');
        self::assertSame(['192.0.2.0/24'], app(Settings::class)->values()['providers']['tencent']['ranges']);
        $data = $this->form();
        $data['providers']['custom']['ip_header'] = 'Authorization';
        $this->post('/admin/cdn-real-ip/config', $data)->assertSessionHas('cdn_real_ip.error');
        $data = $this->form();
        $data['providers']['tencent']['ranges'] = '';
        $this->post('/admin/cdn-real-ip/config', $data)->assertSessionHas('cdn_real_ip.error');
    }

    public function testConfigReadAndWritePermissions(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/plugins/config/cdn-real-ip')->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->get('/admin/plugins/config/cdn-real-ip')->assertOk();
        foreach (['config', 'simulate', 'cloudflare'] as $action) {
            $this->post('/admin/cdn-real-ip/'.$action, [])->assertForbidden();
        }
    }

    public function testSimulationIsLocalAndUsesSameResolver(): void
    {
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->superAdmin()->create());
        $this->post('/admin/cdn-real-ip/simulate', ['peer' => '192.0.2.4', 'native_scheme' => 'http', 'headers' => json_encode(['X-Forwarded-For' => '203.0.113.8', 'X-Forwarded-Proto' => 'https'])])->assertRedirect()->assertSessionHas('cdn_real_ip.simulation.ip', '203.0.113.8');
        $this->post('/admin/cdn-real-ip/simulate', ['peer' => 'evil', 'native_scheme' => 'http', 'headers' => '{}'])->assertSessionHas('cdn_real_ip.error');
        Http::assertNothingSent();
    }

    public function testOfficialCloudflareFetchStagesBeforeSaveAndKeepsExistingOnFailure(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        Http::fake(['api.cloudflare.com/client/v4/ips' => Http::sequence()->push(['success' => true, 'result' => ['ipv4_cidrs' => ['192.0.2.0/24'], 'ipv6_cidrs' => ['2001:db8::/32']]])->push(['success' => true, 'result' => ['ipv4_cidrs' => ['0.0.0.0/0'], 'ipv6_cidrs' => ['::/0']]])]);
        $this->postJson('/admin/cdn-real-ip/cloudflare')->assertOk()->assertJsonPath('count', 2);
        self::assertSame([], app(Settings::class)->values()['providers']['cloudflare']['ranges']);
        $data = $this->form();
        $data['providers']['tencent']['enabled'] = 0;
        $data['providers']['cloudflare']['enabled'] = 1;
        $data['providers']['cloudflare']['ranges'] = "192.0.2.0/24\n2001:db8::/32";
        $this->post('/admin/cdn-real-ip/config', $data)->assertSessionHas('cdn_real_ip.notice');
        self::assertSame('cloudflare', app(Settings::class)->values()['providers']['cloudflare']['source']);
        $this->postJson('/admin/cdn-real-ip/cloudflare')->assertStatus(502);
        self::assertSame(['192.0.2.0/24', '2001:db8::/32'], app(Settings::class)->values()['providers']['cloudflare']['ranges']);
    }

    public function testNativeHttpsAndExplicitSchemeSwitch(): void
    {
        $this->config(['sync_scheme' => false]);
        $r = $this->resolve('192.0.2.4', ['X-Forwarded-For' => '203.0.113.8', 'X-Forwarded-Proto' => 'https']);
        self::assertSame('http', $r['scheme']);
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.4', 'HTTPS' => 'on', 'SERVER_PORT' => 443])->get('https://localhost/test/cdn-client')->assertJsonPath('secure', true)->assertJsonPath('scheme', 'https');
    }

    public function testDisabledProcessingLeavesRequestAndLegacyFilterUnchanged(): void
    {
        $this->config(['enabled' => false]);
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.4'])->withHeaders(['X-Forwarded-For' => '203.0.113.8'])->get('/test/cdn-client')->assertOk()->assertJsonPath('ip', '192.0.2.4')->assertJsonPath('filter', '9.9.9.9')->assertJsonPath('header', '203.0.113.8');
    }

    public function testLoginRateLimitUsesRestoredIp(): void
    {
        $user = User::factory()->create();
        $user->changePassword('Password-example-123');
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.4'])->withHeaders(['X-Forwarded-For' => '203.0.113.8'])->postJson('/auth/login', ['identification' => $user->email, 'password' => 'wrong-pass'])->assertOk();
        self::assertSame(1, (int) Cache::get(sha1('login_fails_203.0.113.8')));
        self::assertSame(0, (int) Cache::get(sha1('login_fails_192.0.2.4')));
    }

    public function testCsrfIsEnforcedAndNoPluginTablesAreRequired(): void
    {
        app()->instance('env', 'production');
        $this->postJson('/admin/cdn-real-ip/config', [])->assertStatus(419);
        self::assertFalse(Illuminate\Support\Facades\Schema::hasTable('cdn_real_ip'));
    }
}
