<?php
namespace Funseco\TimeLimit;

use Funseco\Core\Models\Map;
use Funseco\Core\SharedKernel\TimeLimit;

/**
 * FlexiTime's countdown. Loaded at every map start, counting once the race starts, frozen during the score screen, and
 * forces the next map when it reaches 0. State lives in the state store (lost on restart:
 * the current map then gets its full limit again).
 */
class FlexiTimer implements TimeLimit
{
    private const KEY = 'timelimit.flexi';

    public function __construct(private ClockWidget $clock)
    {
    }

    /** Race start (also after a restart): full limit, running. */
    public function start(Map $map): void
    {
        $this->load($map, waiting: false);
        if (config('timelimit.flexitime.use_chat')) {
            Text::broadcast(Text::get('announce', $this->text()));
        }
    }

    /**
     * Map loaded (BeginChallenge): full limit shown but held while players still load the
     * track; begin() releases it when the race actually starts.
     */
    public function prepare(Map $map): void
    {
        $this->load($map, waiting: true);
    }

    /** The race is on: release a prepared countdown (no-op when it already runs). */
    public function begin(): void
    {
        if (!($this->state()['waiting'] ?? false)) {
            return;
        }
        $this->update(['waiting' => false]);
        $this->redraw();
        if (config('timelimit.flexitime.use_chat')) {
            Text::broadcast(Text::get('announce', $this->text()));
        }
    }

    /** True between map load and race start: the full limit is held, not counting. */
    public function waiting(): bool
    {
        return (bool) ($this->state()['waiting'] ?? false);
    }

    private function load(Map $map, bool $waiting): void
    {
        $limit = StartingTime::for($map);
        $this->save([
            'left'   => $limit,
            'limit'  => $limit,
            'paused' => false,
            'finished' => false,
            'waiting' => $waiting,
            'author' => (int) round((int) $map->author_time / 1000),
        ]);
        $this->redraw();
    }

    /** Race end: freeze and hide the clock while the score screen shows. */
    public function stop(): void
    {
        if ($this->state()) {
            $this->update(['paused' => true, 'finished' => true]);
        }
        $this->clock->hide();
    }

    public function tick(): void
    {
        $state = $this->state();
        if (!$state) {
            return;
        }

        if ($state['waiting'] ?? false) {
            return;
        }

        if (!$state['paused'] && $state['left'] > 0) {
            $this->update(['left' => $state['left'] - 1]);
            $this->announce($state['left'] - 1);
        }
        $this->redraw();

        $state = $this->state();
        if (!$state['paused'] && $state['left'] <= 0) {
            $this->expire();
        }
    }

    /** Set the remaining time (pause state is kept); call expireIfDone() afterwards. */
    public function set(int $seconds): void
    {
        $this->update(['left' => max(0, $seconds)]);
        $this->redraw();
    }

    /** Add time to the countdown; pause state is kept. */
    public function extend(int $seconds): void
    {
        $this->set((int) $this->secondsLeft() + $seconds);
    }

    /** Time is up (e.g. an admin set it to 0): freeze and force the next map. */
    public function expireIfDone(): void
    {
        if ($this->running() && $this->secondsLeft() <= 0) {
            $this->expire();
        }
    }

    public function pause(): void
    {
        $this->update(['paused' => true]);
        $this->redraw();
    }

    public function resume(): void
    {
        $this->update(['paused' => false]);
        $this->redraw();
    }

    public function running(): bool
    {
        return $this->state() !== null;
    }

    public function secondsLeft(): ?int
    {
        return $this->state()['left'] ?? null;
    }

    public function limit(): ?int
    {
        return $this->state()['limit'] ?? null;
    }

    /** Not expired and not stopped at the end of the map/round (an admin pause does not count). */
    public function extendable(): bool
    {
        $state = $this->state();

        return $state !== null && !($state['finished'] ?? false) && $state['left'] > 0;
    }

    public function paused(): bool
    {
        return (bool) ($this->state()['paused'] ?? false);
    }

    /** "05:00 (m:s) until round end." */
    public function text(): string
    {
        $left = (int) $this->secondsLeft();

        return Text::get($this->paused() ? 'time_left_paused' : 'time_left', Text::clock($left), $left >= 3600 ? 'h:m:s' : 'm:s');
    }

    /** Clock colour: danger, then warn (below warn_time or below the author time), else normal. */
    public function colour(): string
    {
        $config = config('timelimit.flexitime');
        $left = (int) $this->secondsLeft();

        return match (true) {
            $left < (int) $config['danger_time'] => $config['danger_colour'],
            $left < (int) $config['warn_time'] || $left < (int) ($this->state()['author'] ?? 0) => $config['warn_colour'],
            default => $config['colour'],
        };
    }

    private function expire(): void
    {
        $this->update(['paused' => true, 'finished' => true]);
        $this->redraw();
        \Server::query()->NextChallenge();
    }

    private function redraw(): void
    {
        $this->paused() ? $this->clock->hide() : $this->clock->show(Text::clock((int) $this->secondsLeft()), $this->colour());
    }

    /** use_chat: whole minutes (multiples of 10, of 5 under an hour, and 1), then 30, 10 and 0 seconds. */
    private function announce(int $left): void
    {
        if (!config('timelimit.flexitime.use_chat')) {
            return;
        }
        $minutes = intdiv($left, 60);
        $due = $left < 60
            ? in_array($left, [30, 10, 0], true)
            : $left % 60 === 0 && ($minutes % 10 === 0 || ($minutes < 60 && $minutes % 5 === 0) || $minutes === 1);
        if ($due) {
            Text::broadcast(Text::get('announce', $this->text()));
        }
    }

    private function state(): ?array
    {
        return state()->get(self::KEY);
    }

    private function save(array $state): void
    {
        state()->forever(self::KEY, $state);
    }

    private function update(array $changes): void
    {
        $this->save(array_merge($this->state() ?? ['left' => 0, 'limit' => 0, 'paused' => true, 'author' => 0], $changes));
    }
}
