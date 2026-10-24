<?php

declare(strict_types=1);

use App\Events\ConfigureRoutes;
use App\Models\User;
use App\Services\Plugin;
use App\Services\PluginManager;
use Blessing\WeCom\Bindings;
use Blessing\WeCom\Installer;
use Blessing\WeCom\Settings;
use Blessing\WeCom\WeComClient;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

final class StrictCsrf extends VerifyCsrfToken
{
    protected function runningUnitTests()
    {
        return false;
    }
}

final class WeComTest extends Tests\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        $path = dirname(__DIR__);
        $plugin = new Plugin($path, json_decode(file_get_contents($path.'/package.json'), true));
        $manager = app(PluginManager::class);
        $manager->all()->put('wecom-login', $plugin);
        self::assertTrue($manager->enable($plugin));
        $booted = new ReflectionProperty($manager, 'booted');
        $booted->setValue($manager, false);
        $manager->boot();
        event(new ConfigureRoutes(app('router')));
        app('router')->getRoutes()->refreshNameLookups();
        config(['wecom' => [
            'corp_id' => 'wwTestCorp', 'agent_id' => '1000001',
            'redirect_uri' => 'https://skin.example.test/auth/wecom/callback',
            'secret' => 'private-test-secret-never-render',
        ]]);
    }

    public function testEnabledPluginBootRepairsMissingSecondTable(): void
    {
        $firstSchema = Schema::getColumnListing(Installer::BINDINGS);
        Schema::drop(Installer::STATES);
        $manager = app(PluginManager::class);
        // enable is a no-op for an already-enabled plugin: bootstrap must repair it.
        self::assertTrue($manager->enable('wecom-login'));
        self::assertFalse(Schema::hasTable(Installer::STATES));
        $booted = new ReflectionProperty($manager, 'booted');
        $booted->setValue($manager, false);
        $manager->boot();
        self::assertTrue(Schema::hasTable(Installer::STATES));
        self::assertSame($firstSchema, Schema::getColumnListing(Installer::BINDINGS));
        Installer::install();
        self::assertTrue(Schema::hasTable(Installer::STATES));
    }

    public function testSchemaDoesNotRequireForeignKeyToHostUsers(): void
    {
        $keys = DB::select('PRAGMA foreign_key_list('.Installer::BINDINGS.')');
        self::assertCount(0, $keys);
        $keys = DB::select('PRAGMA foreign_key_list('.Installer::STATES.')');
        self::assertCount(0, $keys);
    }

    public function testInstallerRepairsIndexesLeftByInterruptedDdl(): void
    {
        $user = $this->user();
        app(Bindings::class)->bind($user, $this->member());
        Schema::table(Installer::BINDINGS, function ($table): void {
            $table->dropUnique(['user_id']);
            $table->dropUnique(['identity_hash']);
        });
        Schema::table(Installer::STATES, function ($table): void {
            $table->dropIndex(['session_hash']);
            $table->dropIndex(['expires_at']);
        });
        Installer::install();
        self::assertTrue(Schema::hasIndex(Installer::BINDINGS, ['user_id'], 'unique'));
        self::assertTrue(Schema::hasIndex(Installer::BINDINGS, ['identity_hash'], 'unique'));
        self::assertTrue(Schema::hasIndex(Installer::STATES, ['state_hash'], 'unique'));
        self::assertTrue(Schema::hasIndex(Installer::STATES, ['session_hash']));
        self::assertTrue(Schema::hasIndex(Installer::STATES, ['expires_at']));
        $this->assertDatabaseHas(Installer::BINDINGS, ['user_id' => $user->uid]);
    }

    public function testApiDiagnosticsContainOnlySafeMetadata(): void
    {
        \Illuminate\Support\Facades\Log::spy();
        $this->mockMember([], ['errcode' => 60111, 'errmsg' => 'sensitive-code-token-url']);
        $state = $this->start($this->user());
        $this->oauthCallback($state)->assertRedirect()->assertSessionHas('wecom_error');
        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->once()->with(
            'wecom-login: api_error', ['endpoint' => 'user/get', 'http_status' => 200, 'errcode' => 60111]
        );
    }

    public function testTransportFailureDiagnosticsDoNotLogExceptionUrl(): void
    {
        \Illuminate\Support\Facades\Log::spy();
        Http::fake(fn () => throw new \RuntimeException('https://example.invalid/?corpsecret=never-log-this'));
        $state = $this->start($this->user());
        $this->oauthCallback($state)->assertRedirect()->assertSessionHas('wecom_error');
        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->once()->with(
            'wecom-login: transport_or_invalid_response', ['endpoint' => 'gettoken', 'http_status' => null, 'errcode' => null]
        );
    }

    private function notificationBinding(User $user, bool $optIn = true): void
    {
        app(Bindings::class)->bind($user, $this->member());
        DB::table(Installer::BINDINGS)->where('user_id', $user->uid)->update(['notify_login' => $optIn]);
    }

    private function notificationApi(array $response = ['errcode' => 0]): void
    {
        Http::fake([
            'https://qyapi.weixin.qq.com/cgi-bin/gettoken*' => Http::response(['errcode' => 0, 'access_token' => 'notify-token-secret', 'expires_in' => 7200]),
            'https://qyapi.weixin.qq.com/cgi-bin/message/send*' => Http::response($response),
        ]);
    }

    public function testNotificationsDefaultOffForExistingBindings(): void
    {
        $user = $this->user();
        app(Bindings::class)->bind($user, $this->member());
        self::assertFalse(app(Settings::class)->notificationsEnabled());
        self::assertSame(0, (int) app(Bindings::class)->forUser($user->uid)->notify_login);
        event(new \Illuminate\Auth\Events\Login('web', $user, false));
        app()->terminate();
        Http::assertNothingSent();
    }

    public function testUserCanChangeOnlyTheirNotificationPreference(): void
    {
        $user = $this->user();$other = $this->user();
        $this->notificationBinding($user, false);
        app(Bindings::class)->bind($other, array_merge($this->member(), ['member_id' => 'other-member']));
        $this->actingAs($user)->post('/user/wecom/notifications', ['notify_login' => '1', 'user_id' => $other->uid])->assertRedirect('/user/wecom');
        self::assertSame(1, (int) app(Bindings::class)->forUser($user->uid)->notify_login);
        self::assertSame(0, (int) app(Bindings::class)->forUser($other->uid)->notify_login);
        $this->post('/user/wecom/notifications', ['notify_login' => '0'])->assertRedirect();
        self::assertSame(0, (int) app(Bindings::class)->forUser($user->uid)->notify_login);
    }

    public function testNotificationPreferenceRequiresAuthenticationAndCsrf(): void
    {
        $this->post('/user/wecom/notifications', ['notify_login' => 1])->assertRedirect('/auth/login');
        $user = $this->user();$this->notificationBinding($user, false);
        $this->app->bind(VerifyCsrfToken::class, StrictCsrf::class);
        $this->actingAs($user)->withSession(['_token' => 'correct-token']);
        $this->post('/user/wecom/notifications', ['notify_login' => 1])->assertStatus(419);
        self::assertSame(0, (int) app(Bindings::class)->forUser($user->uid)->notify_login);
    }

    public function testNotificationUsesCurrentAppAndSendsAfterResponseOnlyOnce(): void
    {
        option(['wecom_login_notifications_enabled' => '1']);
        $user = $this->user();$this->notificationBinding($user);$this->notificationApi();
        $event = new \Illuminate\Auth\Events\Login('web', $user, false);
        event($event);event($event);
        Http::assertNothingSent();
        app()->terminate();app()->terminate();
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/message/send?access_token=notify-token-secret')
            && $request['touser'] === 'zhangsan' && $request['agentid'] === 1000001
            && $request['msgtype'] === 'textcard' && str_contains($request['textcard']['description'], 'UID '.$user->uid)
            && !str_contains($request['textcard']['description'], 'wwTestCorp'));
    }

    public function testPasswordLoginWithRememberCheckedStillNotifies(): void
    {
        option(['wecom_login_notifications_enabled' => '1']);
        $user = $this->user();$this->notificationBinding($user);$this->notificationApi();
        $user->changePassword('Known-password-123');
        $this->post('/auth/login', ['identification' => $user->email, 'password' => 'Known-password-123', 'keep' => true])->assertSuccessful();
        Http::assertSent(fn ($request) => str_contains($request->url(), '/message/send'));
    }

    public function testUserOptOutSuppressesNotifications(): void
    {
        option(['wecom_login_notifications_enabled' => '1']);
        $user = $this->user();$this->notificationBinding($user, false);
        event(new \Illuminate\Auth\Events\Login('web', $user, false));app()->terminate();
        Http::assertNothingSent();
    }

    public function testRevokedPreferenceBeforeDeliverySuppressesNotification(): void
    {
        option(['wecom_login_notifications_enabled' => '1']);
        $user = $this->user();$this->notificationBinding($user);
        event(new \Illuminate\Auth\Events\Login('web', $user, false));
        DB::table(Installer::BINDINGS)->where('user_id', $user->uid)->update(['notify_login' => false]);
        app()->terminate();Http::assertNothingSent();
    }

    public function testNotificationFailureDoesNotFailLogin(): void
    {
        option(['wecom_login_notifications_enabled' => '1']);
        $user = $this->user();$this->notificationBinding($user);$this->notificationApi(['errcode' => 81013]);
        $user->changePassword('Known-password-123');
        $this->post('/auth/login', ['identification' => $user->email, 'password' => 'Known-password-123'])->assertSuccessful();
        $this->assertAuthenticatedAs($user);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/message/send'));
    }

    public function testNotificationTokenExpiryRefreshesAndRetries(): void
    {
        $calls = 0;
        Http::fake([
            'https://qyapi.weixin.qq.com/cgi-bin/gettoken*' => Http::response(['errcode' => 0, 'access_token' => 'refreshed-token', 'expires_in' => 7200]),
            'https://qyapi.weixin.qq.com/cgi-bin/message/send*' => function () use (&$calls) {return Http::response(['errcode' => ++$calls === 1 ? 42001 : 0]);},
        ]);
        app(WeComClient::class)->sendLoginMessage('zhangsan', 'login', app(Settings::class)->requireValid());
        self::assertSame(2, $calls);Http::assertSentCount(4);
    }

    public function testNotificationCannotBroadcast(): void
    {
        try {
            app(WeComClient::class)->sendLoginMessage('@all', 'login', app(Settings::class)->requireValid());
            self::fail('Broadcast should be rejected.');
        } catch (\Blessing\WeCom\Failure $error) {self::assertSame(422, $error->status);}
        Http::assertNothingSent();
    }

    public function testAdminCanEnableNotificationMasterSwitch(): void
    {
        $admin = $this->user(['permission' => User::ADMIN]);
        $this->actingAs($admin)->post('/admin/wecom/config', [
            'corp_id' => 'wwTestCorp', 'agent_id' => '1000001',
            'redirect_uri' => 'https://skin.example.test/auth/wecom/callback', 'login_notifications_enabled' => 1,
        ])->assertRedirect();
        self::assertTrue(app(Settings::class)->notificationsEnabled());
        $this->get('/admin/plugins/config/wecom-login')->assertOk()->assertSee('允许发送网页登录通知');
    }

    public function testMasterSwitchSuppressesOptedInUser(): void
    {
        $user = $this->user();$this->notificationBinding($user);
        event(new \Illuminate\Auth\Events\Login('web', $user, false));
        app()->terminate();Http::assertNothingSent();
    }

    public function testRememberCookieRestorationDoesNotNotify(): void
    {
        option(['wecom_login_notifications_enabled' => '1']);
        $user = $this->user();$this->notificationBinding($user);
        $guard = Auth::guard('web');
        (new ReflectionProperty($guard, 'viaRemember'))->setValue($guard, true);
        event(new \Illuminate\Auth\Events\Login('web', $user, true));
        app()->terminate();Http::assertNothingSent();
    }

    public function testYggdrasilRequestDoesNotSendWebNotification(): void
    {
        option(['wecom_login_notifications_enabled' => '1']);
        $user = $this->user();$this->notificationBinding($user);
        $this->app->instance('request', \Illuminate\Http\Request::create('/api/yggdrasil/authserver/authenticate', 'POST'));
        event(new \Illuminate\Auth\Events\Login('web', $user, false));
        app()->terminate();Http::assertNothingSent();
    }

    public function testWeComLoginSendsOneLoginNotification(): void
    {
        option(['wecom_login_notifications_enabled' => '1']);
        $user = $this->user();$this->notificationBinding($user);$this->mockMember();
        Http::fake(['https://qyapi.weixin.qq.com/cgi-bin/message/send*' => Http::response(['errcode' => 0])]);
        $state = $this->start();
        $this->oauthCallback($state)->assertRedirect('/user');
        $this->assertAuthenticatedAs($user);
        $messages = Http::recorded(fn ($request) => str_contains($request->url(), '/message/send'));
        self::assertCount(1, $messages);
        self::assertStringContainsString('企业微信扫码', $messages->first()[0]['textcard']['description']);
    }

    public function testUnbindErrorStaysInsideAccountPageAndPasswordIsNotFlashed(): void
    {
        $user = $this->user();$user->changePassword('Known-password-123');
        $this->notificationBinding($user);
        $response = $this->actingAs($user)->post('/user/wecom/unbind', ['password' => 'wrong-password-never-flash']);
        $response->assertRedirect('/user/wecom')->assertSessionHas('wecom_error');
        self::assertStringNotContainsString('wrong-password-never-flash', json_encode(session()->all()));
        $this->get('/user/wecom')->assertOk()->assertSee('alert alert-danger', false)
            ->assertSee('皮肤站密码验证失败')->assertSee('保存通知设置')->assertSee('验证密码并解绑')
            ->assertDontSee('wrong-password-never-flash')->assertDontSee('企业微信授权提示');
        $this->assertDatabaseCount(Installer::BINDINGS, 1);
    }

    public function testEmptyUnbindPasswordIsShownOnBindingPage(): void
    {
        $user = $this->user();$this->notificationBinding($user);
        $this->actingAs($user)->post('/user/wecom/unbind', [])->assertRedirect('/user/wecom')->assertSessionHas('wecom_error');
        $this->get('/user/wecom')->assertOk()->assertSee('请输入皮肤站密码')->assertSee('登录通知推送时机');
        $this->assertDatabaseCount(Installer::BINDINGS, 1);
    }

    public function testGuestAuthorizationErrorAppearsInsideSiteLoginPage(): void
    {
        config(['wecom.secret' => '']);
        $this->get('/auth/wecom/login')->assertRedirect('/auth/login')->assertSessionHas('wecom_error');
        $this->get('/auth/login')->assertOk()->assertSee('企业微信登录尚未配置完成')
            ->assertSee('login-card-body', false)->assertDontSee('企业微信授权提示');
    }

    public function testConfigErrorStaysInAdminPageAndPreservesOnlyPublicInput(): void
    {
        $this->actingAs($this->user(['permission' => User::ADMIN]))->post('/admin/wecom/config', [
            'corp_id' => 'wwRetainedCorp', 'agent_id' => '1000012',
            'redirect_uri' => 'http://invalid.example.test/auth/wecom/callback',
            'secret' => 'never-flash-this-secret', 'login_notifications_enabled' => 1,
        ])->assertRedirect('/admin/plugins/config/wecom-login')->assertSessionHas('wecom_error');
        self::assertStringNotContainsString('never-flash-this-secret', json_encode(session()->all()));
        $this->get('/admin/plugins/config/wecom-login')->assertOk()->assertSee('alert alert-danger', false)
            ->assertSee('wwRetainedCorp')->assertSee('1000012')->assertSee('保存配置')->assertDontSee('never-flash-this-secret');
        self::assertSame('wwTestCorp', app(Settings::class)->values()['corp_id']);
    }

    public function testLoginCardEscapesMarkupAndUsesConfiguredAccountUrl(): void
    {
        $this->notificationApi();
        app(WeComClient::class)->sendLoginMessage('zhangsan', "登录成功\n<script>alert(1)</script>&安全", app(Settings::class)->requireValid());
        Http::assertSent(fn ($request) => str_contains($request->url(), '/message/send')
            && $request['msgtype'] === 'textcard' && !isset($request['text'])
            && $request['textcard']['url'] === 'https://skin.example.test/user/wecom'
            && $request['textcard']['btntxt'] === '查看账号'
            && str_contains($request['textcard']['description'], '<br>&lt;script&gt;')
            && !str_contains($request['textcard']['description'], '<script>'));
    }

    public function testLoginCardTruncatesWithoutSplittingUnicodeOrEntities(): void
    {
        $this->notificationApi();
        app(WeComClient::class)->sendLoginMessage('zhangsan', str_repeat('中&', 500), app(Settings::class)->requireValid());
        $messages = Http::recorded(fn ($request) => str_contains($request->url(), '/message/send'));
        $description = $messages->first()[0]['textcard']['description'];
        self::assertLessThanOrEqual(512, strlen($description));
        self::assertTrue(mb_check_encoding($description, 'UTF-8'));
        self::assertStringEndsWith('…', $description);
        self::assertDoesNotMatchRegularExpression('/&[^;]*…$/u', $description);
    }

    public function testUserCanSelectThreeModesAndCannotSelectAnotherUser(): void
    {
        $user = $this->user();$this->notificationBinding($user, false);
        foreach (['strict', 'relaxed', 'off'] as $mode) {
            $this->actingAs($user)->post('/user/wecom/notifications', ['notification_mode' => $mode, 'user_id' => 99999])->assertRedirect('/user/wecom');
            self::assertSame($mode, app(Bindings::class)->forUser($user->uid)->notification_mode);
        }
        $this->get('/user/wecom')->assertOk()->assertSee('严格：')->assertSee('宽松：')->assertSee('关闭：');
        $this->post('/user/wecom/notifications', ['notification_mode' => 'invalid'])->assertRedirect('/user/wecom')->assertSessionHas('wecom_error');
        self::assertSame('off', app(Bindings::class)->forUser($user->uid)->notification_mode);
    }

    public function testOldBooleanPreferenceMigratesToStrictOrOffWithoutDataLoss(): void
    {
        $user = $this->user();$this->notificationBinding($user);
        Installer::install();
        self::assertSame('strict', app(Bindings::class)->forUser($user->uid)->notification_mode);
        self::assertSame('zhangsan', app(Bindings::class)->forUser($user->uid)->member_id);
    }

    public function testRelaxedChinaModeSendsForDifferentCityEvenNearby(): void
    {
        $binding = (object) ['notification_mode' => 'relaxed', 'notification_baseline' => json_encode(['country' => 'CN', 'city' => '广州', 'latitude' => 23.129, 'longitude' => 113.264])];
        $geo = Mockery::mock(\Blessing\WeCom\IpLocation::class);
        $geo->shouldReceive('verified')->once()->andReturn(['country' => 'CN', 'city' => '佛山', 'latitude' => 23.021, 'longitude' => 113.121]);
        $this->app->instance(\Blessing\WeCom\IpLocation::class, $geo);
        $result = app(\Blessing\WeCom\NotificationPolicy::class)->decide($binding, '8.8.8.8');
        self::assertTrue($result['send']);
    }

    public function testRelaxedChinaModeSuppressesSameCityDespiteChangedIp(): void
    {
        $binding = (object) ['notification_mode' => 'relaxed', 'notification_baseline' => json_encode(['country' => 'CN', 'city' => '广州', 'latitude' => 23.129, 'longitude' => 113.264])];
        $geo = Mockery::mock(\Blessing\WeCom\IpLocation::class);
        $geo->shouldReceive('verified')->once()->andReturn(['country' => 'CN', 'city' => '广州', 'latitude' => 23.15, 'longitude' => 113.30]);
        $this->app->instance(\Blessing\WeCom\IpLocation::class, $geo);
        self::assertFalse(app(\Blessing\WeCom\NotificationPolicy::class)->decide($binding, '8.8.4.4')['send']);
    }

    public function testRelaxedFirstLoginAndUncertainLocationAlwaysNotify(): void
    {
        $binding = (object) ['notification_mode' => 'relaxed', 'notification_baseline' => null];
        $geo = Mockery::mock(\Blessing\WeCom\IpLocation::class);
        $geo->shouldReceive('verified')->once()->andReturn(['country' => 'CN', 'city' => '广州', 'latitude' => 23.129, 'longitude' => 113.264]);
        $geo->shouldReceive('verified')->once()->andReturn(null);
        $this->app->instance(\Blessing\WeCom\IpLocation::class, $geo);
        $policy = app(\Blessing\WeCom\NotificationPolicy::class);
        self::assertTrue($policy->decide($binding, '8.8.8.8')['send']);
        $uncertain = $policy->decide($binding, '8.8.4.4');
        self::assertTrue($uncertain['send']);self::assertNull($uncertain['point']);
    }

    public function testRelaxedForeignDistanceAndCountryRules(): void
    {
        $binding = (object) ['notification_mode' => 'relaxed', 'notification_baseline' => json_encode(['country' => 'GB', 'city' => 'London', 'latitude' => 51.51, 'longitude' => -0.09])];
        $geo = Mockery::mock(\Blessing\WeCom\IpLocation::class);
        $geo->shouldReceive('verified')->once()->andReturn(['country' => 'GB', 'city' => 'Manchester', 'latitude' => 53.48, 'longitude' => -2.24]);
        $geo->shouldReceive('verified')->once()->andReturn(['country' => 'FR', 'city' => 'Paris', 'latitude' => 48.85, 'longitude' => 2.35]);
        $this->app->instance(\Blessing\WeCom\IpLocation::class, $geo);
        $policy = app(\Blessing\WeCom\NotificationPolicy::class);
        self::assertFalse($policy->decide($binding, '8.8.8.8')['send']);
        self::assertTrue($policy->decide($binding, '8.8.4.4')['send']);
    }

    public function testLocalMmdbIsIndependentlyVerifiedOnlineAndCached(): void
    {
        $path = dirname(__DIR__).'/tests/fixtures/GeoIP2-City-Test.mmdb';
        $geo = new class($path) extends \Blessing\WeCom\IpLocation {
            public function __construct(private string $path) {}
            public function databasePath(): string {return $this->path;}
        };
        Http::fake(['https://ipwho.is/*' => Http::response(['success' => true, 'ip' => '81.2.69.142', 'country_code' => 'GB', 'latitude' => 51.5142, 'longitude' => -0.0931, 'city' => 'London'])]);
        $point = $geo->verified('81.2.69.142');
        self::assertSame('GB', $point['country']);self::assertSame('伦敦', $point['city']);
        self::assertNotNull($geo->verified('81.2.69.142'));Http::assertSentCount(1);
        $this->assertDatabaseCount(Installer::GEO_CACHE, 1);
        self::assertStringNotContainsString('81.2.69.142', json_encode(DB::table(Installer::GEO_CACHE)->get()));
    }

    public function testPrivateIpIsNeverSentToOnlineService(): void
    {
        foreach (['192.168.1.1', '100.64.0.1', '192.0.2.1', '2001:db8::1', '::ffff:127.0.0.1'] as $ip) {
            self::assertNull(app(\Blessing\WeCom\IpLocation::class)->verified($ip));
        }
        Http::assertNothingSent();
    }

    public function testFailedRelaxedPushDoesNotAdvanceBaseline(): void
    {
        option(['wecom_login_notifications_enabled' => '1']);
        $user = $this->user();$this->notificationBinding($user);
        DB::table(Installer::BINDINGS)->where('user_id', $user->uid)->update(['notification_mode' => 'relaxed']);
        $geo = Mockery::mock(\Blessing\WeCom\IpLocation::class);
        $geo->shouldReceive('verified')->once()->andReturn(['country' => 'CN', 'city' => '广州', 'latitude' => 23.129, 'longitude' => 113.264]);
        $this->app->instance(\Blessing\WeCom\IpLocation::class, $geo);
        $this->notificationApi(['errcode' => 81013]);
        event(new \Illuminate\Auth\Events\Login('web', $user, false));app()->terminate();
        self::assertNull(app(Bindings::class)->forUser($user->uid)->notification_baseline);
    }

    private function actualTestGeo(): \Blessing\WeCom\IpLocation
    {
        return new class(dirname(__DIR__).'/tests/fixtures/GeoIP2-City-Test.mmdb') extends \Blessing\WeCom\IpLocation {
            public function __construct(private string $path) {}
            public function databasePath(): string {return $this->path;}
        };
    }

    public function testChinaCityRequiresOnlineCityAgreementWithRealLocalDatabase(): void
    {
        Http::fake(['https://ipwho.is/*' => Http::response(['success' => true, 'ip' => '175.16.199.1', 'country_code' => 'CN', 'city' => 'Changchun', 'latitude' => 43.88, 'longitude' => 125.3228])]);
        $point = $this->actualTestGeo()->verified('175.16.199.1');
        self::assertSame('长春', $point['city']);self::assertSame('CN', $point['country']);
    }

    public function testChinaOnlineCityMismatchIsUncertainEvenWithNearbyCoordinates(): void
    {
        Http::fake(['https://ipwho.is/*' => Http::response(['success' => true, 'ip' => '175.16.199.1', 'country_code' => 'CN', 'city' => 'Another City', 'latitude' => 43.88, 'longitude' => 125.3228])]);
        self::assertNull($this->actualTestGeo()->verified('175.16.199.1'));
    }

    public function testOnlineRateLimitIsUncertainAndUsesCooldown(): void
    {
        Http::fake(['https://ipwho.is/*' => Http::response([], 429)]);
        $geo = $this->actualTestGeo();
        self::assertNull($geo->verified('175.16.199.1'));
        self::assertNull($geo->verified('81.2.69.142'));
        Http::assertSentCount(1);
    }

    public function testRelaxedSameCityDoesNotCallMessageApiAndUpdatesBaseline(): void
    {
        option(['wecom_login_notifications_enabled' => '1']);
        $user = $this->user();$this->notificationBinding($user);
        DB::table(Installer::BINDINGS)->where('user_id', $user->uid)->update(['notification_mode' => 'relaxed',
            'notification_baseline' => json_encode(['country' => 'CN', 'city' => '广州', 'latitude' => 23.129, 'longitude' => 113.264])]);
        $geo = Mockery::mock(\Blessing\WeCom\IpLocation::class);
        $geo->shouldReceive('verified')->once()->andReturn(['country' => 'CN', 'city' => '广州', 'latitude' => 23.15, 'longitude' => 113.30]);
        $this->app->instance(\Blessing\WeCom\IpLocation::class, $geo);
        event(new \Illuminate\Auth\Events\Login('web', $user, false));app()->terminate();
        Http::assertNothingSent();
        self::assertEquals(23.15, json_decode(app(Bindings::class)->forUser($user->uid)->notification_baseline, true)['latitude']);
    }

    private function user(array $values = []): User
    {
        return User::factory()->create($values);
    }

    private function mockMember(array $identity = [], array $member = []): void
    {
        Http::fake([
            'https://qyapi.weixin.qq.com/cgi-bin/gettoken*' => Http::response([
                'errcode' => 0, 'access_token' => 'test-access-token', 'expires_in' => 7200,
            ]),
            'https://qyapi.weixin.qq.com/cgi-bin/auth/getuserinfo*' => Http::response(array_merge([
                'errcode' => 0, 'userid' => 'zhangsan',
            ], $identity)),
            'https://qyapi.weixin.qq.com/cgi-bin/user/get*' => Http::response(array_merge([
                'errcode' => 0, 'userid' => 'zhangsan', 'name' => '张三', 'enable' => 1, 'status' => 1,
            ], $member)),
        ]);
    }

    private function start(?User $user = null): string
    {
        $this->withSession(['_token' => 'csrf-test-token']);
        if ($user) {
            $this->actingAs($user);
            $response = $this->post('/user/wecom/bind', ['_token' => 'csrf-test-token']);
        } else {
            Auth::logout();
            $response = $this->get('/auth/wecom/login');
        }
        $response->assertRedirect();
        $url = $response->headers->get('Location');
        self::assertStringStartsWith('https://open.work.weixin.qq.com/wwopen/sso/qrConnect?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame('wwTestCorp', $query['appid']);
        self::assertSame('1000001', $query['agentid']);
        self::assertSame('https://skin.example.test/auth/wecom/callback', $query['redirect_uri']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $query['state']);
        return $query['state'];
    }

    private function oauthCallback(string $state, string $code = 'single-use-code')
    {
        return $this->get('/auth/wecom/callback?'.http_build_query(compact('state', 'code')));
    }

    private function member(): array
    {
        return ['corp_id' => 'wwTestCorp', 'member_id' => 'zhangsan', 'member_name' => '张三'];
    }

    public function testEnableCreatesTablesIdempotently(): void
    {
        Installer::install();
        self::assertTrue(Schema::hasTable(Installer::BINDINGS));
        self::assertTrue(Schema::hasTable(Installer::STATES));
        $this->assertDatabaseCount(Installer::BINDINGS, 0);
    }

    public function testLoginButtonAndAccountPageRender(): void
    {
        $this->get('/auth/login')->assertOk()->assertSee('企业微信扫码登录');
        $this->actingAs($this->user())->get('/user/wecom')->assertOk()->assertSee('扫码绑定企业微信');
    }

    public function testUnconfiguredLoginDoesNotCallApi(): void
    {
        config(['wecom.secret' => '']);
        $this->get('/auth/login')->assertOk()->assertDontSee('企业微信扫码登录');
        $this->get('/auth/wecom/login')->assertRedirect('/auth/login')->assertSessionHas('wecom_error');
        Http::assertNothingSent();
    }

    public function testAlreadyLoggedInGoesToBindingPage(): void
    {
        $this->actingAs($this->user())->get('/auth/wecom/login')->assertRedirect('/user/wecom');
    }

    public function testGuestCannotStartBindingOrUnbind(): void
    {
        $this->post('/user/wecom/bind')->assertRedirect('/auth/login');
        $this->post('/user/wecom/unbind', ['password' => 'x'])->assertRedirect('/auth/login');
    }

    public function testUserCanSelfBindWithoutChangingAccount(): void
    {
        $user = $this->user();
        $this->mockMember();
        $state = $this->start($user);
        $this->oauthCallback($state)->assertRedirect('/user/wecom');
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas(Installer::BINDINGS, ['user_id' => $user->uid, 'member_id' => 'zhangsan']);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount(Installer::STATES, 0);
    }

    public function testBoundUserCanLogInAndSessionRotates(): void
    {
        $user = $this->user();
        app(Bindings::class)->bind($user, $this->member());
        $this->mockMember();
        $state = $this->start();
        $oldId = session()->getId();
        $response = $this->oauthCallback($state);
        $response->assertRedirect('/user');
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        self::assertNotSame($oldId, session()->getId());
        $this->assertAuthenticatedAs($user);
    }

    public function testUnboundIdentityDoesNotRegisterOrMatchEmail(): void
    {
        $this->user(['email' => 'zhangsan@example.test']);
        $this->mockMember([], ['email' => 'zhangsan@example.test']);
        $state = $this->start();
        $this->oauthCallback($state)->assertRedirect()->assertSessionHas('wecom_error', fn ($message) => str_contains($message, '尚未绑定'));
        $this->assertGuest();
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount(Installer::BINDINGS, 0);
    }

    public function testInvalidStateStopsBeforeApi(): void
    {
        $this->oauthCallback(str_repeat('a', 64))->assertRedirect()->assertSessionHas('wecom_error');
        Http::assertNothingSent();
    }

    public function testMissingOrArrayStateRejected(): void
    {
        $this->get('/auth/wecom/callback?code=x')->assertRedirect()->assertSessionHas('wecom_error');
        $this->get('/auth/wecom/callback?state[]=x&code=x')->assertRedirect()->assertSessionHas('wecom_error');
        Http::assertNothingSent();
    }

    public function testOtherSessionCannotUseState(): void
    {
        $state = $this->start();
        $this->withSession(['wecom' => ['session_key' => str_repeat('b', 64)]]);
        $this->oauthCallback($state)->assertRedirect()->assertSessionHas('wecom_error');
        $this->assertDatabaseCount(Installer::STATES, 1);
        Http::assertNothingSent();
    }

    public function testExpiredStateRejectedBeforeApi(): void
    {
        $state = $this->start();
        DB::table(Installer::STATES)->update(['expires_at' => time() - 1]);
        $this->oauthCallback($state)->assertRedirect()->assertSessionHas('wecom_error');
        $this->assertDatabaseCount(Installer::STATES, 0);
        Http::assertNothingSent();
    }

    public function testStateCannotBeReplayed(): void
    {
        $this->mockMember();
        $state = $this->start($this->user());
        $this->oauthCallback($state)->assertRedirect('/user/wecom');
        $this->oauthCallback($state)->assertRedirect()->assertSessionHas('wecom_error');
        Http::assertSentCount(3);
    }

    public function testNewAttemptInvalidatesOldState(): void
    {
        $first = $this->start();
        $second = $this->start();
        self::assertNotSame($first, $second);
        $this->oauthCallback($first)->assertRedirect()->assertSessionHas('wecom_error');
        Http::assertNothingSent();
    }

    public function testSettingsChangeInvalidatesAuthorization(): void
    {
        $state = $this->start();
        config(['wecom.secret' => 'changed-secret']);
        $this->oauthCallback($state)->assertRedirect()->assertSessionHas('wecom_error');
        Http::assertNothingSent();
    }

    public function testSwitchingAccountDuringBindingRejected(): void
    {
        $state = $this->start($this->user());
        $this->actingAs($this->user());
        $this->oauthCallback($state)->assertRedirect()->assertSessionHas('wecom_error');
        Http::assertNothingSent();
    }

    public function testLoginAttemptCannotBecomeABindingAttempt(): void
    {
        $state = $this->start();
        $this->actingAs($this->user());
        $this->oauthCallback($state)->assertRedirect()->assertSessionHas('wecom_error');
        Http::assertNothingSent();
    }

    public function testMissingCodeConsumesState(): void
    {
        $state = $this->start();
        $this->get('/auth/wecom/callback?state='.$state)->assertRedirect()->assertSessionHas('wecom_error');
        $this->oauthCallback($state)->assertRedirect()->assertSessionHas('wecom_error');
        Http::assertNothingSent();
    }

    public function testExternalContactRejected(): void
    {
        $this->mockMember(['userid' => null, 'openid' => 'external-contact']);
        $state = $this->start($this->user());
        $this->oauthCallback($state)->assertRedirect()->assertSessionHas('wecom_error', fn ($message) => str_contains($message, '内部成员'));
        $this->assertDatabaseCount(Installer::BINDINGS, 0);
        Http::assertSentCount(2);
    }

    public function testDisabledMemberRejected(): void
    {
        $this->mockMember([], ['enable' => 0]);
        $state = $this->start($this->user());
        $this->oauthCallback($state)->assertRedirect()->assertSessionHas('wecom_error');
        $this->assertDatabaseCount(Installer::BINDINGS, 0);
    }

    public function testInactiveMemberRejected(): void
    {
        $this->mockMember([], ['status' => 4]);
        $state = $this->start($this->user());
        $this->oauthCallback($state)->assertRedirect()->assertSessionHas('wecom_error');
    }

    public function testMemberOutsideAppScopeRejected(): void
    {
        $this->mockMember([], ['errcode' => 60111]);
        $state = $this->start($this->user());
        $this->oauthCallback($state)->assertRedirect()->assertSessionHas('wecom_error');
    }

    public function testMismatchedMemberIdentityRejected(): void
    {
        $this->mockMember([], ['userid' => 'lisi']);
        $state = $this->start($this->user());
        $this->oauthCallback($state)->assertRedirect()->assertSessionHas('wecom_error');
    }

    public function testIdentityCannotBeBoundToTwoUsers(): void
    {
        $owner = $this->user();
        app(Bindings::class)->bind($owner, $this->member());
        $this->mockMember();
        $state = $this->start($this->user());
        $this->oauthCallback($state)->assertRedirect()->assertSessionHas('wecom_error');
        $this->assertDatabaseCount(Installer::BINDINGS, 1);
        $this->assertDatabaseHas(Installer::BINDINGS, ['user_id' => $owner->uid]);
    }

    public function testAlreadyBoundAccountMustUnbindFirst(): void
    {
        $user = $this->user();
        app(Bindings::class)->bind($user, $this->member());
        $this->actingAs($user)->post('/user/wecom/bind')->assertRedirect('/user/wecom')->assertSessionHas('wecom_error');
        Http::assertNothingSent();
    }

    public function testIdentityIsCaseInsensitive(): void
    {
        $user = $this->user();
        app(Bindings::class)->bind($user, $this->member());
        self::assertSame($user->uid, app(Bindings::class)->loginUser([
            'corp_id' => 'WWTESTCORP', 'member_id' => 'ZHANGSAN', 'member_name' => '',
        ])->uid);
    }

    public function testCorpIdentityNamespacesAreSeparate(): void
    {
        $first = $this->user();
        $second = $this->user();
        app(Bindings::class)->bind($first, $this->member());
        app(Bindings::class)->bind($second, array_merge($this->member(), ['corp_id' => 'wwOtherCorp']));
        $this->assertDatabaseCount(Installer::BINDINGS, 2);
    }

    public function testUnbindRequiresCorrectLocalPassword(): void
    {
        $user = $this->user();
        $user->changePassword('Known-password-123');
        app(Bindings::class)->bind($user, $this->member());
        $this->actingAs($user)->post('/user/wecom/unbind', ['password' => 'wrong'])
            ->assertRedirect('/user/wecom')->assertSessionHas('wecom_error');
        $this->assertDatabaseCount(Installer::BINDINGS, 1);
        $this->post('/user/wecom/unbind', ['password' => 'Known-password-123'])
            ->assertRedirect('/user/wecom');
        $this->assertDatabaseCount(Installer::BINDINGS, 0);
        $this->assertAuthenticatedAs($user);
    }

    public function testPasswordlessAccountCannotUnbind(): void
    {
        $user = $this->user(['password' => '']);
        app(Bindings::class)->bind($user, $this->member());
        $this->actingAs($user)->post('/user/wecom/unbind', ['password' => 'anything'])
            ->assertRedirect('/user/wecom')->assertSessionHas('wecom_error');
        $this->assertDatabaseCount(Installer::BINDINGS, 1);
    }

    public function testBannedAccountCannotLogin(): void
    {
        $user = $this->user();
        app(Bindings::class)->bind($user, $this->member());
        $user->permission = User::BANNED;
        $user->save();
        $this->mockMember();
        $state = $this->start();
        $this->oauthCallback($state)->assertRedirect()->assertSessionHas('wecom_error');
        $this->assertGuest();
    }

    public function testBannedAccountCannotBind(): void
    {
        $this->actingAs($this->user(['permission' => User::BANNED]))->post('/user/wecom/bind')
            ->assertForbidden();
        Http::assertNothingSent();
    }

    public function testUserDeletionCascadesBinding(): void
    {
        $user = $this->user();
        app(Bindings::class)->bind($user, $this->member());
        $user->delete();
        $this->assertDatabaseCount(Installer::BINDINGS, 0);
    }

    public function testBindingCsrfIsActuallyEnforced(): void
    {
        $this->app->bind(VerifyCsrfToken::class, StrictCsrf::class);
        $this->actingAs($this->user())->withSession(['_token' => 'correct-token']);
        $this->post('/user/wecom/bind')->assertStatus(419);
        $this->post('/user/wecom/unbind', ['password' => 'x'])->assertStatus(419);
        Http::assertNothingSent();
    }

    public function testNormalUserCannotConfigurePlugin(): void
    {
        $this->actingAs($this->user())->post('/admin/wecom/config', [
            'corp_id' => 'wwOtherCorp', 'agent_id' => '123',
            'redirect_uri' => 'https://skin.example.test/auth/wecom/callback',
        ])->assertForbidden();
    }

    public function testAdminCanSaveSettingsAndEncryptSecret(): void
    {
        $admin = $this->user(['permission' => User::ADMIN]);
        $this->actingAs($admin)->post('/admin/wecom/config', [
            'corp_id' => 'wwOtherCorp', 'agent_id' => '234',
            'redirect_uri' => 'https://skin.example.test/auth/wecom/callback',
            'secret' => 'form-must-not-set-secret',
        ])->assertRedirect('/admin/plugins/config/wecom-login');
        self::assertSame('wwOtherCorp', app(Settings::class)->values()['corp_id']);
        self::assertSame('form-must-not-set-secret', app(Settings::class)->values()['secret']);
        $encrypted = DB::table('options')->where('option_name', 'wecom_secret_encrypted')->value('option_value');
        self::assertNotSame('form-must-not-set-secret', $encrypted);
        self::assertSame('form-must-not-set-secret', Crypt::decryptString($encrypted));
        $this->get('/admin/plugins/config/wecom-login')->assertOk()
            ->assertSee('wwOtherCorp')->assertDontSee('private-test-secret-never-render')
            ->assertDontSee('form-must-not-set-secret');
    }

    public function testInvalidCallbackConfigurationRejected(): void
    {
        $this->actingAs($this->user(['permission' => User::ADMIN]))->post('/admin/wecom/config', [
            'corp_id' => 'wwOtherCorp', 'agent_id' => '234',
            'redirect_uri' => 'http://skin.example.test/auth/wecom/callback',
        ])->assertRedirect('/admin/plugins/config/wecom-login')->assertSessionHas('wecom_error');
    }

    public function testCallbackConfigurationCsrfIsEnforced(): void
    {
        $this->app->bind(VerifyCsrfToken::class, StrictCsrf::class);
        $this->actingAs($this->user(['permission' => User::ADMIN]))
            ->post('/admin/wecom/config', [])->assertStatus(419);
    }

    public function testMemberNameAndIdAreEscapedInHtml(): void
    {
        $user = $this->user();
        app(Bindings::class)->bind($user, array_merge($this->member(), ['member_name' => '<script>alert(1)</script>']));
        $this->actingAs($user)->get('/user/wecom')->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    public function testTokenIsCachedAndEncrypted(): void
    {
        $this->mockMember();
        $client = app(WeComClient::class);
        $settings = app(Settings::class)->requireValid();
        $client->member('code-one', $settings);
        $client->member('code-two', $settings);
        Http::assertSentCount(5);
        $key = 'wecom.token.'.hash('sha256', "wwTestCorp\0001000001\000private-test-secret-never-render");
        $ciphertext = Cache::get($key);
        self::assertIsString($ciphertext);
        self::assertNotSame('test-access-token', $ciphertext);
        self::assertSame('test-access-token', Crypt::decryptString($ciphertext));
    }

    public function testExpiredApiTokenIsRefreshedExactlyOnce(): void
    {
        Http::fake([
            '*/gettoken*' => Http::sequence()
                ->push(['errcode' => 0, 'access_token' => 'old', 'expires_in' => 7200])
                ->push(['errcode' => 0, 'access_token' => 'new', 'expires_in' => 7200]),
            '*/auth/getuserinfo*' => Http::sequence()
                ->push(['errcode' => 42001])->push(['errcode' => 0, 'userid' => 'zhangsan']),
            '*/user/get*' => Http::response(['errcode' => 0, 'userid' => 'zhangsan', 'status' => 1]),
        ]);
        $state = $this->start($this->user());
        $this->oauthCallback($state)->assertRedirect('/user/wecom');
        Http::assertSentCount(5);
    }

    public function testApiFailuresCannotLeakSecrets(): void
    {
        Http::fake(['*' => function () {
            throw new RuntimeException('private-test-secret-never-render token=test-access-token');
        }]);
        $state = $this->start($this->user());
        $this->oauthCallback($state)->assertRedirect()->assertSessionHas('wecom_error')
            ->assertDontSee('private-test-secret-never-render')->assertDontSee('test-access-token');
    }

    public function testApiRedirectIsNotAccepted(): void
    {
        Http::fake(['*' => Http::response('', 302, ['Location' => 'https://evil.example.test/'])]);
        $state = $this->start($this->user());
        $this->oauthCallback($state)->assertRedirect()->assertSessionHas('wecom_error');
        Http::assertSentCount(1);
    }

    public function testMalformedApiResponseFailsClosed(): void
    {
        Http::fake(['*' => Http::response('not-json', 200)]);
        $state = $this->start($this->user());
        $this->oauthCallback($state)->assertRedirect()->assertSessionHas('wecom_error');
    }

    public function testUnbindingRevokesPendingLogin(): void
    {
        $user = $this->user();
        app(Bindings::class)->bind($user, $this->member());
        $this->mockMember();
        $state = $this->start();
        DB::table(Installer::BINDINGS)->where('user_id', $user->uid)->delete();
        $this->oauthCallback($state)->assertRedirect()->assertSessionHas('wecom_error');
        $this->assertGuest();
    }

    public function testUnbindRateLimit(): void
    {
        $this->actingAs($this->user());
        for ($i = 0; $i < 5; $i++) {
            $this->post('/user/wecom/unbind', ['password' => 'wrong'])->assertRedirect('/user/wecom')->assertSessionHas('wecom_error');
        }
        $this->post('/user/wecom/unbind', ['password' => 'wrong'])->assertStatus(429);
    }

    public function testDatabaseUniquenessEnforcesBothSides(): void
    {
        $user = $this->user();
        $other = $this->user();
        app(Bindings::class)->bind($user, $this->member());
        $row = (array) DB::table(Installer::BINDINGS)->first();
        unset($row['id']);
        foreach ([array_merge($row, ['user_id' => $other->uid]), array_merge($row, ['identity_hash' => str_repeat('f', 64)])] as $duplicate) {
            try {
                DB::table(Installer::BINDINGS)->insert($duplicate);
                self::fail('Database accepted a duplicate binding');
            } catch (Illuminate\Database\QueryException $error) {
                self::assertSame('23000', (string) $error->getCode());
            }
        }
        $this->assertDatabaseCount(Installer::BINDINGS, 1);
    }

    public function testDisablingPluginPreservesBindingData(): void
    {
        $user = $this->user();
        app(Bindings::class)->bind($user, $this->member());
        app(PluginManager::class)->disable('wecom-login');
        Installer::install();
        $this->assertDatabaseHas(Installer::BINDINGS, ['user_id' => $user->uid]);
    }

    public function testInstallerHonorsDatabaseTablePrefix(): void
    {
        $old = DB::getDefaultConnection();
        config(['database.connections.wecom_prefixed' => array_merge(config('database.connections.sqlite'), [
            'database' => ':memory:', 'prefix' => 'skin_',
        ])]);
        DB::setDefaultConnection('wecom_prefixed');
        Schema::clearResolvedInstance('db.schema');
        try {
            Schema::create('users', function ($table) {
                $table->increments('uid');
            });
            Installer::install();
            self::assertTrue(Schema::hasTable(Installer::BINDINGS));
            self::assertTrue(Schema::hasTable(Installer::STATES));
            DB::table('users')->insert(['uid' => 99]);
            DB::table(Installer::BINDINGS)->insert([
                'user_id' => 99, 'corp_id' => 'wwTestCorp', 'member_id' => 'prefixed-member',
                'identity_hash' => str_repeat('e', 64), 'created_at' => now(),
            ]);
            self::assertSame('prefixed-member', app(Bindings::class)->forUser(99)->member_id);
        } finally {
            DB::setDefaultConnection($old);
            Schema::clearResolvedInstance('db.schema');
        }
    }

    public function testDashboardRegistersBindingWidgetAndUserMenu(): void
    {
        $user = $this->user();
        $this->actingAs($user)->get('/user')->assertOk()
            ->assertSee('id="wecom-binding-widget"', false)
            ->assertSee('尚未绑定企业微信')
            ->assertSee('企业微信绑定')
            ->assertSee('/user/wecom', false);
        app(Bindings::class)->bind($user, $this->member());
        $this->get('/user')->assertOk()->assertSee('已绑定企业微信')->assertSee('张三')
            ->assertSee('管理企业微信绑定');
    }

    public function testDashboardWidgetOnlyDisplaysCurrentUserBinding(): void
    {
        app(Bindings::class)->bind($this->user(), $this->member());
        $this->actingAs($this->user())->get('/user')->assertOk()
            ->assertSee('尚未绑定企业微信')->assertDontSee('张三');
    }

    public function testDashboardExplainsMissingApplicationConfiguration(): void
    {
        config(['wecom.secret' => '']);
        $this->actingAs($this->user())->get('/user')->assertOk()
            ->assertSee('管理员尚未完成企业微信配置');
    }

    public function testPluginManagementPublishesMetadataAndConfigEntry(): void
    {
        $this->actingAs($this->user(['permission' => User::ADMIN]));
        $this->get('/admin/plugins/data')->assertOk()->assertJsonFragment([
            'name' => 'wecom-login', 'title' => '企业微信登录与绑定',
            'enabled' => true, 'config' => true, 'readme' => true,
        ])->assertJsonFragment(['fa' => 'building', 'faType' => 'fas', 'bg' => 'success']);
        $this->get('/admin/plugins/config/wecom-login')->assertOk()
            ->assertSee('企业微信登录配置')->assertSee('CorpID');
        $this->get('/admin/plugins/manage')->assertOk()->assertSee('企业微信登录与绑定');
    }

    public function testOnlyFullPluginViewNamespaceIsRegistered(): void
    {
        self::assertTrue(view()->exists('Blessing\\WeCom::account'));
        self::assertTrue(view()->exists('Blessing\\WeCom::widgets.account'));
        self::assertFalse(view()->exists('WeCom::account'));
    }

    public function testUserEntryIsNestedNextToOAuthWithoutDuplicate(): void
    {
        $menu = config('menu');
        $event = new App\Events\ConfigureUserMenu($menu);
        event($event);
        event($event);
        $topLevel = array_filter($menu['user'], fn ($entry) => ($entry['link'] ?? '') === 'user/wecom');
        self::assertCount(0, $topLevel);
        $groups = array_values(array_filter($menu['user'], fn ($entry) => ($entry['title'] ?? '') === 'general.developer'));
        self::assertCount(1, $groups);
        $links = array_column($groups[0]['children'], 'link');
        self::assertContains('user/oauth/manage', $links);
        self::assertSame(1, count(array_filter($links, fn ($link) => $link === 'user/wecom')));
        $this->actingAs($this->user())->get('/user/wecom')->assertOk()->assertSee('menu-open');
    }

    public function testBlankSecretKeepsExistingAndExplicitResetRestoresEnvironment(): void
    {
        $this->actingAs($this->user(['permission' => User::ADMIN]));
        $fields = ['corp_id' => 'wwTestCorp', 'agent_id' => '1000001',
            'redirect_uri' => 'https://skin.example.test/auth/wecom/callback'];
        $this->post('/admin/wecom/config', $fields + ['secret' => 'saved-application-secret'])->assertRedirect();
        $this->post('/admin/wecom/config', $fields + ['secret' => ''])->assertRedirect();
        self::assertSame('saved-application-secret', app(Settings::class)->values()['secret']);
        $this->post('/admin/wecom/config', $fields + ['use_env_secret' => 1])->assertRedirect();
        self::assertSame('private-test-secret-never-render', app(Settings::class)->values()['secret']);
        self::assertSame('', option('wecom_secret_encrypted'));
    }

    public function testInvalidSettingsDoNotFlashSecretIntoSession(): void
    {
        $this->actingAs($this->user(['permission' => User::ADMIN]))
            ->post('/admin/wecom/config', ['secret' => 'must-not-be-flashed'])->assertRedirect('/admin/plugins/config/wecom-login')->assertSessionHas('wecom_error');
        self::assertStringNotContainsString('must-not-be-flashed', json_encode(session()->all()));
    }

    public function testNormalUserCannotSetSecret(): void
    {
        $this->actingAs($this->user())->post('/admin/wecom/config', [
            'corp_id' => 'wwTestCorp', 'agent_id' => '1000001',
            'redirect_uri' => 'https://skin.example.test/auth/wecom/callback', 'secret' => 'unauthorized',
        ])->assertForbidden();
        self::assertSame('', option('wecom_secret_encrypted', ''));
    }

    public function testConflictingSecretResetAndReplacementIsRejected(): void
    {
        $this->actingAs($this->user(['permission' => User::ADMIN]))->post('/admin/wecom/config', [
            'corp_id' => 'wwTestCorp', 'agent_id' => '1000001',
            'redirect_uri' => 'https://skin.example.test/auth/wecom/callback',
            'secret' => 'replacement-secret', 'use_env_secret' => 1,
        ])->assertRedirect('/admin/plugins/config/wecom-login')->assertSessionHas('wecom_error');
        self::assertSame('', option('wecom_secret_encrypted', ''));
    }
}
