<?php
namespace Funseco\TimeLimit;

use Funseco\Core\Models\Player;

final class Text
{
    public static function get(string $key, string|int ...$values): string
    {
        $text = config("timelimit.messages.$key", '');
        $text = format_colors($text);   // colour tokens first: values must not expand {#..}
        $map = [];
        foreach ($values as $i => $value) {
            $map['{'.($i + 1).'}'] = (string) $value;
        }

        return strtr($text, $map);
    }

    public static function tell(Player $player, string $message): void
    {
        \Server::query()->ChatSendServerMessageToLogin($message, $player->login);
    }

    public static function broadcast(string $message): void
    {
        \Server::query()->ChatSendServerMessage($message);
    }

    /** HH:MM:SS from one hour on, else MM:SS. */
    public static function clock(int $seconds): string
    {
        $seconds = max(0, $seconds);

        return $seconds >= 3600
            ? sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60)
            : sprintf('%02d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    /** Milliseconds as MM:SS.hh (two-digit minutes). */
    public static function raceTime(int $ms): string
    {
        $ms = max(0, $ms);

        return sprintf('%02d:%02d.%02d', intdiv($ms, 60000), intdiv($ms % 60000, 1000), intdiv($ms % 1000, 10));
    }

    /** Leading integer of a chat argument ("5abc" → 5, "1.5" → 1), null when there is none. */
    public static function leadingInt(string $arg): ?int
    {
        return preg_match('/^\s*([+-]?\d+)/', $arg, $m) ? (int) $m[1] : null;
    }

    public static function stripColors(?string $text): string
    {
        $text = str_replace('$$', "\0", (string) $text);
        $text = preg_replace('/\$(?:[0-9a-f][^$]{0,2}|[lh]\[[^\]]*\]|[^$]?)/i', '', $text);

        return str_replace("\0", '$', $text);
    }
}
