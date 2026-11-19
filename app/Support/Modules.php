<?php

namespace App\Support;

/**
 * Which sidebar tabs are switched on. Tabs are rolled out one at a time
 * (PBP_MODULES in .env, or the default list in config/pbp.php).
 *
 * bookkeeping is in ALL so it can be switched on, but it is not in the default
 * list and not in modules_release_on. It stays off until PBP_MODULES includes
 * bookkeeping, and PBP_MODULES_OFF=bookkeeping turns it off again.
 */
class Modules
{
    public const ALL = ['production', 'receiving', 'checklists', 'batchlog', 'labels', 'schedule', 'compliance', 'logs', 'bookkeeping'];

    public static function enabled(string $m): bool
    {
        return in_array($m, self::list(), true);
    }

    public static function list(): array
    {
        $norm = function ($v): array {
            $v = is_string($v) ? preg_split('/[\s,;]+/', $v) : (array) $v;

            return array_map(fn ($x) => strtolower(trim((string) $x, " \t\n\r\0\x0B'\"")), $v);
        };
        $on = array_merge($norm(config('pbp.modules', self::ALL)), $norm(config('pbp.modules_release_on', [])));
        $on = array_diff($on, $norm(config('pbp.modules_off', '')));

        return array_values(array_intersect(self::ALL, $on));
    }
}
