<?php

declare(strict_types=1);
use App\Events\ConfigureRoutes;
use App\Models\User;
use App\Models\Player;
use App\Services\Plugin;
use App\Services\PluginManager;
use Blessing\LoginHistory\Installer;
use Blessing\LoginHistory\Recorder;
use Blessing\LoginHistory\Observer;
use Blessing\LoginHistory\CaptureYggdrasil;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;

final class HistoryTest extends Tests\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $path = dirname(__DIR__);
        $plugin = new Plugin($path, json_decode(file_get_contents($path.'/package.json'), true));
        $manager = app(PluginManager::class);
        $manager->all()->put('login-history', $plugin);
        self::assertTrue($manager->enable($plugin));
        $booted = new ReflectionProperty($manager, 'booted');$booted->setValue($manager, false);$manager->boot();
        event(new ConfigureRoutes(app('router')));
        app('router')->getRoutes()->refreshNameLookups();
        config(['logging.channels.ygg' => ['driver'=>'monolog','handler'=>Monolog\Handler\NullHandler::class]]);
    }
    public function testEnabledPluginBootRepairsMissingSecondTable(): void
    {
        $firstSchema = Schema::getColumnListing(Installer::ENTRIES);
        Schema::drop(Installer::PROFILES);
        $manager = app(PluginManager::class);
        // enable is a no-op for an already-enabled plugin: bootstrap must repair it.
        self::assertTrue($manager->enable('login-history'));
        self::assertFalse(Schema::hasTable(Installer::PROFILES));
        $booted = new ReflectionProperty($manager, 'booted');
        $booted->setValue($manager, false);
        $manager->boot();
        self::assertTrue(Schema::hasTable(Installer::PROFILES));
        self::assertSame($firstSchema, Schema::getColumnListing(Installer::ENTRIES));
        Installer::install();
        self::assertTrue(Schema::hasTable(Installer::PROFILES));
    }

    public function testSchemaDoesNotRequireForeignKeyToHostUsers(): void
    {
        $keys = DB::select('PRAGMA foreign_key_list('.Installer::ENTRIES.')');
        self::assertCount(0, $keys);
    }

    public function testPasswordChannelRemainsPasswordWithRememberChecked(): void
    {
        $user = $this->user();$user->changePassword('Known-password-123');
        $this->post('/auth/login', ['identification' => $user->email, 'password' => 'Known-password-123', 'keep' => true])->assertOk();
        $this->assertDatabaseHas(Installer::ENTRIES, ['user_id' => $user->uid, 'login_channel' => 'password']);
        $this->get('/user/login-history')->assertOk()->assertSee('密码登录');
    }

    public function testActualOAuthControllerCapturesProviderFromRouteNotQuery(): void
    {
        if (!getenv('BLESSING_OAUTH_VENDOR_PATH')) {$this->markTestSkipped('Set BLESSING_OAUTH_VENDOR_PATH to the OAuth vendor directory.');}
        $user = $this->user();
        Route::middleware('web')->get('auth/login/{driver}/callback', [\Blessing\OAuthCore\AuthController::class, 'callback']);
        foreach (['github', 'google', 'live', 'littleskin'] as $provider) {
            $driver = Mockery::mock();$driver->shouldReceive('user')->once()->andReturn((object) ['email' => $user->email]);
            $factory = Mockery::mock(\Laravel\Socialite\Contracts\Factory::class);
            $factory->shouldReceive('driver')->with($provider)->once()->andReturn($driver);
            \Laravel\Socialite\Facades\Socialite::swap($factory);
            $this->get('/auth/login/'.$provider.'/callback?driver=evil&channel=password')->assertRedirect('/user');
            $this->assertDatabaseHas(Installer::ENTRIES, ['user_id' => $user->uid, 'login_channel' => 'oauth:'.$provider]);
            Auth::logout();
        }
        $this->actingAs($user)->get('/user/login-history')->assertOk()->assertSee('GitHub')->assertSee('Microsoft Live');
        $this->get('/user/login-history?channel=oauth:github')->assertOk()->assertSee('共 1 条');
        self::assertSame(0, DB::table(Installer::ENTRIES)->where('login_channel', 'password')->count());
    }

    public function testWeComChannelUsesExactControllerAndQueryCannotSpoofChannel(): void
    {
        $route = new \Illuminate\Routing\Route('GET', 'auth/wecom/callback', ['uses' => 'Blessing\\WeCom\\Controller@callback', 'controller' => 'Blessing\\WeCom\\Controller@callback']);
        $request = Request::create('/auth/wecom/callback?driver=github&login_channel=password');
        $request->setRouteResolver(fn () => $route);
        $this->app->instance('request', $request);
        $user = $this->user();event(new \Illuminate\Auth\Events\Login('web', $user, false));
        $this->assertDatabaseHas(Installer::ENTRIES, ['user_id' => $user->uid, 'login_channel' => 'wecom']);
    }

    public function testUnknownControllerCannotBeMislabelledFromUrl(): void
    {
        $request = Request::create('/auth/login/github/callback?driver=github');
        $this->app->instance('request', $request);
        $user = $this->user();event(new \Illuminate\Auth\Events\Login('web', $user, false));
        $this->assertDatabaseHas(Installer::ENTRIES, ['user_id' => $user->uid, 'login_channel' => 'unknown']);
    }

    public function testRememberRestorationIsSeparateFromPasswordLogin(): void
    {
        $guard = Auth::guard('web');(new ReflectionProperty($guard, 'viaRemember'))->setValue($guard, true);
        $user = $this->user();event(new \Illuminate\Auth\Events\Login('web', $user, true));
        $this->assertDatabaseHas(Installer::ENTRIES, ['user_id' => $user->uid, 'login_channel' => 'remember']);
    }

    public function testOldRowsAreNotGuessedAndChannelFilterIsUserScoped(): void
    {
        $user = $this->user();$other = $this->user();
        app(Recorder::class)->record($user->uid, 'web', 'login', 'web', Request::create('/old'));
        app(Recorder::class)->record($other->uid, 'web', 'login', 'oauth', Request::create('/other'), [], 'oauth:custom-private');
        $this->actingAs($user)->get('/user/login-history?channel=legacy')->assertOk()->assertSee('旧版记录（渠道信息不完整）')->assertSee('共 1 条')->assertDontSee('custom-private');
        $this->get('/user/login-history?channel=oauth:custom-private')->assertOk()->assertSee('共 0 条');
    }

    public function testPasskeyLoginIsRecordedDisplayedAndFilterable(): void
    {
        $user = $this->user();
        request()->attributes->set('login_history.web_channel', 'custom:passkey');
        event(new \Illuminate\Auth\Events\Login('web', $user, false));
        $this->assertDatabaseHas(Installer::ENTRIES, ['user_id' => $user->uid, 'login_channel' => 'custom:passkey']);
        $this->actingAs($user)->get('/user/login-history?channel=custom:passkey')->assertOk()->assertSee('通行密钥')->assertSee('共 1 条')->assertDontSee('第三方 · passkey');
        $this->get('/user/login-history?channel=password')->assertOk()->assertSee('共 0 条');
    }

    public function testPasskeyChannelCannotBeSetByQueryAndHasStableLabel(): void
    {
        $request = Request::create('/auth/two-factor/passkey/verify?login_channel=custom:passkey');
        self::assertSame('unknown', \Blessing\LoginHistory\WebChannels::detect($request, false));
        self::assertSame('通行密钥', \Blessing\LoginHistory\WebChannels::label('custom:passkey'));
        self::assertSame('两步验证', \Blessing\LoginHistory\WebChannels::label('custom:two-factor'));
        self::assertSame('密码登录', \Blessing\LoginHistory\WebChannels::label('password'));
    }

    public function testCustomServerSideChannelSupportsLongProviderIdentifiers(): void
    {
        $channel = 'custom:'.str_repeat('a', 50);
        request()->attributes->set('login_history.web_channel', $channel);
        $user = $this->user();event(new \Illuminate\Auth\Events\Login('web', $user, false));
        $this->assertDatabaseHas(Installer::ENTRIES, ['user_id' => $user->uid, 'login_channel' => $channel]);
    }

    public function testUserAgentLabelsPrioritizeMobileAndWeComBeforeDesktopTokens(): void
    {
        $parser = app(\Blessing\LoginHistory\ClientAgent::class);
        foreach ([
            ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/130', '电脑 · Windows'],
            ['Mozilla/5.0 (Macintosh; Intel Mac OS X 14_6) Safari/605', '电脑 · macOS'],
            ['Mozilla/5.0 (X11; Linux x86_64) Firefox/130', '电脑 · Linux'],
            ['Mozilla/5.0 (Linux; Android 14; Pixel 8) Mobile', '手机 · Android'],
            ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X)', '手机 · iOS'],
            ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15) Mobile/15E148', '平板 · iPadOS'],
            ['Mozilla/5.0 (Linux; Android 14) MicroMessenger/8.0 wxwork/4.1.0', '企业微信 · Android'],
            ['Mozilla/5.0 (Windows NT 10.0) wxwork/4.1.0', '企业微信 · Windows'],
            ['Mozilla/5.0 (iPhone) MicroMessenger/8.0', '微信 · iOS'],
            ['Java/21 Minecraft Launcher', '其他客户端'], ['', '未记录'],
        ] as [$agent, $label]) {self::assertSame($label, $parser->label($agent));}
    }

    public function testHistoryShowsShortClientLabelWithFullEscapedUaTooltip(): void
    {
        $user = $this->user();
        $agent = 'Mozilla/5.0 (Windows NT 10.0) Chrome/130 " onmouseover="alert(1)<script>';
        app(Recorder::class)->record($user->uid, 'web', 'login', 'password', Request::create('/test', 'POST', [], [], [], ['HTTP_USER_AGENT' => $agent]), [], 'password');
        $this->actingAs($user)->get('/user/login-history')->assertOk()->assertSee('电脑 · Windows')
            ->assertSee('title="Mozilla/5.0 (Windows NT 10.0) Chrome/130 &quot; onmouseover=&quot;alert(1)&lt;script&gt;"', false)
            ->assertDontSee('title="Mozilla/5.0 (Windows NT 10.0) Chrome/130 " onmouseover="', false);
        $this->assertDatabaseHas(Installer::ENTRIES, ['user_id' => $user->uid, 'user_agent' => $agent]);
    }

    public function testIpTooltipUsesLocalLocationAndEscapesAttribute(): void
    {
        $geo = new \Blessing\LoginHistory\GeoLocation(__DIR__.'/fixtures/GeoIP2-City-Test.mmdb');
        self::assertStringContainsString('伦敦', $geo->label('81.2.69.142'));
        self::assertSame('内网或保留地址', $geo->label('192.168.1.1'));
        $this->app->instance(\Blessing\LoginHistory\GeoLocation::class, $geo);
        $user = $this->user();
        app(Recorder::class)->record($user->uid, 'web', 'login', 'password', Request::create('/test', 'POST', [], [], [], ['REMOTE_ADDR' => '81.2.69.142']), [], 'password');
        $this->actingAs($user)->get('/user/login-history')->assertOk()->assertSee('参考归属地：英国 · 英格兰 · 伦敦')->assertSee('81.2.69.142');
    }

    public function testWeComAndHistoryAutoloadersCanBeEnabledTogether(): void
    {
        $path=dirname(__DIR__,2).'/wecom-login';
        $plugin=new Plugin($path,json_decode(file_get_contents($path.'/package.json'),true));
        $manager=app(PluginManager::class);$manager->all()->put('wecom-login',$plugin);
        self::assertTrue($manager->enable($plugin));
        $booted=new ReflectionProperty($manager,'booted');$booted->setValue($manager,false);$manager->boot();
        event(new ConfigureRoutes(app('router')));app('router')->getRoutes()->refreshNameLookups();
        self::assertTrue(class_exists('ComposerAutoloaderInitBlessingLoginHistory'));
        self::assertTrue(class_exists('ComposerAutoloaderInitb48eee53571ba794117e44c34328c6f5'));
        $this->actingAs($this->user())->get('/user/login-history')->assertOk();
        $this->get('/user/wecom')->assertOk();
    }

    public function testAdminConfigurationListsAllUsersAndHasPrivateResponse(): void
    {
        $first = $this->user();$second = $this->user();
        $first->update(['nickname'=>'AdminLogAlice']);$second->update(['nickname'=>'AdminLogBob']);
        $this->record($first);$this->record($second);
        $this->actingAs(User::factory()->admin()->create())->get('/admin/plugins/config/login-history')
            ->assertOk()->assertSee('全站登录记录')->assertSee('AdminLogAlice')->assertSee('AdminLogBob')->assertSee('共 2 条')
            ->assertHeader('Cache-Control','no-store, private')->assertHeader('Referrer-Policy','same-origin');
    }

    public function testAdminFiltersUidKeywordIpAndChannelTogether(): void
    {
        $first = $this->user();$second = $this->user();$first->update(['nickname'=>'SearchLoginUser']);
        app(Recorder::class)->record($first->uid,'web','login','web',Request::create('/test','GET',[],[],[],['REMOTE_ADDR'=>'203.0.113.8']),[],'custom:passkey');
        $this->record($second);
        $url='/admin/plugins/config/login-history?uid='.$first->uid.'&user=SearchLogin&ip=203.0.113.8&channel=custom:passkey';
        $this->actingAs(User::factory()->superAdmin()->create())->get($url)->assertOk()->assertSee('共 1 条')->assertSee('SearchLoginUser')->assertSee('通行密钥');
        $this->get($url.'&source=yggdrasil')->assertOk()->assertSee('共 0 条');
        $this->get('/admin/plugins/config/login-history?uid='.$second->uid.'&ip=203.0.113.8')->assertOk()->assertSee('共 0 条');
    }

    public function testAdminRoleFilterUsesSelectedRoleAcrossUsers(): void
    {
        $first=$this->user();$second=$this->user();
        $this->record($first,'yggdrasil','authenticate',[
            ['player_id'=>101,'player_name'=>'SelectedRole','selected'=>true],
            ['player_id'=>102,'player_name'=>'CandidateRole','selected'=>false],
        ]);
        $this->record($second,'yggdrasil','join',[['player_id'=>102,'player_name'=>'JoinedRole','selected'=>true]]);
        $this->actingAs(User::factory()->admin()->create())->get('/admin/plugins/config/login-history?source=yggdrasil&player=102')
            ->assertOk()->assertSee('共 1 条')->assertSee('JoinedRole')->assertDontSee('SelectedRole');
    }

    public function testAdminPaginationPreservesFiltersAndShowsPageNumbers(): void
    {
        $user=$this->user();
        for($i=0;$i<21;$i++){$this->record($user);}
        $this->actingAs(User::factory()->admin()->create())->get('/admin/plugins/config/login-history?uid='.$user->uid.'&page=2')
            ->assertOk()->assertSee('共 21 条')->assertSee('第 2 / 2 页')->assertSee('aria-current="page"',false)->assertSee('rel="prev"',false)
            ->assertSee('uid='.$user->uid,false)->assertSee('page=1',false);
    }

    public function testOrdinaryUsersCannotReadAdminAndCannotExpandOwnScope(): void
    {
        $first=$this->user();$second=$this->user();$this->record($first);$this->record($second);
        $this->actingAs($first)->get('/admin/plugins/config/login-history')->assertForbidden();
        $this->get('/user/login-history?uid='.$second->uid.'&user=anything&ip=203.0.113.7')->assertOk()->assertSee('共 1 条');
        $records=app(\Blessing\LoginHistory\History::class)->records($first->uid,['source'=>'all','uid'=>$second->uid]);
        self::assertSame($first->uid,(int)$records->first()->user_id);
    }

    public function testAdminInvalidFiltersReturnValidationAndEscapeNickname(): void
    {
        $user=$this->user();$user->update(['nickname'=>'<script>logTest</script>']);$this->record($user);
        $this->actingAs(User::factory()->admin()->create())->get('/admin/plugins/config/login-history')->assertOk()
            ->assertSee('&lt;script&gt;logTest&lt;/script&gt;',false)->assertDontSee('<script>logTest</script>',false);
        foreach(['ip=bad','uid=-1','source=bad','from=2026-10-24&to=2026-10-23'] as $query){
            $this->getJson('/admin/plugins/config/login-history?'.$query)->assertStatus(422);
        }
    }

    public function testAdminCanQueryExistingLegacyLogsAndDeletedUsers(): void
    {
        Schema::create('ygg_log',function($t){$t->increments('id');$t->string('action');$t->unsignedInteger('user_id');$t->unsignedInteger('player_id');$t->string('ip');$t->dateTime('time');});
        DB::table('ygg_log')->insert(['action'=>'join','user_id'=>99999,'player_id'=>7,'ip'=>'203.0.113.9','time'=>now()]);
        $this->actingAs(User::factory()->admin()->create())->get('/admin/plugins/config/login-history?source=legacy&uid=99999&player=7')
            ->assertOk()->assertSee('共 1 条')->assertSee('已删除用户')->assertSee('历史角色编号 #7')->assertSee('203.0.113.9');
    }

    private function user(): User {return User::factory()->create();}
    private function player(User $user, string $name): Player
    {
        return Player::create(['uid'=>$user->uid,'name'=>$name,'tid_skin'=>0,'tid_cape'=>0]);
    }
    private function record(User $user, string $source='web', string $action='login', array $profiles=[]): void
    {
        $request = Request::create('/test','POST',[],[],[],['REMOTE_ADDR'=>'203.0.113.7','HTTP_USER_AGENT'=>'TestAgent']);
        app(Recorder::class)->record($user->uid,$source,$action,'test',$request,$profiles);
    }
    private function legacyRoutes(): void
    {
        require getenv('BLESSING_PLUGINS_PATH').'/plugins/yggdrasil-api/src/Utils/helpers.php';
        Schema::create('uuid',function($table){$table->increments('id');$table->string('name');$table->string('uuid');});
        foreach(['ygg_uuid_algorithm'=>'v3','ygg_token_expire_1'=>'259200','ygg_token_expire_2'=>'604800','ygg_tokens_limit'=>'10','ygg_rate_limit'=>'1000'] as $k=>$v){option([$k=>$v]);}
        Route::namespace('Yggdrasil\\Controllers')->prefix('api/yggdrasil')->group(getenv('BLESSING_PLUGINS_PATH').'/plugins/yggdrasil-api/routes.php');
    }
    private function authenticate(User $user, ?string $identification=null)
    {
        $user->changePassword('Known-password-123');
        return $this->postJson('/api/yggdrasil/authserver/authenticate',[
            'username'=>$identification ?? $user->email,'password'=>'Known-password-123','clientToken'=>'private-client-token',
        ]);
    }
    public function testGuestCannotQuery(): void {$this->get('/user/login-history')->assertRedirect('/auth/login');}
    public function testMenuIsNestedAndKeepsOAuth(): void
    {
        $menu=config('menu');event(new App\Events\ConfigureUserMenu($menu));
        self::assertNotContains('user/login-history',array_column($menu['user'],'link'));
        $group=collect($menu['user'])->firstWhere('title','general.developer');
        self::assertContains('user/login-history',array_column($group['children'],'link'));
        self::assertContains('user/oauth/manage',array_column($group['children'],'link'));
    }
    public function testActualWebLoginIsRecordedOnce(): void
    {
        $user=$this->user();$user->changePassword('Known-password-123');
        $this->post('/auth/login',['identification'=>$user->email,'password'=>'Known-password-123'])->assertOk();
        $this->assertDatabaseCount(Installer::ENTRIES,1);
        $this->assertDatabaseHas(Installer::ENTRIES,['user_id'=>$user->uid,'source'=>'web','action'=>'login']);
        $this->get('/user/login-history')->assertOk();$this->assertDatabaseCount(Installer::ENTRIES,1);
    }
    public function testOtherAccountsAndUidQueryAreIsolated(): void
    {
        $own=$this->user();$other=$this->user();$this->record($own);$this->record($other);
        DB::table(Installer::ENTRIES)->where('user_id',$other->uid)->update(['user_agent'=>'SECRET-OTHER-USER']);
        $this->actingAs($own)->get('/user/login-history?user_id='.$other->uid)->assertOk()->assertDontSee('SECRET-OTHER-USER')->assertSee('共 1 条');
    }
    public function testMultiRoleAuthenticationIsNotAssignedToFirstPlayer(): void
    {
        $this->legacyRoutes();$user=$this->user();$a=$this->player($user,'Alpha');$b=$this->player($user,'Beta');
        $this->authenticate($user)->assertOk();
        $this->assertDatabaseCount(Installer::ENTRIES,1);$this->assertDatabaseCount(Installer::PROFILES,2);
        self::assertSame(0,DB::table(Installer::PROFILES)->where('selected',true)->count());
        $this->actingAs($user)->get('/user/login-history')->assertOk()->assertSee('Alpha')->assertSee('Beta')->assertSee('未选择角色（账号级认证）');
        $this->get('/user/login-history?player='.$a->pid)->assertOk()->assertSee('没有符合条件');
    }
    public function testActualRoleSelectionAndJoinAreRecorded(): void
    {
        $this->legacyRoutes();$user=$this->user();$a=$this->player($user,'Alpha');$b=$this->player($user,'Beta');
        $auth=$this->authenticate($user)->assertOk()->json();$profile=$auth['availableProfiles'][1];
        $refresh=$this->postJson('/api/yggdrasil/authserver/refresh',[
            'accessToken'=>$auth['accessToken'],'clientToken'=>$auth['clientToken'],'selectedProfile'=>$profile,
        ])->assertOk()->json();
        $this->postJson('/api/yggdrasil/sessionserver/session/minecraft/join',[
            'accessToken'=>$refresh['accessToken'],'selectedProfile'=>$profile['id'],'serverId'=>'private-server-id',
        ])->assertNoContent();
        $this->assertDatabaseCount(Installer::ENTRIES,3);
        $selected=DB::table(Installer::PROFILES)->where('selected',true)->get();self::assertCount(2,$selected);
        foreach($selected as $row){self::assertSame($b->pid,(int)$row->player_id);self::assertSame('Beta',$row->player_name);}
        $this->actingAs($user)->get('/user/login-history?player='.$b->pid)->assertOk()->assertSee('共 2 条');
        $this->get('/user/login-history?player='.$a->pid)->assertOk()->assertSee('没有符合条件');
    }
    public function testSingleRoleAuthenticationSelectsItsActualRole(): void
    {
        $this->legacyRoutes();$user=$this->user();$player=$this->player($user,'Solo');$this->authenticate($user)->assertOk();
        $this->assertDatabaseHas(Installer::PROFILES,['player_id'=>$player->pid,'selected'=>true]);
    }
    public function testFailedYggdrasilAuthenticationDoesNotProduceSuccess(): void
    {
        $user=$this->user();
        $request=Request::create('/api/yggdrasil/authserver/authenticate','POST',['username'=>$user->email,'password'=>'wrong']);
        $request->attributes->set('login_history.backend','yggdrasil-api');$request->attributes->set('login_history.operation','authenticate');
        app(CaptureYggdrasil::class)->handle($request,fn()=>response()->json(['error'=>'ForbiddenOperationException'],403));
        $this->assertDatabaseCount(Installer::ENTRIES,0);
    }
    public function testSecretsAreNotStoredOrRendered(): void
    {
        $this->legacyRoutes();$user=$this->user();$this->player($user,'Solo');$body=$this->authenticate($user)->assertOk()->json();
        $raw=json_encode(DB::table(Installer::ENTRIES)->get()).json_encode(DB::table(Installer::PROFILES)->get());
        foreach(['Known-password-123','private-client-token',$body['accessToken']] as $secret){self::assertStringNotContainsString($secret,$raw);}
        $this->actingAs($user)->get('/user/login-history')->assertOk()->assertDontSee($body['accessToken'])->assertDontSee('private-client-token');
    }
    public function testDeletedRenamedOrTransferredRolesKeepOriginalOwner(): void
    {
        $user=$this->user();$other=$this->user();$player=$this->player($user,'OldName');
        $this->record($user,'yggdrasil','refresh',[['player_id'=>$player->pid,'player_name'=>'OldName','profile_uuid'=>str_repeat('a',32),'selected'=>true]]);
        $player->uid=$other->uid;$player->name='NewName';$player->save();
        $this->actingAs($user)->get('/user/login-history')->assertOk()->assertSee('OldName')->assertDontSee('NewName');
        $this->actingAs($other)->get('/user/login-history')->assertOk()->assertDontSee('OldName');
        $player->delete();$this->actingAs($user)->get('/user/login-history?player='.$player->pid)->assertOk()->assertSee('OldName');
    }
    public function testPaginationIsBoundedAndPrivate(): void
    {
        $user=$this->user();for($i=0;$i<25;$i++){$this->record($user);}
        $response=$this->actingAs($user)->get('/user/login-history?per_page=10000');$response->assertOk()->assertSee('共 25 条')->assertSee('下一页');
        $response->assertHeader('Cache-Control','no-store, private');
        $this->get('/user/login-history?page=2')->assertOk()->assertSee('上一页');
    }
    public function testRetentionPrunesOwnTablesOnly(): void
    {
        $user=$this->user();$this->record($user);DB::table(Installer::ENTRIES)->update(['occurred_at'=>now('UTC')->subDays(91)]);
        Cache::flush();$this->record($user);$this->assertDatabaseCount(Installer::ENTRIES,1);
    }
    public function testDeletedUserCascadesRecordsAndProfiles(): void
    {
        $user=$this->user();$this->record($user,'yggdrasil','refresh',[['player_name'=>'Gone','selected'=>true]]);$user->delete();
        $this->assertDatabaseCount(Installer::ENTRIES,0);$this->assertDatabaseCount(Installer::PROFILES,0);
    }
    public function testHtmlIsEscaped(): void
    {
        $user=$this->user();$this->record($user,'yggdrasil','refresh',[['player_name'=>'<script>alert(1)</script>','selected'=>true]]);
        $this->actingAs($user)->get('/user/login-history')->assertOk()->assertDontSee('<script>alert(1)</script>',false)->assertSee('&lt;script&gt;',false);
    }
    public function testFilterInputsAreValidated(): void
    {
        $this->actingAs($this->user())->getJson('/user/login-history?source=evil')->assertStatus(422);
        $this->getJson('/user/login-history?player=-1')->assertStatus(422);
        $this->getJson('/user/login-history?from=2026-13-99')->assertStatus(422);
    }
    public function testLegacyLogsAreReadOnlyAndExcludeParameters(): void
    {
        $user=$this->user();$other=$this->user();
        Schema::create('ygg_log',function($table){$table->increments('id');$table->string('action');$table->integer('user_id');$table->integer('player_id');$table->text('parameters');$table->string('ip');$table->dateTime('time');});
        foreach([$user,$other] as $owner){DB::table('ygg_log')->insert(['action'=>'join','user_id'=>$owner->uid,'player_id'=>7,'parameters'=>'{"accessToken":"legacy-secret-token"}','ip'=>'203.0.113.9','time'=>now()]);}
        $this->actingAs($user)->get('/user/login-history?source=legacy')->assertOk()->assertSee('历史角色编号 #7')->assertSee('共 1 条')->assertDontSee('legacy-secret-token');
        $this->assertDatabaseCount('ygg_log',2);
    }
    public function testNoLegacyTableReturns404(): void {$this->actingAs($this->user())->get('/user/login-history?source=legacy')->assertNotFound();}
    public function testPluginManagementAndConfigEntry(): void
    {
        $user=$this->user();$user->permission=1;$user->save();
        $this->actingAs($user)->get('/admin/plugins/data')->assertOk()->assertJsonFragment(['name'=>'login-history','enabled'=>true,'config'=>true]);
        $this->get('/admin/plugins/config/login-history')->assertOk()->assertSee('全站登录记录');
    }
    public function testRecorderFailureDoesNotBreakLogin(): void
    {
        Schema::drop(Installer::PROFILES);Schema::drop(Installer::ENTRIES);
        $user=$this->user();Auth::login($user);$this->assertAuthenticatedAs($user);
    }
    public function testConnectCaptureUsesAuthenticatedOwnerNotCookieOrRoleGuess(): void
    {
        $user=$this->user();$other=$this->user();$player=$this->player($user,'ConnectRole');
        $request=Request::create('/api/yggdrasil/authserver/authenticate','POST',['username'=>'irrelevant@example.test','password'=>'never-save']);
        $request->setUserResolver(fn()=>$user);
        $request->attributes->set('login_history.backend','yggdrasil-connect');$request->attributes->set('login_history.operation','authenticate');
        $response=app(CaptureYggdrasil::class)->handle($request,fn()=>response()->json(['accessToken'=>'must-not-persist','selectedProfile'=>['name'=>'ConnectRole','id'=>str_repeat('b',32)],'availableProfiles'=>[]]));
        self::assertSame(200,$response->getStatusCode());
        $this->assertDatabaseHas(Installer::ENTRIES,['user_id'=>$user->uid,'backend'=>'yggdrasil-connect']);
        $this->assertDatabaseHas(Installer::PROFILES,['player_id'=>$player->pid,'selected'=>true]);
        self::assertStringNotContainsString('must-not-persist',json_encode(DB::table(Installer::ENTRIES)->get()));
    }

    public function testFailedRefreshDoesNotAddSuccessRecord(): void
    {
        $this->legacyRoutes();$user=$this->user();$this->player($user,'Alpha');$this->player($user,'Beta');
        $auth=$this->authenticate($user)->assertOk()->json();
        $request=Request::create('/api/yggdrasil/authserver/refresh','POST',['accessToken'=>$auth['accessToken']]);
        $request->attributes->set('login_history.backend','yggdrasil-api');$request->attributes->set('login_history.operation','refresh');
        app(CaptureYggdrasil::class)->handle($request,fn()=>response()->json(['error'=>'invalid'],400));
        $this->assertDatabaseCount(Installer::ENTRIES,1);
    }
    public function testLegacyAuthenticationNeverUsesUnrelatedWebCookieOwner(): void
    {
        $this->legacyRoutes();$owner=$this->user();$other=$this->user();$this->player($owner,'OwnerRole');
        $this->actingAs($other);$this->authenticate($owner)->assertOk();
        $this->assertDatabaseHas(Installer::ENTRIES,['user_id'=>$owner->uid,'source'=>'yggdrasil']);
        self::assertSame(0,DB::table(Installer::ENTRIES)->where('user_id',$other->uid)->count());
    }
    public function testDateFiltersUseWebsiteTimezoneAndAllowOnlyEndDate(): void
    {
        $user=$this->user();$this->record($user);
        $day=now(config('app.timezone'))->format('Y-m-d');
        $this->actingAs($user)->get('/user/login-history?to='.$day)->assertOk()->assertSee('共 1 条');
        $this->get('/user/login-history?from='.$day.'&to='.$day)->assertOk()->assertSee('共 1 条');
    }
    public function testIpHeaderCannotSpoofAnUntrustedProxy(): void
    {
        $user=$this->user();
        $request=Request::create('/test','POST',[],[],[],['REMOTE_ADDR'=>'203.0.113.7','HTTP_X_FORWARDED_FOR'=>'198.51.100.99','HTTP_USER_AGENT'=>"Client\r\nInjected"]);
        Symfony\Component\HttpFoundation\Request::setTrustedProxies([],0);
        app(Recorder::class)->record($user->uid,'web','login','web',$request);
        $this->assertDatabaseHas(Installer::ENTRIES,['ip'=>'203.0.113.7','user_agent'=>'ClientInjected']);
    }
    public function testUnsupportedControllersCannotRegisterYggCapture(): void
    {
        $route=new Illuminate\Routing\Route('POST','fake',['uses'=>App\Http\Controllers\AuthController::class.'@handleLogin']);
        $request=Request::create('/fake','POST');
        app(Observer::class)->route(new Illuminate\Routing\Events\RouteMatched($route,$request));
        self::assertNotContains(CaptureYggdrasil::class,$route->middleware());
        $route=new Illuminate\Routing\Route('POST','api/yggdrasil/authserver/refresh',['uses'=>\LittleSkin\YggdrasilConnect\Controllers\AuthController::class.'@refresh','controller'=>\LittleSkin\YggdrasilConnect\Controllers\AuthController::class.'@refresh']);
        app(Observer::class)->route(new Illuminate\Routing\Events\RouteMatched($route,$request));
        self::assertContains(CaptureYggdrasil::class,$route->middleware(),$route->getActionName().' '.$request->method());
        self::assertSame('yggdrasil-connect',$request->attributes->get('login_history.backend'));
    }
    public function testTablePrefixAndCascadesAreSupported(): void
    {
        $old=DB::getDefaultConnection();config(['database.connections.prefixed'=>array_merge(config('database.connections.sqlite'),['database'=>':memory:','prefix'=>'skin_'])]);
        DB::setDefaultConnection('prefixed');Schema::clearResolvedInstance('db.schema');
        try {
            Schema::create('users',function($table){$table->increments('uid');});Installer::install();
            DB::table('users')->insert(['uid'=>99]);
            $id=DB::table(Installer::ENTRIES)->insertGetId(['user_id'=>99,'source'=>'web','action'=>'login','backend'=>'web','occurred_at'=>now('UTC')]);
            DB::table(Installer::PROFILES)->insert(['entry_id'=>$id,'player_name'=>'PrefixRole','selected'=>true]);
            DB::table(Installer::ENTRIES)->where('user_id',99)->delete();
            self::assertSame(0,DB::table(Installer::ENTRIES)->count());self::assertSame(0,DB::table(Installer::PROFILES)->count());
        } finally {DB::setDefaultConnection($old);Schema::clearResolvedInstance('db.schema');}
    }
}
