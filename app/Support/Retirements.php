<?php

namespace App\Support;

use App\Models\ChecklistTemplate;
use App\Services\Audit;

/**
 * Nov 19 (Andy): Checklists templates that duplicate a Compliance hub log are switched off (never deleted).
 * Their past checklists stay readable; nothing new is created for them and open ones can't be filled in.
 *
 * Kept on purpose:
 *  - changeover_clean: it is Production step 2's batch cleaning record (only started from a batch, never from the
 *    Checklists page), and the step-2 gate needs it. Retiring it would block production.
 *  - weekly_equipment, daily_security_walk: no hub log covers them.
 */
class Retirements
{
    /** template code => the hub log that replaces it */
    public const CHECKLISTS = [
        'daily_room_clean' => 'CLN',
        'weekly_deep_clean' => 'CLN',
        'scale_check_daily' => 'BAL',
        'monthly_security_test' => 'SEC',
        'monthly_calibration_review' => 'EQ-CM',
    ];

    public static function applyChecklists(): int
    {
        $n = 0;
        foreach (ChecklistTemplate::whereIn('code', array_keys(self::CHECKLISTS))->where('active', true)->get() as $t) {
            $t->active = false;
            $t->save();   // audited (old / new) like any template change
            Audit::log('retire', $t, ['reason' => 'Duplicate of Compliance hub log '.self::CHECKLISTS[$t->code], 'replaced_by' => self::CHECKLISTS[$t->code]]);
            $n++;
        }

        return $n;
    }

    public static function undoChecklists(): void
    {
        foreach (ChecklistTemplate::whereIn('code', array_keys(self::CHECKLISTS))->where('active', false)->get() as $t) {
            $t->active = true;
            $t->save();
        }
    }

    /** Is the replacement hub log still switched on? (BAL itself was retired later on Nov 19.) */
    public static function replacementActive(string $code): bool
    {
        return \App\Models\LogDefinition::where('code', $code)->where('active', true)->exists();
    }

    /** Replacement hub log code when this template was retired, else null. */
    public static function replacedBy(?ChecklistTemplate $t): ?string
    {
        return $t && ! $t->active ? (self::CHECKLISTS[$t->code] ?? null) : null;
    }
}
