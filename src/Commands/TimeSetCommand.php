<?php
namespace Funseco\TimeLimit\Commands;

use Funseco\Core\Models\Player;
use Funseco\Core\Services\Cache\CurrentMap;
use Funseco\Core\SharedKernel\Commands\Command;
use Funseco\Core\SharedKernel\Commands\DeclaresCommand;
use Funseco\Core\SharedKernel\Commands\Described;
use Funseco\Core\SharedKernel\Commands\Guarded;
use Funseco\TimeLimit\CustomTime;
use Funseco\TimeLimit\Text;

/** /timeset N — stores N minutes for the current map; used the next time it starts. */
class TimeSetCommand implements Command, Guarded, Described
{
    use DeclaresCommand;

    protected string $name = 'timeset';
    protected ?string $ability = TimeLeftCommand::ABILITY;
    protected string $description = 'Sets custom timelimit in minutes for when this track is played in future.';

    public function handle(Player $player, array $args): void
    {
        if (!config('timelimit.flexitime.custom_time')) {
            Text::tell($player, Text::get('timeset_disabled'));
            return;
        }

        $minutes = Text::leadingInt((string) ($args[0] ?? ''));
        $map = CurrentMap::get();
        if (!$minutes || $minutes < 0 || !$map) {
            Text::tell($player, Text::get('timeset_usage'));
            return;
        }

        CustomTime::store($map->uid, $minutes * 60);
        Text::broadcast(Text::get('timeset', $player->login, $minutes));
    }
}
