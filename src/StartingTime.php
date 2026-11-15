<?php
namespace Funseco\TimeLimit;

use Funseco\Core\Models\Map;

/** FlexiTime's starting limit for a map, in seconds. */
final class StartingTime
{
    public static function for(Map $map): int
    {
        $config = config('timelimit.flexitime');
        $default = (int) ($config['default_time'] ?? 5) * 60;

        if (($config['custom_time'] ?? false) && $map->uid && ($custom = CustomTime::secondsFor($map->uid)) !== null) {
            return $custom;
        }

        $mult = (int) ($config['author_mult'] ?? 0);
        if ($mult !== 0) {
            $seconds = (int) ceil((int) $map->author_time / 60000 * $mult) * 60;
            $min = (int) ($config['min_time'] ?? 0) * 60;
            // Maximum checked first, as in FlexiTime.
            if ($seconds > $default) {
                return $default;
            }
            if ($seconds < $min) {
                return $min;
            }

            return $seconds;
        }

        return $default;
    }
}
