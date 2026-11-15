<?php
namespace Funseco\TimeLimit;

use Illuminate\Database\Eloquent\Model;

/**
 * A per-track FlexiTime limit set with /timeset.
 *
 * @property string $map_uid
 * @property int $seconds
 */
class CustomTime extends Model
{
    protected $table = 'timelimit_custom_times';

    protected $fillable = ['map_uid', 'seconds'];

    protected $casts = ['seconds' => 'integer'];

    public static function secondsFor(string $uid): ?int
    {
        return static::query()->where('map_uid', $uid)->value('seconds');
    }

    public static function store(string $uid, int $seconds): void
    {
        static::query()->updateOrCreate(['map_uid' => $uid], ['seconds' => $seconds]);
    }
}
