<?php
namespace Funseco\TimeLimit;

use Funseco\Core\Services\ManiaLink\Facades\ManiaLink;
use Funseco\Core\SharedKernel\UI\Region;
use Funseco\Core\SharedKernel\UI\WidgetRegistry;

/**
 * FlexiTime clock: a chrono label on a player-card background, sticking out of the right
 * screen edge. Sent to everybody every second while the timer runs (new joiners see it
 * within a second); cleared when paused. Pinned per gamemode in config/manialinks.php.
 */
class ClockWidget
{
    public const NAME = 'timelimit_clock';

    private const SHOWN = 'timelimit.clock_shown';

    public static function register(WidgetRegistry $registry): void
    {
        // Right-hand column, above the record lists (the karma widget stays pinned over it).
        $registry->regionWidget(self::NAME, Region::MidRight, 5, width: 15.5, height: 5.0);
    }

    public function show(string $time, string $colour): void
    {
        $placement = config('timelimit.flexitime.show_panel') ? app(WidgetRegistry::class)->placement(self::NAME) : null;
        if (!$placement) {
            $this->hide();
            return;
        }

        state()->forever(self::SHOWN, true);
        ManiaLink::displayAll('<manialink id="'.ManiaLink::getAction(self::NAME).'">'
            .'<frame posn="'.$placement->x.' '.($placement->y - $placement->height / 2).' 0" scale="1">'
            .'<quad posn="'.$placement->width.' 0 0" sizen="'.$placement->width.' '.$placement->height.' 0.08" halign="right" valign="center" style="BgsPlayerCard" substyle="BgPlayerCardBig"/>'
            .'<label posn="'.($placement->width - 4.5).' 0.1 0.1" halign="right" valign="center" scale="0.6" style="TextRaceChrono" text="$s$'
            .htmlspecialchars($colour.$time, ENT_QUOTES).'"/>'
            .'</frame></manialink>');
    }

    public function hide(): void
    {
        if (state()->pull(self::SHOWN)) {
            ManiaLink::displayAll('<manialink id="'.ManiaLink::getAction(self::NAME).'"></manialink>');
        }
    }
}
