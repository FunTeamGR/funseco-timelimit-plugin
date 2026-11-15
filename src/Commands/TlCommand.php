<?php
namespace Funseco\TimeLimit\Commands;

use Funseco\Core\Models\Player;
use Funseco\Core\SharedKernel\Commands\Command;
use Funseco\Core\SharedKernel\Commands\DeclaresCommand;
use Funseco\Core\SharedKernel\Commands\Described;
use Funseco\Core\SharedKernel\Commands\Guarded;
use Funseco\TimeLimit\FlexiTimer;

/** /tl — emergency: remaining time back to 5:00. Arguments are ignored. */
class TlCommand implements Command, Guarded, Described
{
    use DeclaresCommand;

    protected string $name = 'tl';
    protected ?string $ability = TimeLeftCommand::ABILITY;
    protected string $description = 'Quickly set remaining time to 5 minutes';

    public function handle(Player $player, array $args): void
    {
        if (app(FlexiTimer::class)->running()) {
            TimeLeftCommand::change($player, (int) config('timelimit.flexitime.emergency_seconds', 300));
        }
    }
}
