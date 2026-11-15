<?php
namespace Funseco\TimeLimit\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Funseco\Core\Testing\FakesServer;
use Funseco\TimeLimit\Tests\TestCase;
use Funseco\Core\Models\Map;
use Funseco\Core\Models\Player;
use Funseco\Core\Services\Cache\CurrentMap;
use Funseco\Core\Services\Cache\CurrentPlayers;
use Funseco\Core\Services\Cache\CurrentSpectators;
use Funseco\Core\Services\Chat\Facades\Chat;
use Funseco\Core\Services\Defaults\NullTimeLimit;
use Funseco\Core\Services\ManiaLink\Facades\ManiaLink;
use Funseco\Core\SharedKernel\TimeLimit;
use Funseco\Core\SharedKernel\UI\WidgetRegistry;
use Funseco\TimeLimit\AutoTimeLimit;
use Funseco\TimeLimit\ClockWidget;
use Funseco\TimeLimit\CustomTime;
use Funseco\TimeLimit\FlexiTimer;
use Funseco\TimeLimit\StartingTime;
use Funseco\TimeLimit\Text;

class TimeLimitTest extends TestCase
{
    use FakesServer;
    use RefreshDatabase;

    private Player $boss;
    private Player $admin;
    private Player $op;
    private Player $racer;
    private Map $map;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeServer();
        state()->flush();
        config([
            'adminops.masteradmins' => ['boss'],
            'adminops.admins' => ['adm'],
            'adminops.operators' => ['op'],
            'adminops.required_tag' => null,
        ]);
        $this->boss = Player::factory()->create(['login' => 'boss', 'nickname' => 'Boss']);
        $this->admin = Player::factory()->create(['login' => 'adm', 'nickname' => '$f00Nick']);
        $this->op = Player::factory()->create(['login' => 'op', 'nickname' => 'Op']);
        $this->racer = Player::factory()->create(['login' => 'racer', 'nickname' => 'Racer']);
        foreach ([$this->boss, $this->admin, $this->op, $this->racer] as $player) {
            CurrentPlayers::add($player);
        }
        $this->map = Map::factory()->create(['uid' => 'uid1', 'name' => '$fffGamac $o$f00Filoc', 'author_time' => 41170]);
        CurrentMap::set($this->map);
    }

    private function timer(): FlexiTimer
    {
        return app(FlexiTimer::class);
    }

    private function startMap(): void
    {
        $this->timer()->start($this->map);
        $this->calls = [];
    }

    private function toLogin(string $login): array
    {
        return array_values(array_map(fn ($a) => $a[0], array_filter($this->sent('ChatSendServerMessageToLogin'), fn ($a) => $a[1] === $login)));
    }

    private function clockXml(): string
    {
        $sent = $this->sent('SendDisplayManialinkPage');

        return (string) (end($sent)[0] ?? '');
    }

    // --- FlexiTime: starting time ---------------------------------------------------------

    public function test_default_start_is_five_minutes()
    {
        $this->startMap();

        $this->assertSame(300, $this->timer()->secondsLeft());
        $this->assertSame(300, $this->timer()->limit());
        $this->assertFalse($this->timer()->paused());
    }

    public function test_custom_time_wins_when_enabled()
    {
        CustomTime::store('uid1', 7 * 60);
        $this->assertSame(420, StartingTime::for($this->map));

        config(['timelimit.flexitime.custom_time' => false]);
        $this->assertSame(300, StartingTime::for($this->map));
    }

    public function test_author_multiplier_rounds_up_to_minutes_and_clamps_max_first()
    {
        config(['timelimit.flexitime.author_mult' => 3, 'timelimit.flexitime.default_time' => 10, 'timelimit.flexitime.min_time' => 1]);
        // 41.17 s × 3 = 2.06 min → 3 min
        $this->assertSame(180, StartingTime::for($this->map));

        $this->map->author_time = 300000;   // 15 min > max 10
        $this->assertSame(600, StartingTime::for($this->map));

        config(['timelimit.flexitime.min_time' => 15]);
        $this->map->author_time = 41170;    // 3 min < min 15 (but max checked first: 3 < 10)
        $this->assertSame(900, StartingTime::for($this->map));
    }

    // --- FlexiTime: countdown and clock -----------------------------------------------------

    public function test_tick_counts_down_and_redraws_the_clock()
    {
        $this->startMap();
        $this->timer()->tick();

        $this->assertSame(299, $this->timer()->secondsLeft());
        $xml = $this->clockXml();
        $this->assertStringContainsString('<manialink id="'.ManiaLink::getAction(ClockWidget::NAME).'">', $xml);
        $placement = app(WidgetRegistry::class)->placement(ClockWidget::NAME);
        $this->assertStringContainsString('<frame posn="'.$placement->x.' '.($placement->y - 2.5).' 0" scale="1">', $xml);
        $this->assertStringContainsString('style="BgsPlayerCard" substyle="BgPlayerCardBig"', $xml);
        $this->assertStringContainsString('scale="0.6" style="TextRaceChrono" text="$s$fff04:59"', $xml);
    }

    public function test_clock_turns_yellow_below_warn_time_or_author_time()
    {
        $this->map->author_time = 90000;
        $this->startMap();
        $this->timer()->set(91);
        $this->assertSame('fff', $this->timer()->colour());
        $this->timer()->set(89);
        $this->assertSame('ff4', $this->timer()->colour());

        config(['timelimit.flexitime.danger_time' => 30]);
        $this->timer()->set(29);
        $this->assertSame('f44', $this->timer()->colour());
    }

    public function test_reaching_zero_forces_the_next_map_once()
    {
        $this->startMap();
        $this->timer()->set(1);
        $this->timer()->tick();
        $this->timer()->tick();

        $this->assertSame(0, $this->timer()->secondsLeft());
        $this->assertTrue($this->timer()->paused());
        $this->assertCount(1, $this->sent('NextChallenge'));
    }

    public function test_map_end_pauses_and_hides_the_clock_until_next_map()
    {
        $this->startMap();
        $this->timer()->tick();
        $this->timer()->stop();
        $this->timer()->tick();

        $this->assertSame(299, $this->timer()->secondsLeft());
        $this->assertStringContainsString('"></manialink>', $this->clockXml());
        $this->assertCount(1, array_filter($this->sent('SendDisplayManialinkPage'), fn ($a) => str_ends_with($a[0], '"></manialink>')));

        $this->timer()->start($this->map);
        $this->assertSame(300, $this->timer()->secondsLeft());
        $this->assertFalse($this->timer()->paused());
    }

    public function test_map_load_holds_the_full_limit_until_the_race_starts()
    {
        \Funseco\Core\Services\Cache\ServerInfo::setGameMode(1);
        $this->timer()->prepare($this->map);   // MapStarted (BeginChallenge)
        $this->assertTrue($this->timer()->waiting());
        $this->assertStringContainsString('05:00', $this->clockXml());

        // players still loading: the countdown does not move
        $this->timer()->tick();
        $this->timer()->tick();
        $this->assertSame(300, $this->timer()->secondsLeft());

        event(new \Funseco\Core\SharedKernel\Events\RoundStarted);
        $this->assertFalse($this->timer()->waiting());
        $this->timer()->tick();
        $this->assertSame(299, $this->timer()->secondsLeft());
    }

    public function test_status_play_releases_a_held_timer_without_resetting_a_running_one()
    {
        $this->timer()->prepare($this->map);   // MapStarted (BeginChallenge)
        event(new \Funseco\Core\SharedKernel\Events\GameStatusChanged(4, 'Running - Play'));
        $this->assertFalse($this->timer()->waiting());

        $this->timer()->tick();
        event(new \Funseco\Core\SharedKernel\Events\GameStatusChanged(4, 'Running - Play'));
        event(new \Funseco\Core\SharedKernel\Events\RoundStarted);
        $this->assertSame(299, $this->timer()->secondsLeft());
    }

    public function test_round_modes_reset_and_stop_the_timer_every_round_but_time_attack_does_not()
    {
        CurrentMap::set($this->map);
        $this->startMap();
        foreach ([1 => false, 0 => true, 2 => true, 3 => true, 5 => true] as $mode => $perRound) {
            \Funseco\Core\Services\Cache\ServerInfo::setGameMode($mode);
            $this->timer()->set(100);
            event(new \Funseco\Core\SharedKernel\Events\RoundStarted);
            $this->assertSame($perRound ? 300 : 100, $this->timer()->secondsLeft(), "mode $mode start");
            $this->assertFalse($this->timer()->paused());

            event(new \Funseco\Core\SharedKernel\Events\RoundEnded);
            $this->assertSame($perRound, $this->timer()->paused(), "mode $mode end");
            $this->timer()->resume();
        }
    }

    public function test_no_clock_when_show_panel_is_off()
    {
        config(['timelimit.flexitime.show_panel' => false]);
        $this->startMap();
        $this->timer()->tick();

        $this->assertSame([], $this->sent('SendDisplayManialinkPage'));
    }

    public function test_clock_overlaps_no_other_right_hand_widget_in_any_gamemode()
    {
        $grid = app(WidgetRegistry::class);
        $overlap = fn ($a, $b) => $a->x < $b->x + $b->width && $b->x < $a->x + $a->width
            && $a->y > $b->y - $b->height && $b->y > $a->y - $a->height;

        foreach (WidgetRegistry::GAMEMODES as $mode) {
            $clock = $grid->placement(ClockWidget::NAME, $mode);
            $this->assertNotNull($clock, $mode);
            foreach (['karma', 'local_records', 'live_rankings', 'dedimania_records'] as $other) {
                if ($p = $grid->placement($other, $mode)) {
                    $this->assertFalse($overlap($clock, $p), "$mode: clock overlaps $other");
                }
            }
        }
    }

    public function test_time_strings()
    {
        $this->assertSame('05:00', Text::clock(300));
        $this->assertSame('01:30:00', Text::clock(5400));
        $this->assertSame('00:47.32', Text::raceTime(47320));

        $this->startMap();
        $this->timer()->set(5400);
        $this->assertSame(format_colors('{#highlite}01:30:00{#server} (h:m:s) until round end.'), $this->timer()->text());
        $this->timer()->pause();
        $this->assertSame(format_colors('{#highlite}01:30:00{#server} (h:m:s) until round end (paused).'), $this->timer()->text());
    }

    public function test_periodic_chat_when_enabled()
    {
        config(['timelimit.flexitime.use_chat' => true]);
        $this->timer()->start($this->map);
        $this->assertSame([format_colors('{#server}> {#highlite}05:00{#server} (m:s) until round end.')], $this->messages('ChatSendServerMessage'));

        $this->calls = [];
        $this->timer()->set(61);
        $this->timer()->tick();   // 60 → 1 minute
        $this->timer()->set(32);
        $this->timer()->tick();   // 31: nothing
        $this->timer()->tick();   // 30
        $this->assertSame([format_colors('{#server}> {#highlite}01:00{#server} (m:s) until round end.'), format_colors('{#server}> {#highlite}00:30{#server} (m:s) until round end.')], $this->messages('ChatSendServerMessage'));
    }

    public function test_periodic_chat_is_off_by_default()
    {
        $this->timer()->start($this->map);
        $this->timer()->set(61);
        $this->timer()->tick();

        $this->assertSame([], $this->messages('ChatSendServerMessage'));
    }

    // --- /timeleft --------------------------------------------------------------------------

    public function test_anyone_can_query_time_left()
    {
        $this->startMap();
        Chat::resolve('/timeleft', 'racer');

        $this->assertSame([format_colors('{#highlite}05:00{#server} (m:s) until round end.')], $this->toLogin('racer'));
    }

    public function test_players_and_operators_cannot_change_time()
    {
        $this->startMap();
        Chat::resolve('/timeleft +10', 'racer');
        Chat::resolve('/timeleft pause', 'op');

        $this->assertSame([format_colors('{#server}> {#error}You do not have permission to change the remaining time.')], $this->toLogin('racer'));
        $this->assertSame([format_colors('{#server}> {#error}You do not have permission to change the remaining time.')], $this->toLogin('op'));
        $this->assertSame(300, $this->timer()->secondsLeft());
        $this->assertFalse($this->timer()->paused());
    }

    public function test_admin_adds_subtracts_and_sets_minutes()
    {
        $this->startMap();
        $this->timer()->set(192);   // 03:12

        Chat::resolve('/timeleft +10', 'adm');
        $this->assertSame(792, $this->timer()->secondsLeft());
        Chat::resolve('/timeleft -2', 'adm');
        $this->assertSame(672, $this->timer()->secondsLeft());
        Chat::resolve('/timeleft 5abc', 'boss');
        $this->assertSame(300, $this->timer()->secondsLeft());
        Chat::resolve('/timeleft 1.5', 'boss');
        $this->assertSame(60, $this->timer()->secondsLeft());

        $this->assertSame([
            format_colors('{#server}> {#highlite}Nick{#server} changed time left: {#highlite}13:12{#server} (m:s) until round end.'),
            format_colors('{#server}> {#highlite}Nick{#server} changed time left: {#highlite}11:12{#server} (m:s) until round end.'),
            format_colors('{#server}> {#highlite}Boss{#server} changed time left: {#highlite}05:00{#server} (m:s) until round end.'),
            format_colors('{#server}> {#highlite}Boss{#server} changed time left: {#highlite}01:00{#server} (m:s) until round end.'),
        ], $this->messages('ChatSendServerMessage'));
    }

    public function test_invalid_and_negative_parameters()
    {
        $this->startMap();
        foreach (['+0', '-0', 'abc', '+', '+x', '00'] as $arg) {
            Chat::resolve("/timeleft $arg", 'adm');
        }
        Chat::resolve('/timeleft -6', 'adm');

        $this->assertSame(
            [...array_fill(0, 6, format_colors('{#server}> {#error}Invalid parameter to /timeleft.')), format_colors('{#server}> {#error}Can\'t set remaining time to less than zero.')],
            $this->toLogin('adm'),
        );
        $this->assertSame(300, $this->timer()->secondsLeft());
    }

    public function test_setting_zero_announces_then_forces_next_map()
    {
        $this->startMap();
        Chat::resolve('/timeleft 0', 'adm');

        $this->assertSame([format_colors('{#server}> {#highlite}Nick{#server} changed time left: {#highlite}00:00{#server} (m:s) until round end.')], $this->messages('ChatSendServerMessage'));
        $this->assertCount(1, $this->sent('NextChallenge'));
        $this->assertTrue($this->timer()->paused());
    }

    public function test_pause_and_resume()
    {
        $this->startMap();
        Chat::resolve('/timeleft PAUSE', 'adm');
        $this->timer()->tick();
        $this->assertSame(300, $this->timer()->secondsLeft());

        Chat::resolve('/timeleft +1', 'adm');   // stays paused
        $this->assertTrue($this->timer()->paused());

        Chat::resolve('/timeleft resume', 'adm');
        $this->timer()->tick();
        $this->assertSame(359, $this->timer()->secondsLeft());

        $this->assertSame([
            format_colors('{#server}> {#highlite}adm{#server} paused the timer.'),
            format_colors('{#server}> {#highlite}Nick{#server} changed time left: {#highlite}06:00{#server} (m:s) until round end (paused).'),
            format_colors('{#server}> {#highlite}adm{#server} unpaused the timer.'),
        ], $this->messages('ChatSendServerMessage'));
    }

    public function test_resume_after_expiry_forces_next_map_again()
    {
        $this->startMap();
        $this->timer()->set(0);
        $this->timer()->expireIfDone();
        Chat::resolve('/timeleft resume', 'adm');
        $this->timer()->tick();

        $this->assertCount(2, $this->sent('NextChallenge'));
    }

    // --- /tl ----------------------------------------------------------------------------------

    public function test_tl_sets_five_minutes_and_ignores_arguments()
    {
        $this->startMap();
        $this->timer()->set(20);
        Chat::resolve('/tl 99', 'adm');

        $this->assertSame(300, $this->timer()->secondsLeft());
        $this->assertSame([format_colors('{#server}> {#highlite}Nick{#server} changed time left: {#highlite}05:00{#server} (m:s) until round end.')], $this->messages('ChatSendServerMessage'));
    }

    public function test_tl_needs_permission()
    {
        $this->startMap();
        $this->timer()->set(20);
        Chat::resolve('/tl', 'op');

        $this->assertSame(20, $this->timer()->secondsLeft());
    }

    // --- /timeset -----------------------------------------------------------------------------

    public function test_timeset_stores_minutes_for_the_next_start_only()
    {
        $this->startMap();
        Chat::resolve('/timeset 120', 'adm');

        $this->assertSame(7200, CustomTime::secondsFor('uid1'));
        $this->assertSame(300, $this->timer()->secondsLeft());
        $this->assertSame([format_colors('{#server}> {#highlite}adm{#server} set future time for this track to {#highlite}120{#server} minutes.')], $this->messages('ChatSendServerMessage'));

        Chat::resolve('/timeset 8', 'adm');   // update, one row per map
        $this->assertSame(1, CustomTime::count());
        $this->timer()->start($this->map);
        $this->assertSame(480, $this->timer()->secondsLeft());
    }

    public function test_timeset_rejects_zero_negative_and_non_numbers()
    {
        foreach (['0', '-5', 'abc', ''] as $arg) {
            Chat::resolve(trim("/timeset $arg"), 'adm');
        }

        $this->assertSame(array_fill(0, 4, format_colors('{#server}> {#highlite}Usage (where 120 is number of minutes): /timeset 120')), $this->toLogin('adm'));
        $this->assertSame(0, CustomTime::count());
    }

    public function test_timeset_disabled_and_guarded()
    {
        Chat::resolve('/timeset 5', 'racer');
        $this->assertSame(0, CustomTime::count());

        config(['timelimit.flexitime.custom_time' => false]);
        Chat::resolve('/timeset 5', 'adm');
        $this->assertSame([format_colors('{#server}> {#error}/timeset command not enabled in plugin config.')], $this->toLogin('adm'));
    }

    public function test_help_lists_commands_only_for_those_who_may_change_time()
    {
        $names = fn (Player $p) => array_column(Chat::commands($p), 'name');

        $this->assertContains('timeleft', $names($this->racer));
        $this->assertNotContains('tl', $names($this->racer));
        $this->assertContains('tl', $names($this->admin));
        $this->assertContains('timeset', $names($this->boss));
    }

    // --- Shared contract --------------------------------------------------------------------

    public function test_time_limit_contract_is_the_flexi_timer()
    {
        $this->assertNull((new NullTimeLimit)->secondsLeft());
        $this->assertNull((new NullTimeLimit)->limit());
        $this->assertSame($this->timer(), app(TimeLimit::class));
        $this->assertNull(app(TimeLimit::class)->secondsLeft());

        $this->startMap();
        $this->assertSame(300, app(TimeLimit::class)->secondsLeft());
    }

    // --- Auto TimeLimit -----------------------------------------------------------------------

    private function enableAuto(float $mult = 2, float $min = 1, float $max = 10, float $default = 5.5): void
    {
        config(['timelimit.autotime' => [
            'enabled' => true, 'multiplicator' => $mult, 'mintime' => $min, 'maxtime' => $max,
            'defaulttime' => $default, 'display' => 1,
        ]]);
        $this->responses['GetNextGameInfo'] = ['GameMode' => 1];
        $this->responses['GetNextChallengeInfo'] = ['Name' => '$fffGamac $o$f00Filoc', 'AuthorTime' => 41170];
        $this->responses['GetCurrentChallengeInfo'] = ['Name' => 'Current', 'AuthorTime' => 50000];
    }

    public function test_limit_formula()
    {
        $config = ['multiplicator' => 2, 'mintime' => 1, 'maxtime' => 10, 'defaulttime' => 5.5];
        $this->assertSame([82000, 'new'], AutoTimeLimit::limitFor(41170, $config));   // 82340 floored to seconds
        $this->assertSame([330000, 'default'], AutoTimeLimit::limitFor(0, $config));
        $this->assertSame([60000, 'min'], AutoTimeLimit::limitFor(20000, $config));
        $this->assertSame([600000, 'max'], AutoTimeLimit::limitFor(400000, $config));
        // Owner: min = max = default = 5.5
        $this->assertSame([330000, 'min'], AutoTimeLimit::limitFor(41170, ['multiplicator' => 1, 'mintime' => 5.5, 'maxtime' => 5.5, 'defaulttime' => 5.5]));
    }

    public function test_race_end_sets_next_map_limit_and_announces()
    {
        $this->enableAuto(10, 5.5, 5.5);
        app(AutoTimeLimit::class)->raceEnded();

        $this->assertSame([[330000]], $this->sent('SetTimeAttackLimit'));
        $this->assertSame([], $this->sent('GetCurrentChallengeInfo'));
        $message = $this->messages('ChatSendServerMessage')[0];
        $this->assertStringContainsString('Set max timelimit for ', $message);
        $this->assertStringContainsString('Gamac Filoc', $message);
        $this->assertStringContainsString('05:30', $message);
        $this->assertStringContainsString('00:41.17', $message);
    }

    public function test_only_spectators_uses_the_current_map()
    {
        $this->enableAuto();
        foreach (CurrentPlayers::all() as $player) {
            CurrentSpectators::add($player);
        }
        app(AutoTimeLimit::class)->raceEnded();

        $this->assertCount(1, $this->sent('GetCurrentChallengeInfo'));
        $this->assertSame([[100000]], $this->sent('SetTimeAttackLimit'));
    }

    public function test_skips_when_off_disabled_restarting_or_not_time_attack()
    {
        $auto = app(AutoTimeLimit::class);

        // Owner config: multiplicator 0 does nothing
        $auto->raceEnded();
        $this->assertSame([], $this->sent('GetNextGameInfo'));

        $this->enableAuto();
        $auto->raceEnded(restart: true);
        $auto->restarting();
        $auto->raceEnded();             // skipped once, flag cleared
        $this->assertSame([], $this->sent('SetTimeAttackLimit'));

        $auto->setActive(false);
        $auto->raceEnded();
        $this->assertSame([], $this->sent('SetTimeAttackLimit'));

        $auto->setActive(true);
        $this->responses['GetNextGameInfo'] = ['GameMode' => 0];
        $auto->raceEnded();
        $this->assertSame([], $this->sent('SetTimeAttackLimit'));

        $this->responses['GetNextGameInfo'] = ['GameMode' => 1];
        $auto->raceEnded();
        $this->assertCount(1, $this->sent('SetTimeAttackLimit'));
    }

    public function test_restart_flag_is_cleared_even_while_inactive()
    {
        $this->enableAuto();
        $auto = app(AutoTimeLimit::class);
        $this->responses['GetNextGameInfo'] = ['GameMode' => 1];
        $auto->setActive(false);
        $auto->restarting();
        $auto->raceEnded();
        $auto->setActive(true);
        $auto->raceEnded();             // the stale flag must not swallow this one
        $this->assertCount(1, $this->sent('SetTimeAttackLimit'));
    }

    public function test_display_zero_sets_silently()
    {
        $this->enableAuto();
        config(['timelimit.autotime.display' => 0]);
        app(AutoTimeLimit::class)->raceEnded();

        $this->assertCount(1, $this->sent('SetTimeAttackLimit'));
        $this->assertSame([], $this->messages('ChatSendServerMessage'));
    }

    public function test_admin_autotime_command()
    {
        Chat::resolve('/admin autotime', 'adm');
        Chat::resolve('/admin autotime OFF', 'adm');
        Chat::resolve('/admin autotime', 'adm');
        Chat::resolve('/admin autotime on', 'adm');
        Chat::resolve('/admin autotime off', 'op');   // operators lack the ability

        $this->assertSame([
            format_colors('{#server}> {#admin}Auto TimeLimit is currently Enabled'),
            format_colors('{#server}> {#admin}Auto TimeLimit set to Disabled'),
            format_colors('{#server}> {#admin}Auto TimeLimit is currently Disabled'),
            format_colors('{#server}> {#admin}Auto TimeLimit set to Enabled'),
        ], $this->toLogin('adm'));
        $this->assertTrue(app(AutoTimeLimit::class)->active());
        $this->assertContains('admin autotime', array_column(Chat::commands($this->admin, 'admin'), 'name'));
    }

    public function test_admin_autotime_unavailable()
    {
        config(['timelimit.autotime.enabled' => false]);
        Chat::resolve('/admin autotime on', 'boss');

        $this->assertSame([format_colors('{#server}> {#admin}Auto TimeLimit unavailable - set timelimit.autotime.enabled in config')], $this->toLogin('boss'));
    }
}
