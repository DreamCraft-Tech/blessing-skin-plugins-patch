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
        $this->actingAs($user)->get('/user')->assertOk()->assertSee('每日人品')->assertSee('2026-10-24')
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
        $this->get('/admin/plugins/config/daily-luck')->assertOk()->assertSee('仪表板卡片')->assertSee('每日人品配置已保存。');
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
