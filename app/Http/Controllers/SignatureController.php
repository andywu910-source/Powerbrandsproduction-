<?php

namespace App\Http\Controllers;

use App\Models\ComplianceEntry;
use App\Models\Signature;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Sign-offs can never be edited or deleted. The only change allowed is an admin voiding one, with a reason:
 * the sign-off stays on record (marked VOID, who voided it and why) and the audit log gets the same reason.
 * Route is admin-only (role:admin).
 */
class SignatureController extends Controller
{
    public function void(Request $r, Signature $signature)
    {
        abort_unless($r->user()->isAdmin(), 403);
        $d = $r->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']], ['reason.required' => 'Say why this sign-off is being voided.']);
        if ($signature->invalidated_at !== null) {
            return back()->withErrors(['reason' => $signature->isVoided() ? 'That sign-off is already voided.' : 'That sign-off is already marked no longer valid (the record was corrected).']);
        }
        $reason = trim($d['reason']);

        DB::transaction(function () use ($signature, $r, $reason) {
            $signature->forceFill(['invalidated_at' => now(), 'voided_by_user_id' => $r->user()->id, 'void_reason' => $reason])->save();
            $record = $signature->signable;
            Audit::log('void_signature', $record, [
                'signature_id' => $signature->id, 'meaning' => $signature->meaning, 'signer_user_id' => $signature->user_id,
                'signer_name' => $signature->signerName(), 'signed_at' => $signature->signed_at?->toIso8601String(), 'reason' => $reason,
            ]);
            // A voided checklist item goes back to "not done" so it can be signed again.
            if ($record instanceof ComplianceEntry && $signature->meaning === 'completed') {
                $record->update(['done' => false, 'completed_by_user_id' => null, 'completed_on' => null]);
            }
        });

        return back()->with('status', 'Sign-off voided. It stays on record, marked VOID.');
    }
}
