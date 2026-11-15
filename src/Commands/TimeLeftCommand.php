<?php
namespace Funseco\TimeLimit\Commands;

use Funseco\Core\Models\Player;
use Funseco\Core\SharedKernel\Commands\Command;
use Funseco\Core\SharedKernel\Commands\DeclaresCommand;
use Funseco\Core\SharedKernel\Commands\Described;
use Funseco\TimeLimit\FlexiTimer;
use Funseco\TimeLimit\Text;

/**
 * /timeleft — anyone may query; changing needs the 'flexitime' ability (checked here, since
 * the query form is open to all).
 */
class TimeLeftCommand implements Command, Described
{
    use DeclaresCommand;

    public const ABILITY = 'flexitime';

    protected string $name = 'timeleft';
    protected string $description = 'Change or query time left: /timeleft [[+|-]MINUTES]|[pause|resume]';

    public function handle(Player $player, array $args): void
    {
        $timer = app(FlexiTimer::class);
        if (!$timer->running()) {
            return;
        }

        $arg = strtolower(trim((string) ($args[0] ?? '')));
        if ($arg === '') {
            Text::tell($player, $timer->text());
            return;
        }
        if (!$player->allowed(self::ABILITY)) {
            Text::tell($player, Text::get('no_permission'));
            return;
        }

        if ($arg === 'pause' || $arg === 'resume') {
            $arg === 'pause' ? $timer->pause() : $timer->resume();
            Text::broadcast(Text::get($arg === 'pause' ? 'paused' : 'resumed', $player->login));
            return;
        }

        $minutes = Text::leadingInt($arg);
        if ($minutes === null || ($minutes === 0 && $arg !== '0')) {
            Text::tell($player, Text::get('invalid'));
            return;
        }

        $seconds = match ($arg[0]) {
            '+', '-' => $timer->secondsLeft() + $minutes * 60,
            default  => $minutes * 60,
        };
        if ($seconds < 0) {
            Text::tell($player, Text::get('below_zero'));
            return;
        }

        self::change($player, $seconds);
    }

    /** Apply a new remaining time and announce it (nickname raw, as FlexiTime did). */
    public static function change(Player $player, int $seconds): void
    {
        $timer = app(FlexiTimer::class);
        $timer->set($seconds);
        Text::broadcast(Text::get('changed', Text::stripColors((string) $player->nickname), $timer->text()));
        $timer->expireIfDone();
    }
}
