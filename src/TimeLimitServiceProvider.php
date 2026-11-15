<?php
namespace Funseco\TimeLimit;

use Funseco\Core\SharedKernel\PublishesPluginConfig;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Funseco\Core\SharedKernel\Settings\Setting;
use Funseco\Core\SharedKernel\Settings\SettingsRegistry;
use Funseco\Core\Services\Cache\CurrentMap;
use Funseco\Core\Services\Cache\ServerInfo;
use Funseco\Core\Services\ManiaLink\ManiaLinkService;
use Funseco\Core\SharedKernel\Events\EverySecondEvent;
use Funseco\Core\SharedKernel\Events\ExtendRequested;
use Funseco\Core\SharedKernel\Events\GameStatusChanged;
use Funseco\Core\SharedKernel\Events\MapEnded;
use Funseco\Core\SharedKernel\Events\MapStarted;
use Funseco\Core\SharedKernel\Events\ReplayRequested;
use Funseco\Core\SharedKernel\Events\RoundEnded;
use Funseco\Core\SharedKernel\Events\RoundStarted;
use Funseco\Core\SharedKernel\Events\StartupEvent;
use Funseco\Core\SharedKernel\TimeLimit;
use Funseco\Core\SharedKernel\UI\WidgetRegistry;
use Funseco\TimeLimit\Commands as C;

class TimeLimitServiceProvider extends ServiceProvider
{
    use PublishesPluginConfig;

    /** Game modes with several rounds per map: Rounds, Team, Laps, Cup. */
    private const ROUND_MODES = [0, 2, 3, 5];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/timelimit.php', 'timelimit');
        $this->publishPluginConfig(__DIR__.'/..');

        // flexitime.enabled is read in register()/boot() to bind the timer and register listeners, so it needs a restart; the rest is read lazily.
        $this->app->make(SettingsRegistry::class)->declare('timelimit', 'timelimit', [
            Setting::bool('flexitime.enabled', 'FlexiTime enabled', restart: true),
            Setting::int('flexitime.default_time', 'Default time limit', min: 1, unit: 'min'),
            Setting::bool('flexitime.custom_time', 'Per-track /timeset times'),
            Setting::int('flexitime.author_mult', 'Author time multiplier (0 = default time)', min: 0),
            Setting::int('flexitime.min_time', 'Minimum time with author multiplier', min: 0, unit: 'min'),
            Setting::int('flexitime.emergency_seconds', '/tl remaining time', min: 0, unit: 's'),
            Setting::bool('flexitime.use_chat', 'Chat announcements'),
            Setting::bool('flexitime.show_panel', 'Clock panel'),
            Setting::string('flexitime.colour', 'Clock colour (hex)'),
            Setting::int('flexitime.warn_time', 'Warn below', min: 0, unit: 's'),
            Setting::string('flexitime.warn_colour', 'Warn colour (hex)'),
            Setting::int('flexitime.danger_time', 'Danger below (0 = never)', min: 0, unit: 's'),
            Setting::string('flexitime.danger_colour', 'Danger colour (hex)'),
            Setting::bool('autotime.enabled', 'Auto time limit enabled'),
            Setting::float('autotime.multiplicator', 'Author time multiplier (0 = off)', min: 0),
            Setting::float('autotime.mintime', 'Auto minimum time', min: 0, unit: 'min'),
            Setting::float('autotime.maxtime', 'Auto maximum time', min: 0, unit: 'min'),
            Setting::float('autotime.defaulttime', 'Auto default time', min: 0, unit: 'min'),
            Setting::int('autotime.display', 'Announce auto time in chat (1 = yes, 0 = no)', min: 0, max: 1),
        ]);

        $this->app->singleton(ClockWidget::class);
        $this->app->singleton(FlexiTimer::class);
        $this->app->singleton(AutoTimeLimit::class);
        if (config('timelimit.flexitime.enabled', true)) {
            $this->app->singleton(TimeLimit::class, fn ($app) => $app->make(FlexiTimer::class));
        }
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        \Chat::register(C\AutoTimeCommand::class);

        $auto = fn () => app(AutoTimeLimit::class);
        app(\Funseco\Core\SharedKernel\MenuIndicators::class)->register('timelimit.auto', fn () => $auto()->active());
        Event::listen(MapEnded::class, fn (MapEnded $e) => $auto()->raceEnded($e->restart));
        Event::listen(ReplayRequested::class, fn () => $auto()->restarting());

        if (!config('timelimit.flexitime.enabled', true)) {
            return;
        }

        app(ManiaLinkService::class)->addAction(ClockWidget::NAME);
        ClockWidget::register(app(WidgetRegistry::class));
        foreach ([C\TimeLeftCommand::class, C\TlCommand::class, C\TimeSetCommand::class] as $command) {
            \Chat::register($command);
        }

        $timer = fn () => app(FlexiTimer::class);
        Event::listen(StartupEvent::class, function () use ($timer) {
            if ($map = CurrentMap::get()) {
                $timer()->start($map);
            }
        });
        // BeginChallenge comes while players are still loading the track: load the full limit but
        // hold it until the race starts (BeginRound, as the old FlexiTime did; the status turning
        // to Play is the fallback in case a mode sends no BeginRound).
        Event::listen(MapStarted::class, fn (MapStarted $e) => $timer()->prepare($e->map));
        Event::listen(GameStatusChanged::class, function (GameStatusChanged $e) use ($timer) {
            $e->isPlaying() && $timer()->begin();
        });
        Event::listen(MapEnded::class, fn () => $timer()->stop());
        // Rounds, Team, Cup and Laps run many rounds per map: the timer starts afresh every round
        // and stops at its end. Time Attack has the map as its single round.
        $perRound = fn () => in_array(ServerInfo::getGameMode(), self::ROUND_MODES, true);
        Event::listen(RoundStarted::class, function () use ($timer, $perRound) {
            if ($perRound() && ($map = CurrentMap::get())) {
                $timer()->start($map);
            } else {
                $timer()->begin();
            }
        });
        Event::listen(RoundEnded::class, function () use ($timer, $perRound) {
            $perRound() && $timer()->stop();
        });
        Event::listen(EverySecondEvent::class, fn () => $timer()->tick());
        // /extend vote: add the default round time (no restart, so auto time-limit is left alone).
        Event::listen(ExtendRequested::class, function (ExtendRequested $e) use ($timer) {
            if ($timer()->extendable() && ($map = CurrentMap::get())) {
                $e->addedSeconds = StartingTime::for($map);
                $timer()->extend($e->addedSeconds);
            }
        });
    }
}
