<?php

namespace App\Services;

use App\Exceptions\GateFailed;
use App\Models\Revision;
use App\Models\Signature;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Corrections keep the old value: a revisions row stores old, new, reason, who,
 * initials and when. Signatures on the record are marked "invalidated by
 * correction" and must be signed again. The UI shows the old value struck through.
 */
class CorrectionService
{
    public function correct(Model $record, string $field, mixed $newValue, string $reason, string $initials, User $by): Revision
    {
        if ($by->isViewer()) {
            throw GateFailed::because('Read-only accounts cannot make corrections.');
        }
        $allowed = property_exists($record, 'correctable') ? $record->correctable : [];
        if (! in_array($field, $allowed, true)) {
            throw GateFailed::because('That field cannot be corrected here.');
        }
        if (trim($reason) === '' || trim($initials) === '') {
            throw GateFailed::because('A correction needs a reason and your initials.');
        }
        if (method_exists($record, 'isVoid') && $record->isVoid()) {
            throw GateFailed::because('A void record cannot be corrected.');
        }

        return DB::transaction(function () use ($record, $field, $newValue, $reason, $initials, $by) {
            $old = $record->getAttribute($field);
            $oldStr = $old instanceof \DateTimeInterface ? $old->format('Y-m-d') : (is_bool($old) ? ($old ? 'yes' : 'no') : ($old === null ? null : (string) $old));
            $newStr = $newValue === null || $newValue === '' ? null : (string) $newValue;
            if ($oldStr === $newStr) {
                throw GateFailed::because('The new value is the same as the current value.');
            }
            $record->setAttribute($field, $newStr);
            $record->save();

            $rev = Revision::create([
                'record_type' => $record->getMorphClass(),
                'record_id' => $record->getKey(),
                'field' => $field,
                'old_value' => $oldStr,
                'new_value' => $newStr,
                'reason' => trim($reason),
                'corrected_by' => $by->id,
                'recorded_by_user_id' => $by->id,
                'initials' => mb_strtoupper(mb_substr(trim($initials), 0, 10)),
                'corrected_at' => now(),
            ]);

            Signature::where('signable_type', $record->getMorphClass())->where('signable_id', $record->getKey())
                ->whereNull('invalidated_at')
                ->update(['invalidated_at' => now(), 'invalidated_by_revision_id' => $rev->id]);

            if (method_exists($record, 'afterCorrection')) {
                $record->afterCorrection();
            }

            Audit::log('correct', $record, ['field' => $field, 'old' => $oldStr, 'new' => $newStr, 'reason' => $reason, 'revision_id' => $rev->id]);
            if (($record->getAttributes()['filing_status'] ?? 'none') !== 'none') {
                DriveOutbox::enqueue($record, 'correction', ['CORRECTION', $field, $oldStr, $newStr, $reason, $rev->initials]);
            }

            return $rev;
        });
    }
}
