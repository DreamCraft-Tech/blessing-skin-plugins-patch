<?php

use App\Events\ConfigureRoutes;
use App\Models\User;
use App\Services\Plugin;
use App\Services\PluginManager;
use Blessing\TwoFactor\Accounts;
use Blessing\TwoFactor\Passkeys;
use Blessing\TwoFactor\Settings;
use Blessing\TwoFactor\Totp;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

final class TwoFactorTest extends Tests\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $path = dirname(__DIR__);
        $plugin = new Plugin($path, json_decode(file_get_contents($path.'/package.json'), true));
        $manager = app(PluginManager::class);
        $manager->all()->put('two-factor', $plugin);
        self::assertTrue($manager->enable($plugin));
        (new ReflectionProperty($manager, 'booted'))->setValue($manager, false);
        $manager->boot();
        event(new ConfigureRoutes(app('router')));
        app('router')->getRoutes()->refreshNameLookups();
        option(['two_factor_settings' => json_encode(array_replace(app(Settings::class)->values(), ['origin' => 'https://skin.example.test']))]);
    }

    private function user(int $permission = 0): User
    {
        $user = User::factory()->create(['permission' => $permission, 'verified' => true]);
        $user->changePassword('Example-password-123');

        return $user;
    }

    private function settings(array $values): void
    {
        option(['two_factor_settings' => json_encode(array_replace(app(Settings::class)->values(), $values))]);
    }

    private function device(User $user): string
    {
        $secret = app(Totp::class)->secret();
        app(Accounts::class)->account((int) $user->uid);
        DB::table('two_factor_totp')->insert(['user_id' => $user->uid, 'name' => '手机', 'secret' => Crypt::encryptString($secret), 'last_step' => -1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('two_factor_accounts')->where('user_id', $user->uid)->update(['enabled' => true]);

        return $secret;
    }

    private function login(User $user)
    {
        return $this->postJson('/auth/login', ['identification' => $user->email, 'password' => 'Example-password-123', 'keep' => true]);
    }

    private function code(string $secret): string
    {
        return app(Totp::class)->code($secret, intdiv(time(), 30));
    }

    private function manage(User $user): void
    {
        $this->actingAs($user);
        app(Accounts::class)->mark((int) $user->uid);
    }

    public function testInstallationRepairsMissingTableAndHasNoForeignKeys(): void
    {
        Schema::drop('two_factor_passkeys');
        Blessing\TwoFactor\Installer::install();
        foreach (['two_factor_accounts', 'two_factor_totp', 'two_factor_passkeys'] as $table) {
            self::assertTrue(Schema::hasTable($table));
            self::assertCount(0, DB::select('PRAGMA foreign_key_list('.$table.')'));
        }
    }

    public function testTotpRfc6238VectorsAndReplays(): void
    {
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        foreach ([59 => '94287082', 1111111109 => '07081804', 1111111111 => '14050471', 1234567890 => '89005924', 2000000000 => '69279037', 20000000000 => '65353130'] as $time => $code) {
            self::assertSame($code, app(Totp::class)->code($secret, intdiv($time, 30), 8));
        }
        $user = $this->user();
        $secret = $this->device($user);
        self::assertTrue(app(Accounts::class)->proof((int) $user->uid, $this->code($secret)));
        self::assertFalse(app(Accounts::class)->proof((int) $user->uid, $this->code($secret)));
        self::assertFalse(app(Accounts::class)->proof((int) $user->uid, '12345'));
        self::assertFalse(app(Accounts::class)->proof((int) $user->uid, 'abcdef'));
    }

    public function testPasswordDoesNotEstablishSessionBeforeSecondStep(): void
    {
        $user = $this->user();
        $secret = $this->device($user);
        $events = [];
        app('events')->listen(Illuminate\Auth\Events\Login::class, function ($event) use (&$events) {$events[] = $event; });
        $this->login($user)->assertOk()->assertJsonPath('data.redirectTo', url('auth/two-factor'));
        $this->assertGuest();
        self::assertCount(0, $events);
        $this->get('/user')->assertRedirect('/auth/login');
        $this->get('/auth/two-factor')->assertOk()->assertSee('两步验证');
        $this->post('/auth/two-factor/code', ['current_password' => $this->code($secret)])->assertRedirect('/user');
        $this->assertAuthenticatedAs($user);
        self::assertCount(1, $events);
        $this->get('/user')->assertOk();
    }

    public function testRecoveryCodeIsOneUseAndStoredHashed(): void
    {
        $user = $this->user();
        $this->device($user);
        $codes = app(Accounts::class)->recovery((int) $user->uid);
        self::assertCount(10, $codes);
        self::assertStringNotContainsString($codes[0], app(Accounts::class)->account((int) $user->uid)->recovery_codes);
        $this->login($user);
        $this->post('/auth/two-factor/code', ['current_password' => $codes[0]])->assertRedirect('/user');
        Auth::logout();
        $this->login($user);
        $this->post('/auth/two-factor/code', ['current_password' => $codes[0]])->assertRedirect('/auth/two-factor');
        $this->assertGuest();
    }

    public function testChallengeExpiryAttemptsAndAccountBinding(): void
    {
        $user = $this->user();
        $this->device($user);
        $this->login($user);
        for ($i = 0; $i < 5; $i++) {
            $this->post('/auth/two-factor/code', ['current_password' => 'bad-code'])->assertRedirect('/auth/two-factor');
        }
        $this->get('/auth/two-factor')->assertRedirect('/auth/login');
        $this->login($user);
        $pending = session('two_factor.pending');
        $pending['at'] = time() - 301;
        session(['two_factor.pending' => $pending]);
        $this->get('/auth/two-factor')->assertRedirect('/auth/login');
        $this->login($user);
        DB::table('two_factor_accounts')->where('user_id', $user->uid)->update(['revision' => bin2hex(random_bytes(16))]);
        $this->get('/auth/two-factor')->assertRedirect('/auth/login');
    }

    public function testMenuIsPeerOfProfileAndAdminConfigurationIsRegistered(): void
    {
        $admin = $this->user(User::SUPER_ADMIN);
        $this->manage($admin);
        $this->get('/user/two-factor')->assertOk()->assertSee('TOTP 验证设备')->assertSee('通行密钥验证设备');
        $menus = config('menu');
        $event = new App\Events\ConfigureUserMenu($menus);
        event($event);
        $menu = $event->menu['user'];
        $profile = array_search('user/profile', array_column($menu, 'link'));
        self::assertSame('user/two-factor', $menu[$profile + 1]['link']);
        self::assertArrayNotHasKey('children', $menu[$profile + 1]);
        $this->get('/admin/plugins/config/two-factor')->assertOk()->assertSee('用户两步验证策略')->assertSee('通行密钥与站点身份');
        $this->get('/admin')->assertOk()->assertSee('两步验证');
    }

    public function testEnrollmentRequiresConfirmationAndPersistsOnlyConfirmedSecret(): void
    {
        $user = $this->user();
        $this->actingAs($user);
        $this->post('/user/two-factor/totp/start', ['name' => '手机'])->assertRedirect('/user/two-factor');
        self::assertFalse(DB::table('two_factor_totp')->exists());
        $this->post('/user/two-factor/confirm', ['current_password' => 'Example-password-123'])->assertRedirect();
        $this->post('/user/two-factor/totp/start', ['name' => '手机'])->assertRedirect();
        $setup = session('two_factor.setup');
        self::assertNotNull($setup);
        $this->get('/user/two-factor')->assertOk()->assertSee('<svg', false)->assertSee($setup['secret']);
        self::assertFalse(DB::table('two_factor_totp')->exists());
        $this->post('/user/two-factor/totp/confirm', ['current_password' => $this->code($setup['secret'])])->assertRedirect();
        $device = DB::table('two_factor_totp')->first();
        self::assertNotSame($setup['secret'], $device->secret);
        self::assertSame($setup['secret'], Crypt::decryptString($device->secret));
        self::assertTrue((bool) app(Accounts::class)->account((int) $user->uid)->enabled);
        $codes = session('two_factor.recovery_plain');
        self::assertCount(10, $codes);
        $this->get('/user/two-factor')->assertOk()->assertSee($codes[0])->assertHeader('Cache-Control', 'no-store, private');
        $this->get('/user/two-factor')->assertOk()->assertDontSee($codes[0]);
    }

    public function testRequiredPolicyRoutesToEnrollmentAndProtectsLastDevice(): void
    {
        $user = $this->user(User::ADMIN);
        $this->settings(['policy' => 'admins']);
        $this->login($user)->assertOk();
        $this->get('/user')->assertRedirect('/user/two-factor');
        $this->get('/user/two-factor')->assertOk();
        $this->device($user);
        app(Accounts::class)->mark((int) $user->uid);
        $device = DB::table('two_factor_totp')->first();
        $this->post('/user/two-factor/devices/remove', ['kind' => 'totp', 'id' => $device->id])->assertRedirect('/user/two-factor');
        self::assertSame(1, DB::table('two_factor_totp')->count());
        $this->post('/user/two-factor/toggle', ['enabled' => 0])->assertRedirect('/user/two-factor');
        self::assertTrue((bool) app(Accounts::class)->account((int) $user->uid)->enabled);
    }

    public function testDeviceOwnershipAndUserDeletionCleanup(): void
    {
        $a = $this->user();
        $b = $this->user();
        $this->device($b);
        $id = DB::table('two_factor_totp')->value('id');
        $this->manage($a);
        $this->post('/user/two-factor/devices/remove', ['kind' => 'totp', 'id' => $id])->assertNotFound();
        $this->post('/user/two-factor/devices/rename', ['kind' => 'totp', 'id' => $id, 'name' => 'evil'])->assertNotFound();
        $b->delete();
        self::assertSame(0, DB::table('two_factor_totp')->count());
        self::assertFalse(DB::table('two_factor_accounts')->where('user_id', $b->uid)->exists());
    }

    public function testPolicyChangeInvalidatesVerifiedSession(): void
    {
        $user = $this->user();
        $this->device($user);
        $this->manage($user);
        $this->get('/user')->assertOk();
        $this->settings(['totp_window' => 0]);
        $this->get('/user')->assertRedirect('/auth/two-factor');
        $this->assertGuest();
    }

    public function testConfigurationPermissionsAndValidation(): void
    {
        $admin = $this->user(User::ADMIN);
        $this->manage($admin);
        $this->post('/admin/two-factor/config', [])->assertForbidden();
        $super = $this->user(User::SUPER_ADMIN);
        $this->manage($super);
        $values = app(Settings::class)->values();
        $this->post('/admin/two-factor/config', array_replace($values, ['origin' => 'http://example.com']))->assertSessionHas('two_factor_error');
        $this->post('/admin/two-factor/config', array_replace($values, ['policy' => 'all', 'allow_totp' => false, 'allow_passkeys' => false]))->assertSessionHas('two_factor_error');
        $this->post('/admin/two-factor/config', array_replace($values, ['timeout' => 121]))->assertSessionHasErrors('timeout');
        $this->post('/admin/two-factor/config', array_replace($values, ['max_passkeys' => 3]))->assertRedirect('/admin/plugins/config/two-factor');
        self::assertEquals(3, app(Settings::class)->values()['max_passkeys']);
    }

    public function testLoginMethodsAreGroupedAndDisabledPolicyHidesPasskey(): void
    {
        $filter = app(Blessing\Filter::class);
        $filter->add('oauth_providers', fn ($p) => collect(['github' => ['displayName' => 'GitHub', 'icon' => 'github']]));
        $response = $this->get('/auth/login')->assertOk()->assertSee('通行密钥登录')->assertSee('GitHub');
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$response->getContent());
        $xpath = new DOMXPath($dom);
        self::assertSame(1, $xpath->query('//*[@id="two-factor-login-methods"]')->length);
        self::assertSame(1, $xpath->query('//*[@id="two-factor-login-methods"]//button[@data-passkey-purpose="login"]')->length);
        $this->settings(['direct_login' => false]);
        $this->get('/auth/login')->assertOk()->assertDontSee('通行密钥登录');
        $this->postJson('/auth/two-factor/passkey/options', ['purpose' => 'login'])->assertForbidden();
    }

    public function testTotpAndRecoveryCannotBeFlashedAsOldInput(): void
    {
        $user = $this->user();
        $this->actingAs($user);
        $this->post('/user/two-factor/confirm', ['current_password' => 'wrong-password'])->assertRedirect()->assertSessionMissing('_old_input');
    }

    private function bytes(string $value, int $major = 2): string
    {
        $length = strlen($value);

        return chr(($major << 5) | ($length < 24 ? $length : ($length < 256 ? 24 : 25))).($length < 24 ? '' : ($length < 256 ? chr($length) : pack('n', $length))).$value;
    }

    private function fixture(User $user, string $name = '我的通行密钥'): array
    {
        $this->manage($user);
        $options = $this->postJson('/user/two-factor/passkeys/options')->assertOk()->json('publicKey');
        $private = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $details = openssl_pkey_get_details($private);
        $id = random_bytes(32);
        // OpenSSL may omit leading zeros; P-256 COSE coordinates require 32 bytes.
        $cose = "\xa5\x01\x02\x03\x26\x20\x01\x21".$this->bytes(str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT))."\x22".$this->bytes(str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT));
        $auth = hash('sha256', 'skin.example.test', true)."\x45".pack('N', 0).str_repeat("\0", 16).pack('n', strlen($id)).$id.$cose;
        $att = "\xa3".$this->bytes('fmt', 3).$this->bytes('none', 3).$this->bytes('authData', 3).$this->bytes($auth).$this->bytes('attStmt', 3)."\xa0";
        $client = json_encode(['type' => 'webauthn.create', 'challenge' => $options['challenge'], 'origin' => 'https://skin.example.test', 'crossOrigin' => false]);
        $data = ['name' => $name, 'id' => Passkeys::encode($id), 'clientDataJSON' => Passkeys::encode($client), 'attestationObject' => Passkeys::encode($att), 'transports' => ['internal']];

        return [$private, $id, $data];
    }

    private function registerFixture(User $user): array
    {
        [$private,$id,$data] = $this->fixture($user);
        $this->postJson('/user/two-factor/passkeys/register', $data)->assertOk();

        return [$private, $id];
    }

    private function assertion(User $user, $private, string $id, string $purpose = 'login', int $count = 1, array $override = []): array
    {
        $options = $this->postJson('/auth/two-factor/passkey/options', ['purpose' => $purpose])->assertOk()->json('publicKey');
        $client = json_encode(array_replace(['type' => 'webauthn.get', 'challenge' => $options['challenge'], 'origin' => 'https://skin.example.test', 'crossOrigin' => false], $override));
        $auth = hash('sha256', 'skin.example.test', true)."\x05".pack('N', $count);
        openssl_sign($auth.hash('sha256', $client, true), $signature, $private, OPENSSL_ALGO_SHA256);

        return ['purpose' => $purpose, 'id' => Passkeys::encode($id), 'clientDataJSON' => Passkeys::encode($client), 'authenticatorData' => Passkeys::encode($auth), 'signature' => Passkeys::encode($signature), 'userHandle' => Passkeys::encode(hex2bin(app(Accounts::class)->account((int) $user->uid)->handle))];
    }

    public function testRealPasskeyRegistrationAndDirectLogin(): void
    {
        $user = $this->user();
        [$private,$id] = $this->registerFixture($user);
        self::assertSame(1, DB::table('two_factor_passkeys')->count());
        $key = DB::table('two_factor_passkeys')->first();
        self::assertStringContainsString('PUBLIC KEY', $key->public_key);
        self::assertTrue((bool) app(Accounts::class)->account((int) $user->uid)->enabled);
        Auth::logout();
        $data = $this->assertion($user, $private, $id);
        $this->postJson('/auth/two-factor/passkey/verify', $data)->assertOk()->assertJsonPath('redirectTo', url('/user'));
        $this->assertAuthenticatedAs($user);
        self::assertSame(1, (int) DB::table('two_factor_passkeys')->value('sign_count'));
        $this->postJson('/auth/two-factor/passkey/verify', $data)->assertUnprocessable();
    }

    public function testRealPasskeySecondStepAndManagementConfirmation(): void
    {
        $user = $this->user();
        [$private,$id] = $this->registerFixture($user);
        Auth::logout();
        $this->login($user)->assertJsonPath('data.redirectTo', url('auth/two-factor'));
        $this->assertGuest();
        $data = $this->assertion($user, $private, $id, 'challenge');
        $this->postJson('/auth/two-factor/passkey/verify', $data)->assertOk();
        $this->assertAuthenticatedAs($user);
        session()->forget('two_factor.recent');
        $data = $this->assertion($user, $private, $id, 'manage', 2);
        $this->postJson('/auth/two-factor/passkey/verify', $data)->assertOk();
        self::assertTrue(app(Accounts::class)->recent((int) $user->uid));
    }

    public function testPasskeyRejectsWrongOriginChallengeUserAndSignature(): void
    {
        $user = $this->user();
        [$private,$id] = $this->registerFixture($user);
        Auth::logout();
        foreach ([['origin' => 'https://evil.skin.example.test'], ['challenge' => Passkeys::encode(random_bytes(32))], ['crossOrigin' => true], ['tokenBinding' => ['status' => 'present', 'id' => 'bad']]] as $override) {
            $data = $this->assertion($user, $private, $id, 'login', 1, $override);
            $this->postJson('/auth/two-factor/passkey/verify', $data)->assertUnprocessable();
            $this->assertGuest();
        }
        $data = $this->assertion($user, $private, $id);
        $data['signature'] = Passkeys::encode(random_bytes(64));
        $this->postJson('/auth/two-factor/passkey/verify', $data)->assertUnprocessable();
        $this->assertGuest();
        $data = $this->assertion($user, $private, $id);
        $data['userHandle'] = Passkeys::encode(random_bytes(32));
        $this->postJson('/auth/two-factor/passkey/verify', $data)->assertUnprocessable();
        $this->assertGuest();
    }

    public function testPasskeyRejectsMissingUserVerificationExpiredAndWrongPurpose(): void
    {
        $user = $this->user();
        [$private,$id] = $this->registerFixture($user);
        Auth::logout();
        $data = $this->assertion($user, $private, $id);
        $client = Passkeys::decode($data['clientDataJSON']);
        $auth = hash('sha256', 'skin.example.test', true)."\x01".pack('N', 1);
        openssl_sign($auth.hash('sha256', $client, true), $sig, $private, OPENSSL_ALGO_SHA256);
        $data['authenticatorData'] = Passkeys::encode($auth);
        $data['signature'] = Passkeys::encode($sig);
        $this->postJson('/auth/two-factor/passkey/verify', $data)->assertUnprocessable();
        $this->assertGuest();
        $data = $this->assertion($user, $private, $id);
        $state = session('two_factor.webauthn');
        $state['at'] = time() - 301;
        session(['two_factor.webauthn' => $state]);
        $this->postJson('/auth/two-factor/passkey/verify', $data)->assertUnprocessable();
        $data = $this->assertion($user, $private, $id);
        $this->settings(['user_verification' => 'preferred']);
        $this->postJson('/auth/two-factor/passkey/verify', $data)->assertUnprocessable();
        $this->manage($user);
        $data = $this->assertion($user, $private, $id, 'manage');
        $data['purpose'] = 'login';
        Auth::logout();
        $this->postJson('/auth/two-factor/passkey/verify', $data)->assertUnprocessable();
        $this->assertGuest();
    }

    public function testPasskeyCannotRegisterWithoutRecentProofOrOverDeviceLimit(): void
    {
        $user = $this->user();
        $this->actingAs($user);
        $this->postJson('/user/two-factor/passkeys/options')->assertForbidden();
        $this->settings(['max_passkeys' => 1]);
        $this->registerFixture($user);
        [$private,$id,$data] = $this->fixture($user);
        $this->postJson('/user/two-factor/passkeys/register', $data)->assertUnprocessable();
        self::assertSame(1, DB::table('two_factor_passkeys')->count());
    }

    private function enablePlugin(string $name): void
    {
        $path = dirname(__DIR__, 2).'/'.$name;
        $plugin = new Plugin($path, json_decode(file_get_contents($path.'/package.json'), true));
        $manager = app(PluginManager::class);
        $manager->all()->put($name, $plugin);
        self::assertTrue($manager->enable($plugin));
        $manager->registerPlugin($plugin);
        (new ReflectionMethod($manager, 'loadViewsAndTranslations'))->invoke($manager, $plugin);
        $manager->bootPlugin($plugin);
        event(new ConfigureRoutes(app('router')));
        app('router')->getRoutes()->refreshNameLookups();
    }

    public function testEnterpriseLoginAndPasskeyStayInOneGroupRegardlessOfBootOrder(): void
    {
        $this->enablePlugin('wecom-login');
        option(['wecom_corp_id' => 'ww1234567890abcdef', 'wecom_agent_id' => '1000002', 'wecom_redirect_uri' => 'https://skin.example.test/auth/wecom/callback', 'wecom_secret_encrypted' => Crypt::encryptString(str_repeat('a', 40))]);
        $response = $this->get('/auth/login')->assertOk()->assertSee('企业微信扫码登录')->assertSee('通行密钥登录');
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$response->getContent());
        $xpath = new DOMXPath($dom);
        self::assertSame(1, $xpath->query('//*[@id="two-factor-login-methods"]//a[contains(@href,"auth/wecom/login")]')->length);
        self::assertSame(0, $xpath->query('//*[@id="wecom-oauth-providers"]')->length);
        self::assertSame(1, $xpath->query('//*[@id="two-factor-login-methods"]//button[@data-passkey-purpose="login"]')->length);
    }

    public function testThirdPartyLoginAlsoWaitsForSecondStepAndLogsOnlyOnCompletion(): void
    {
        $this->enablePlugin('login-history');
        $user = $this->user();
        $secret = $this->device($user);
        Route::middleware('web')->get('test/oauth/callback', function () use ($user) {
            request()->attributes->set('login_history.web_channel', 'oauth:github');
            Auth::login($user);

            return redirect('user');
        });
        $this->get('/test/oauth/callback')->assertRedirect('/auth/two-factor');
        $this->assertGuest();
        self::assertSame(0, DB::table('login_history_entries')->where('user_id', $user->uid)->count());
        $this->post('/auth/two-factor/code', ['current_password' => $this->code($secret)])->assertRedirect('/user');
        self::assertSame(1, DB::table('login_history_entries')->where('user_id', $user->uid)->count());
        $this->assertDatabaseHas('login_history_entries', ['user_id' => $user->uid, 'login_channel' => 'oauth:github']);
    }

    public function testRememberMeRequiresAnotherFactorBeforeLoginEvent(): void
    {
        $user = $this->user();
        $this->device($user);
        $token = bin2hex(random_bytes(30));
        User::where('uid', $user->uid)->update(['remember_token' => $token]);
        $events = [];
        app('events')->listen(Illuminate\Auth\Events\Login::class, function ($event) use (&$events) {$events[] = $event; });
        $cookie = Auth::guard('web')->getRecallerName();
        $this->withCookie($cookie, $user->uid.'|'.$token.'|'.$user->password)->get('/user')->assertRedirect('/auth/two-factor');
        $this->assertGuest();
        self::assertCount(0, $events);
    }

    public function testRoutesEnforceCsrfOutsideUnitTestEnvironment(): void
    {
        app()->instance('env', 'production');
        $this->postJson('/auth/two-factor/passkey/options', ['purpose' => 'login'])->assertStatus(419);
    }

    public function testInvalidCodeIsThrottledAndDeviceLimitsAreEnforced(): void
    {
        $user = $this->user();
        $this->manage($user);
        for ($i = 0; $i < 5; $i++) {
            $this->post('/user/two-factor/confirm', ['current_password' => 'bad'])->assertRedirect();
        }
        $this->post('/user/two-factor/confirm', ['current_password' => 'bad'])->assertStatus(429);
        $this->settings(['max_totp' => 1]);
        $this->device($user);
        app(Accounts::class)->mark((int) $user->uid);
        $this->post('/user/two-factor/totp/start', ['name' => '第二台'])->assertSessionHas('two_factor_error');
    }

    public function testResetRevokesOldSessionAndRecoveryCodes(): void
    {
        $user = $this->user();
        $this->device($user);
        $this->manage($user);
        $codes = app(Accounts::class)->recovery((int) $user->uid);
        app(Accounts::class)->reset((int) $user->uid);
        $this->get('/user')->assertRedirect('/auth/login');
        self::assertFalse(app(Accounts::class)->proof((int) $user->uid, $codes[0]));
        self::assertFalse(app(Accounts::class)->hasFactors((int) $user->uid));
    }

    public function testCompletionKeepsLocalIntendedUrlAndRejectsExternalRedirects(): void
    {
        $flow = app(Blessing\TwoFactor\Flow::class);
        self::assertSame('/oauth/authorize?client_id=1', $flow->target(url('/oauth/authorize?client_id=1')));
        self::assertSame('/user/profile', $flow->target('/user/profile'));
        foreach (['//evil.example', 'https://evil.example/user', '/%2fexample.com', '/%5cevil.example', "/user\r\nLocation: https://evil.example"] as $target) {
            self::assertSame('/user', $flow->target($target));
        }
    }
}
