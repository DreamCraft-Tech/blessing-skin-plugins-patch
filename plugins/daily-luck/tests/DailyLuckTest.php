<?php

use App\Events\ConfigureRoutes;
use App\Models\User;
use App\Services\Plugin;
use App\Services\PluginManager;
use Blessing\DailyLuck\Luck;
use Blessing\DailyLuck\Settings;
use Carbon\CarbonImmutable;

final class DailyLuckTest extends Tests\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $path = dirname(__DIR__);
        $plugin = new Plugin($path, json_decode(file_get_contents($path.'/package.json'), true));
        $manager = app(PluginManager::class);
        $manager->all()->put('daily-luck', $plugin);
        self::assertTrue($manager->enable($plugin));
        (new ReflectionProperty($manager, 'booted'))->setValue($manager, false);
        $manager->boot();
        event(new ConfigureRoutes(app('router')));
        app('router')->getRoutes()->refreshNameLookups();
        config(['app.key' => '12345678901234567890123456789012', 'app.timezone' => 'Asia/Shanghai']);
    }

    public function testOpeningPersistsAcrossRequestsAndDisablesButtonWithResultExpanded(): void
    {
        $user = User::factory()->create();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-24 12:00:00', 'Asia/Shanghai'));
        $this->actingAs($user)->get('/user')->assertOk()->assertSee('查看今日人品')->assertDontSee('明天再来看看？');
        $this->post('/user/daily-luck/reveal')->assertRedirect('/user#daily-luck-card');
        $this->assertDatabaseHas(Blessing\DailyLuck\Installer::TABLE, ['user_id' => $user->uid, 'viewed_date' => '2026-10-24']);
        $this->get('/user')->assertOk()->assertSee('明天再来看看？')->assertSee('disabled aria-disabled="true"', false)
            ->assertSee('id="daily-luck-result" class="mt-3" ', false)->assertDontSee('data-toggle="collapse"', false);
        $result = app(Blessing\DailyLuck\Viewed::class)->result($user->uid);
        self::assertTrue($result['opened']);
        $this->post('/user/daily-luck/reveal')->assertRedirect('/user#daily-luck-card');
        self::assertSame($result, app(Blessing\DailyLuck\Viewed::class)->result($user->uid));
        $this->assertDatabaseCount(Blessing\DailyLuck\Installer::TABLE, 1);
        Illuminate\Support\Facades\Auth::forgetGuards();
        $this->actingAs($user->fresh())->get('/user')->assertOk()->assertSee('明天再来看看？');
    }

    public function testNextDayUnlocksWithoutChangingThePreviousScoreAlgorithm(): void
    {
        $user = User::factory()->create();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-24 23:59:59', 'Asia/Shanghai'));
        $this->actingAs($user)->post('/user/daily-luck/reveal')->assertRedirect();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-25 00:00:00', 'Asia/Shanghai'));
        $this->get('/user')->assertOk()->assertSee('查看今日人品')->assertDontSee('明天再来看看？');
        self::assertFalse(app(Blessing\DailyLuck\Viewed::class)->result($user->uid)['opened']);
        $this->post('/user/daily-luck/reveal')->assertRedirect();
        $this->assertDatabaseHas(Blessing\DailyLuck\Installer::TABLE, ['user_id' => $user->uid, 'viewed_date' => '2026-10-25']);
        self::assertTrue(app(Blessing\DailyLuck\Viewed::class)->result($user->uid)['opened']);
    }

    public function testOpeningUsesAuthenticatedAccountAndGuestCannotOpen(): void
    {
        $this->postJson('/user/daily-luck/reveal')->assertUnauthorized();
        $first = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($first)->post('/user/daily-luck/reveal', ['uid' => $other->uid, 'user_id' => $other->uid, 'date' => '2099-01-01'])->assertRedirect();
        self::assertTrue(app(Blessing\DailyLuck\Viewed::class)->result($first->uid)['opened']);
        self::assertFalse(app(Blessing\DailyLuck\Viewed::class)->result($other->uid)['opened']);
        $first->delete();
        $this->assertDatabaseCount(Blessing\DailyLuck\Installer::TABLE, 0);
    }

    public function testRevealRequiresCsrfAndMissingTableCanBeRepaired(): void
    {
        Illuminate\Support\Facades\Schema::drop(Blessing\DailyLuck\Installer::TABLE);
        Blessing\DailyLuck\Installer::install();
        self::assertTrue(Illuminate\Support\Facades\Schema::hasTable(Blessing\DailyLuck\Installer::TABLE));
        $this->actingAs(User::factory()->create());
        app()->instance('env', 'production');
        $this->postJson('/user/daily-luck/reveal')->assertStatus(419);
        $this->assertDatabaseCount(Blessing\DailyLuck\Installer::TABLE, 0);
    }

    public function testFeatureSwitchesIndependentlyControlWidgetsAndRevealEndpoint(): void
    {
        $user = User::factory()->superAdmin()->create();
        $this->actingAs($user);
        $form = ['title' => '组合卡片', 'position' => 'right', 'display' => 'reveal', 'texts' => ''];
        foreach ([[1, 0], [0, 1], [0, 0], [1, 1]] as [$luck,$prank]) {
            $this->post('/admin/daily-luck/config', $form + ['luck_enabled' => $luck, 'prank_enabled' => $prank])->assertRedirect();
            $response = $this->get('/user')->assertOk();
            if ($luck) {
                $response->assertSee('查看今日人品');
            } else {
                $response->assertDontSee('id="daily-luck-result"', false);
                $this->post('/user/daily-luck/reveal')->assertNotFound();
            }
            if ($prank) {
                $response->assertSee('id="daily-prank-trigger"', false);
            } else {
                $response->assertDontSee('id="daily-prank-trigger"', false);
            }
            if (!$luck && !$prank) {
                $response->assertDontSee('id="daily-luck-card"', false);
            }
        }
    }

    public function testCustomTextLinesAreNormalizedStableEscapedAndDoNotChangeScore(): void
    {
        $user = User::factory()->superAdmin()->create();
        $this->actingAs($user);
        $before = app(Luck::class)->today($user->uid);
        $this->post('/admin/daily-luck/config', ['title' => '组合', 'position' => 'right', 'display' => 'visible', 'texts' => " 第一条 \r\n\n第二条\r第三条 "])->assertRedirect();
        self::assertSame(['第一条', '第二条', '第三条'], app(Settings::class)->texts());
        $one = app(Luck::class)->today($user->uid);
        self::assertContains($one['text'], app(Settings::class)->texts());
        self::assertSame($before['score'], $one['score']);
        self::assertSame($one, app(Luck::class)->today($user->uid));
        $form = ['title' => '组合', 'position' => 'right', 'display' => 'visible', 'texts' => '<script>customLuck()</script>'];
        $this->post('/admin/daily-luck/config', $form)->assertRedirect();
        $this->get('/user')->assertOk()->assertSee('&lt;script&gt;customLuck()&lt;/script&gt;', false)->assertDontSee('<script>customLuck()</script>', false);
        $this->post('/admin/daily-luck/config', array_replace($form, ['texts' => '']))->assertRedirect();
        self::assertSame($before['text'], app(Luck::class)->today($user->uid)['text']);
    }

    public function testCustomTextAndEffectValidationKeepsSavedSettings(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $before = app(Settings::class)->values();
        $form = ['title' => '组合', 'position' => 'right', 'display' => 'reveal'];
        foreach ([['texts' => str_repeat('字', 201)], ['texts' => implode("\n", array_fill(0, 101, '短评'))], ['effect' => 'evil'], ['motion' => 'fast'], ['duration' => 999], ['luck_enabled' => 2], ['prank_enabled' => 'yes']] as $invalid) {
            $this->postJson('/admin/daily-luck/config', $form + $invalid)->assertStatus(422);
        }
        self::assertSame($before, app(Settings::class)->values());
    }

    public function testLegacySettingsAndOpenedDateSurviveCombinedUpgrade(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/user/daily-luck/reveal')->assertRedirect();
        option(['daily_luck_settings' => json_encode(['title' => '原来的标题', 'position' => 'left', 'display' => 'reveal'])]);
        $s = app(Settings::class)->values();
        self::assertTrue($s['luck_enabled']);
        self::assertTrue($s['prank_enabled']);
        $this->get('/user')->assertOk()->assertSee('原来的标题')->assertSee('明天再来看看？')->assertSee('千万别点')->assertSee('prank.js?v=1.1.0', false);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function testScoreIsFixedPerUserAndDateAndWithinRange(): void
    {
        $luck = app(Luck::class);
        $date = CarbonImmutable::parse('2026-10-24 12:00:00', 'Asia/Shanghai');
        $scores = [];
        for ($uid = 1; $uid <= 200; $uid++) {
            $one = $luck->today($uid, $date);
            $two = $luck->today($uid, $date->addHours(1));
            self::assertSame($one, $two);
            self::assertGreaterThanOrEqual(0, $one['score']);
            self::assertLessThanOrEqual(100, $one['score']);
            $scores[] = $one['score'];
        }
        self::assertGreaterThan(70, count(array_unique($scores)));
    }

    public function testDailyBoundaryUsesSiteTimezoneAndSameInstantAcrossTimezones(): void
    {
        $luck = app(Luck::class);
        $before = CarbonImmutable::parse('2026-10-24 15:59:59', 'UTC');
        $after = $before->addSecond();
        self::assertSame('2026-10-24', $luck->today(12, $before)['date']);
        self::assertSame('2026-10-25', $luck->today(12, $after)['date']);
        self::assertSame($luck->today(12, $after), $luck->today(12, $after->setTimezone('America/New_York')));
        $values = [];
        for ($d = 0; $d < 10; $d++) {
            $values[] = $luck->today(12, $after->addDays($d))['score'];
        }
        self::assertGreaterThan(1, count(array_unique($values)));
    }

    public function testEveryScoreHasTextAndBoundaryEasterEggs(): void
    {
        foreach (range(0, 100) as $score) {
            $item = app(Luck::class)->describe($score);
            self::assertNotEmpty($item['text']);
            self::assertContains($item['color'], ['success', 'info', 'primary', 'secondary']);
        }
        self::assertSame('幸运满格', app(Luck::class)->describe(100)['label']);
        self::assertSame('触发隐藏彩蛋', app(Luck::class)->describe(0)['label']);
    }

    public function testDashboardShowsCardWithOriginalWidgetsAndPrivateCache(): void
    {
        $user = User::factory()->create();
        $score = $user->score;
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-24 12:00:00', 'Asia/Shanghai'));
        $this->actingAs($user)->get('/user')->assertOk()->assertSee('今日人品')->assertSee('2026-10-24')
            ->assertSee('查看今日人品')->assertSee('id="daily-luck-result"', false)->assertSee('id="usage-box"', false)
            ->assertHeader('Cache-Control', 'no-store, private');
        self::assertSame($score, $user->fresh()->score);
        $expected = app(Luck::class)->today($user->uid);
        $this->get('/user')->assertOk()->assertSee($expected['text']);
    }

    public function testGridPreservesPluginsAndAppendsOnceToConfiguredColumn(): void
    {
        $grid = ['layout' => [['md-7', 'md-5']], 'widgets' => [[['other.left'], ['other.right']]]];
        $filter = app(Blessing\Filter::class);
        $out = $filter->apply('grid:user.index', $grid);
        $again = $filter->apply('grid:user.index', $out);
        self::assertSame(['other.left'], $out['widgets'][0][0]);
        self::assertSame(['other.right', 'Blessing\\DailyLuck::card'], $out['widgets'][0][1]);
        self::assertSame($out, $again);
        option(['daily_luck_settings' => json_encode(['position' => 'left'])]);
        $out = $filter->apply('grid:user.index', $grid);
        self::assertSame(['other.left', 'Blessing\\DailyLuck::card'], $out['widgets'][0][0]);
        self::assertSame(['other.right'], $out['widgets'][0][1]);
    }

    public function testSuperAdminConfigSaveAndVisibleModeEscapeTitle(): void
    {
        $user = User::factory()->superAdmin()->create();
        $this->actingAs($user);
        $this->post('/admin/daily-luck/config', ['title' => '<b>我的今日人品</b>', 'position' => 'left', 'display' => 'visible'])->assertRedirect('/admin/plugins/config/daily-luck')->assertSessionHas('daily_luck.notice');
        $this->get('/admin/plugins/config/daily-luck')->assertOk()->assertSee('仪表板卡片')->assertSee('今日人品与千万别点配置已保存。');
        self::assertSame('visible', app(Settings::class)->values()['display']);
        $this->get('/user')->assertOk()->assertSee('&lt;b&gt;我的今日人品&lt;/b&gt;', false)->assertDontSee('查看今日人品')->assertDontSee('<b>我的今日人品</b>', false);
    }

    public function testPermissionsAndValidation(): void
    {
        $data = ['title' => '每日人品', 'position' => 'left', 'display' => 'visible'];
        $this->actingAs(User::factory()->create())->get('/admin/plugins/config/daily-luck')->assertForbidden();
        $this->post('/admin/daily-luck/config', $data)->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->get('/admin/plugins/config/daily-luck')->assertOk()->assertSee('disabled');
        $this->post('/admin/daily-luck/config', $data)->assertForbidden();
        $this->actingAs(User::factory()->superAdmin()->create());
        $this->postJson('/admin/daily-luck/config', ['title' => '', 'position' => 'bad', 'display' => 'bad'])->assertStatus(422)->assertJsonValidationErrors(['title', 'position', 'display']);
        self::assertSame('right', app(Settings::class)->values()['position']);
    }

    public function testGuestCardIsEmptyAndConfigCsrfIsEnforced(): void
    {
        self::assertStringNotContainsString('daily-luck-card', view('Blessing\\DailyLuck::card')->render());
        app()->instance('env', 'production');
        $this->postJson('/admin/daily-luck/config', [])->assertStatus(419);
    }
}
