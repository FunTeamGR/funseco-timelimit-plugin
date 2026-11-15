<?php
namespace Funseco\TimeLimit;

use Funseco\Core\Services\Cache\CurrentPlayers;
use Funseco\Core\Services\Cache\CurrentSpectators;

/**
 * Auto TimeLimit: at race end, sets the server's TimeAttack limit for the next map from its
 * author time × multiplicator, clamped to min/max. Skipped once when the map is restarted.
 * The on/off toggle (/admin autotime) is in memory only.
 */
class AutoTimeLimit
{
    private const ACTIVE = 'timelimit.autotime.active';
    private const RESTART = 'timelimit.autotime.restart';
    private const TIME_ATTACK = 1;

    public function available(): bool
    {
        return (bool) config('timelimit.autotime.enabled', false);
    }

    public function active(): bool
    {
        return $this->available() && state()->get(self::ACTIVE, true);
    }

    public function setActive(bool $active): void
    {
        state()->forever(self::ACTIVE, $active);
    }

    /** The current map will be played again: leave the limit alone at this race end. */
    public function restarting(): void
    {
        state()->forever(self::RESTART, true);
    }

    public function raceEnded(bool $restart = false): void
    {
        $flagged = (bool) state()->pull(self::RESTART);   // cleared even when autotime is inactive
        if (!$this->active() || $flagged || $restart) {
            return;
        }
        if ((float) config('timelimit.autotime.multiplicator') <= 0) {
            return;
        }

        \Server::query()->GetNextGameInfo(1, function ($result) {
            if ((int) ($result[0]['GameMode'] ?? -1) !== self::TIME_ATTACK) {
                return;
            }
            // Somebody racing: the next map. Only spectators: the server has already switched.
            $racing = CurrentPlayers::all()->contains(fn ($p) => !CurrentSpectators::has($p->login));
            $method = $racing ? 'GetNextChallengeInfo' : 'GetCurrentChallengeInfo';
            \Server::query()->{$method}(function ($result) {
                if (is_array($result[0] ?? null)) {
                    $this->apply($result[0]);
                }
            });
        });
    }

    /** @param array{Name?: string, AuthorTime?: int} $challenge */
    public function apply(array $challenge): void
    {
        $config = config('timelimit.autotime');
        $author = (int) ($challenge['AuthorTime'] ?? 0);
        [$limit, $tag] = self::limitFor($author, $config);

        \Server::query()->SetTimeAttackLimit($limit);

        if ((int) $config['display'] === 1) {
            Text::broadcast(Text::get(
                'autotime',
                $tag,
                Text::stripColors($challenge['Name'] ?? ''),
                Text::clock(intdiv($limit, 1000)),
                Text::raceTime($author),
            ));
        }
    }

    /** @return array{0: int, 1: string} limit in ms and its tag (default/new/min/max) */
    public static function limitFor(int $authorTime, array $config): array
    {
        if ($authorTime <= 0) {
            [$limit, $tag] = [(int) ($config['defaulttime'] * 60000), 'default'];
        } else {
            $limit = (int) ($authorTime * $config['multiplicator']);
            [$limit, $tag] = [$limit - $limit % 1000, 'new'];
        }

        $min = (int) ($config['mintime'] * 60000);
        $max = (int) ($config['maxtime'] * 60000);
        if ($limit < $min) {
            return [$min, 'min'];
        }
        if ($limit > $max) {
            return [$max, 'max'];
        }

        return [$limit, $tag];
    }
}
