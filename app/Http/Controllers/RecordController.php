<?php

namespace App\Http\Controllers;

use App\Exceptions\GateFailed;
use App\Models\Attachment;
use App\Services\Audit;
use App\Services\CorrectionService;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Generic void / correction / file download for any compliance record. */
class RecordController extends Controller
{
    private const VOIDABLE = ['material_lot', 'deviation', 'checklist', 'pqp', 'mmp', 'training'];
    private const CORRECTABLE = ['material_lot', 'batch', 'batch_mmp_step', 'checklist_item', 'deviation', 'pqp'];

    private function find(string $type, int $id, array $allowed)
    {
        abort_unless(in_array($type, $allowed, true), 404);
        $class = Relation::getMorphedModel($type);

        return $class::findOrFail($id);
    }

    public function void(Request $r, string $type, int $id)
    {
        abort_unless($r->user()->isSupervisorOrAdmin(), 403, 'Only a supervisor or admin can void records.');
        $rec = $this->find($type, $id, self::VOIDABLE);
        if ($type === 'material_lot' && $rec->ledger()->exists()) {
            throw GateFailed::because('This lot already has stock movements. Use a stock adjustment instead of voiding.');
        }
        $rec->void((string) $r->input('reason'), $r->user());

        return back()->with('status', 'Voided. It stays on file, marked VOID.');
    }

    public function correct(Request $r, string $type, int $id, CorrectionService $svc)
    {
        $rec = $this->find($type, $id, self::CORRECTABLE);
        $svc->correct($rec, (string) $r->input('field'), $r->input('new_value'), (string) $r->input('reason'), (string) $r->input('initials'), $r->user());

        return back()->with('status', 'Correction saved. The old value stays on file, struck through. Signatures on it must be signed again.');
    }

    public function download(Attachment $attachment)
    {
        Audit::log('download', null, ['attachment_id' => $attachment->id]);
        abort_unless(Storage::disk('local')->exists($attachment->path), 404);

        return Storage::disk('local')->download($attachment->path, $attachment->filename);
    }
}
