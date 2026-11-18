<?php

namespace App\Services;

use App\Exceptions\GateFailed;
use App\Models\Batch;
use App\Models\Deviation;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class DeviationService
{
    public function __construct(private SignatureService $sig) {}

    public function open(?Batch $batch, ?Model $source, string $title, string $description, bool $activeIngredient, bool $requiresQc, int $signerId, User $by, ?int $lotId = null): Deviation
    {
        if (trim($title) === '' || trim($description) === '') {
            throw GateFailed::because('Say what happened (title and description).');
        }

        return DB::transaction(function () use ($batch, $source, $title, $description, $activeIngredient, $requiresQc, $signerId, $by, $lotId) {
            $d = Deviation::create([
                'public_id' => PublicId::next('DEV'),
                'batch_id' => $batch?->id, 'material_lot_id' => $lotId,
                'source_type' => $source?->getMorphClass(), 'source_id' => $source?->getKey(),
                'title' => trim($title), 'description' => trim($description),
                'active_ingredient' => $activeIngredient, 'requires_qc_approval' => $requiresQc,
                'status' => 'open', 'recorded_by_user_id' => $by->id,
            ]);
            $this->sig->sign($d, 'opened', $signerId, $by);
            DriveOutbox::enqueue($d, 'opened', [$d->public_id, $batch?->batch_number, $d->title, $d->description]);

            return $d;
        });
    }

    public function approveContinue(Deviation $d, int $signerId, User $by): void
    {
        if ($d->status !== 'open') {
            throw GateFailed::because('Only an open deviation can be approved to continue.');
        }
        DB::transaction(function () use ($d, $signerId, $by) {
            $this->sig->sign($d, 'qc_approve_continue', $signerId, $by, ['qc' => true]);
            $d->update(['status' => 'approved_to_continue']);
            DriveOutbox::enqueue($d, 'qc_approved_continue', [$d->public_id]);
        });
    }

    public function close(Deviation $d, array $data, int $signerId, User $by): void
    {
        if (! in_array($d->status, ['open', 'approved_to_continue'], true)) {
            throw GateFailed::because('This deviation is not open.');
        }
        foreach (['root_cause' => 'root cause', 'corrective_action' => 'corrective action', 'disposition' => 'disposition'] as $f => $label) {
            if (trim((string) ($data[$f] ?? '')) === '') {
                throw GateFailed::because("Enter the {$label} before closing.");
            }
        }
        DB::transaction(function () use ($d, $data, $signerId, $by) {
            $this->sig->sign($d, 'closed', $signerId, $by, ['qc' => true]);
            $d->update(['root_cause' => $data['root_cause'], 'corrective_action' => $data['corrective_action'], 'disposition' => $data['disposition'],
                'impact' => $data['impact'] ?? $d->impact, 'status' => 'closed', 'closed_at' => now()]);
            DriveOutbox::enqueue($d, 'closed', [$d->public_id, $data['root_cause'], $data['corrective_action'], $data['disposition']]);
        });
    }
}
