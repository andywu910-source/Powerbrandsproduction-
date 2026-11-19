<?php

namespace App\Services;

use App\Exceptions\GateFailed;
use App\Models\Setting;
use App\Models\Signature;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

/**
 * Every sign-off goes through here.
 *
 * signing_auth_mode (Settings): 'name' = pick your name (Andy's choice for now),
 * 'pin' = name + personal PIN, 'password' = name + account password. Switching
 * needs no data changes: users.signing_pin_hash already exists.
 *
 * Always recorded: signer user id, who was logged in, server time, IP, browser,
 * meaning of the signature, and a SHA-256 of the record as signed.
 */
class SignatureService
{
    public const MEANINGS = [
        'performed' => 'Performed by',
        'verified' => 'Verified by (second person)',
        'approved' => 'Approved by',
        'issued' => 'Batch issued by (supervisor)',
        'confirmed' => 'Calculation confirmed by (supervisor)',
        'weighed' => 'Weighed / counted by',
        'received' => 'Received by',
        'paperwork' => 'Paperwork checked by',
        'qc_release' => 'QC release',
        'qc_reject' => 'QC rejection',
        'qc_approve_continue' => 'QC approved continuing',
        'opened' => 'Deviation opened by',
        'closed' => 'Deviation closed by (QC)',
        'completed' => 'Completed by',
        'adjusted' => 'Stock adjustment by',
        'sampled' => 'Lab sampling witnessed by (our employee)',
        'log_signoff' => 'Batch log signed off by',
        'reconfirmed' => 'Re-confirmed after revision by',
    ];

    /**
     * @param  array{differs_from?: int[], roles?: string[], qc?: bool, credential?: ?string, differs_message?: string}  $rules
     */
    public function sign(Model $record, string $meaning, int|User|null $signer, User $recordedBy, array $rules = []): Signature
    {
        $signer = $signer instanceof User ? $signer : ($signer ? User::with('person')->find($signer) : null);
        if (! $signer) {
            throw GateFailed::because('Pick the name of the person signing.');
        }
        if ($recordedBy->isViewer()) {
            throw GateFailed::because('Read-only accounts cannot record signatures.');
        }
        if ($why = $signer->signingBlocker()) {
            throw GateFailed::because($why);
        }
        if ($recordedBy->signsAsSelf() && $signer->id !== $recordedBy->id) {
            throw GateFailed::because('You are signed in as '.$recordedBy->name.'. Staff accounts can only sign their own work, so you cannot sign as '.$signer->name.'.');
        }

        // PIN / password (when switched on) come from the form: signing_credential[<user id>] or signing_credential.
        $cred = $rules['credential'] ?? null;
        if ($cred === null && (! app()->runningInConsole() || app()->runningUnitTests())) {
            $cred ??= request()->input('signing_credential.'.$signer->id) ?? (is_string(request()->input('signing_credential')) ? request()->input('signing_credential') : null);
        }
        $this->checkCredential($signer, $cred);

        if (! empty($rules['differs_from']) && in_array($signer->id, array_map('intval', $rules['differs_from']), true)) {
            throw GateFailed::because($rules['differs_message'] ?? 'The second signature must come from a different person than the one who performed it.');
        }
        if (! empty($rules['qc']) && ! $signer->isQcReviewer()) {
            throw GateFailed::because("{$signer->name} is not a QC reviewer. QC reviewers are set in Settings.");
        }
        if (! empty($rules['roles']) && ! $signer->hasRole(...$rules['roles'])) {
            $who = implode(' or ', array_map(fn ($r) => config("pbp.roles.$r", $r), $rules['roles']));
            throw GateFailed::because("This signature must be from a {$who}; {$signer->name} is a {$signer->roleLabel()}.");
        }

        $req = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();
        $sig = Signature::create([
            'signable_type' => $record->getMorphClass(),
            'signable_id' => $record->getKey(),
            'meaning' => $meaning,
            'meaning_text' => self::MEANINGS[$meaning] ?? ucfirst(str_replace('_', ' ', $meaning)),
            'user_id' => $signer->id,
            'signer_name' => $signer->name,
            'recorded_by_user_id' => $recordedBy->id,
            'person_id' => $signer->person?->id,
            'signed_at' => now(),
            'auth_method' => (string) Setting::get('signing_auth_mode', 'name'),
            'ip' => $req?->ip(),
            'user_agent' => $req ? mb_substr((string) $req->userAgent(), 0, 255) : 'console',
            'record_hash' => self::hashRecord($record),
        ]);
        Audit::log('sign', $record, ['meaning' => $meaning, 'signer' => $signer->id, 'signature_id' => $sig->id]);

        return $sig;
    }

    private function checkCredential(User $signer, ?string $credential): void
    {
        $mode = (string) Setting::get('signing_auth_mode', 'name');
        if ($mode === 'pin') {
            if (! $signer->signing_pin_hash) {
                throw GateFailed::because("{$signer->name} has not set a signing PIN yet.");
            }
            if (! $credential || ! Hash::check($credential, $signer->signing_pin_hash)) {
                throw GateFailed::because('That PIN is not correct.');
            }
        } elseif ($mode === 'password') {
            if (! $credential || ! Hash::check($credential, $signer->password)) {
                throw GateFailed::because('That password is not correct.');
            }
        }
    }

    public static function hashRecord(Model $record): string
    {
        $a = $record->attributesToArray();
        unset($a['updated_at'], $a['filing_status']);
        ksort($a);

        return hash('sha256', $record->getMorphClass().'|'.json_encode($a));
    }

    /** All user ids who performed / verified / weighed anything on a batch. */
    public static function batchWorkerIds(\App\Models\Batch $batch): array
    {
        $meanings = ['performed', 'verified', 'weighed', 'issued', 'confirmed', 'sampled', 'completed'];
        $ids = collect();
        $add = function ($type, $recordIds) use (&$ids, $meanings) {
            if (! count($recordIds)) return;
            $ids = $ids->merge(Signature::where('signable_type', $type)->whereIn('signable_id', $recordIds)
                ->whereIn('meaning', $meanings)->whereNull('invalidated_at')->pluck('user_id'));
        };
        $add((new \App\Models\BatchStep)->getMorphClass(), $batch->steps()->pluck('id')->all());
        $add((new \App\Models\BatchMaterialLine)->getMorphClass(), $batch->materialLines()->pluck('id')->all());
        $add((new \App\Models\BatchMmpStep)->getMorphClass(), $batch->mmpStepRecords()->pluck('id')->all());
        $add((new \App\Models\BatchCalculation)->getMorphClass(), $batch->calculations()->pluck('id')->all());
        $add((new \App\Models\Batch)->getMorphClass(), [$batch->id]);
        $add((new \App\Models\ChecklistInstance)->getMorphClass(), \App\Models\ChecklistInstance::where('batch_id', $batch->id)->pluck('id')->all());

        return $ids->map(fn ($v) => (int) $v)->unique()->values()->all();
    }
}
