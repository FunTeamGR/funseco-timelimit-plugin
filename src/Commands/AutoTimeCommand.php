<?php
namespace Funseco\TimeLimit\Commands;

use Funseco\Core\Models\Player;
use Funseco\Core\SharedKernel\Commands\Command;
use Funseco\Core\SharedKernel\Commands\DeclaresCommand;
use Funseco\Core\SharedKernel\Commands\Described;
use Funseco\Core\SharedKernel\Commands\Guarded;
use Funseco\Core\SharedKernel\Enums\Role;
use Funseco\TimeLimit\AutoTimeLimit;
use Funseco\TimeLimit\Text;

/** /admin autotime [on|off] — toggles Auto TimeLimit until the next controller restart. */
class AutoTimeCommand implements Command, Guarded, Described
{
    use DeclaresCommand;

    protected string $name = 'admin autotime';
    protected ?Role $role = Role::Operator;
    protected ?string $ability = 'autotime';
    protected string $description = 'Sets Auto TimeLimit {ON/OFF}';
    protected string $usage = '[on|off]';

    public function handle(Player $player, array $args): void
    {
        $auto = app(AutoTimeLimit::class);
        if (!$auto->available()) {
            Text::tell($player, Text::get('autotime_unavailable'));
            return;
        }

        $arg = strtolower(trim((string) ($args[0] ?? '')));
        if ($arg === 'on' || $arg === 'off') {
            $auto->setActive($arg === 'on');
            Text::tell($player, Text::get('autotime_set', $arg === 'on' ? 'Enabled' : 'Disabled'));
            return;
        }

        Text::tell($player, Text::get('autotime_is', $auto->active() ? 'Enabled' : 'Disabled'));
    }
}
