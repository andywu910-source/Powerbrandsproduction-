<?php

namespace App\Services;

use App\Models\Equipment;
use App\Models\Setting;

/**
 * "Refuse scales that are overdue for inspection" (setting gate_refuse_uninspected_scales).
 * It is OFF at deploy and turns ON by itself once at least one active scale exists and every
 * active scale has a last inspection date. Checked when a scale is saved and daily
 * (pbp:scale-block-check). It never switches itself off, and it only switches itself on once:
 * after that (or after an admin changes it in Settings) only an admin changes it.
 */
class ScaleBlock
{
    public const SETTING = 'gate_refuse_uninspected_scales';
    public const AUTO_DONE = 'scale_block_auto_enabled_at';      // set when it switched itself on
    public const ADMIN_DECIDED = 'scale_block_admin_decided_at'; // set when an admin changed it in Settings

    private static bool $running = false;

    public static function on(): bool
    {
        return (bool) Setting::get(self::SETTING);
    }

    /** @return array{total:int, done:int, missing: \Illuminate\Support\Collection, overdue: \Illuminate\Support\Collection} */
    public static function progress(): array
    {
        $scales = Equipment::scales()->where('active', true)->orderBy('equip_id')->get();

        return [
            'total' => $scales->count(),
            'done' => $scales->filter(fn ($s) => $s->last_inspected_on !== null)->count(),
            'missing' => $scales->filter(fn ($s) => $s->last_inspected_on === null)->values(),
            'overdue' => $scales->filter(fn ($s) => $s->inspectionStatus() === 'overdue')->values(),
        ];
    }

    /** Will it still switch itself on? */
    public static function autoPending(): bool
    {
        return ! self::on() && ! Setting::get(self::AUTO_DONE) && ! Setting::get(self::ADMIN_DECIDED);
    }

    /** Status line for Setup. */
    public static function statusLine(): string
    {
        if (self::on()) {
            return 'Block is on: production step 3 refuses scales that are overdue or have no inspection date.';
        }
        if (! self::autoPending()) {
            return 'Block is off (turned off by an admin). Switch it on in Settings when ready.';
        }
        $p = self::progress();

        return "Block is off. It turns on automatically once every scale has an inspection date ({$p['done']} of {$p['total']} done).";
    }

    /** Switch the block on if every active scale now has a last inspection date. Returns true when it switched on. */
    public static function maybeEnable(string $trigger = 'scale saved'): bool
    {
        if (self::$running || ! self::autoPending()) {
            return false;
        }
        self::$running = true;
        try {
            $p = self::progress();
            if ($p['total'] < 1 || $p['done'] < $p['total']) {
                return false;
            }
            Setting::put(self::SETTING, true);
            Setting::put(self::AUTO_DONE, now()->toIso8601String());
            Audit::log('scale_block_auto_enabled', null, ['message' => 'Scale block auto-enabled', 'trigger' => $trigger, 'scales' => $p['total'],
                'note' => 'Every active scale has a last inspection date. An admin can switch it off in Settings.']);

            return true;
        } finally {
            self::$running = false;
        }
    }
}
