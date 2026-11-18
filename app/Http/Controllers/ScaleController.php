<?php

namespace App\Http\Controllers;

use App\Exceptions\GateFailed;
use App\Models\AuditLog;
use App\Models\Equipment;
use App\Models\Room;
use App\Models\ScaleInspection;
use App\Services\Audit;
use App\Services\PublicId;
use App\Support\AttachmentStore;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Scale register (Setup). Scales are equipment rows with is_scale = true, plus their
 * Weights & Measures details. NY Agriculture & Markets Law §182 / 1 NYCRR §220.5:
 * commercial scales are inspected at least once a year. Nothing is deleted: scales are
 * switched off with "Active", inspections are voided with a reason. Every change is audited.
 */
class ScaleController extends Controller
{
    public const UNITS = ['g' => 'g', 'kg' => 'kg', 'mg' => 'mg', 'lb' => 'lb', 'oz' => 'oz'];

    private function canEdit(Request $r): bool
    {
        return $r->user()->isSupervisorOrAdmin();
    }

    public function index(Request $r)
    {
        $scales = Equipment::scales()->with('room')->orderByDesc('active')->orderBy('equip_id')->get();
        $order = ['overdue' => 0, 'missing' => 1, 'due_soon' => 2, 'ok' => 3, 'inactive' => 4];
        $scales = $scales->sortBy(fn ($s) => ($order[$s->inspectionStatus()] ?? 9).'-'.$s->equip_id)->values();

        return view('scales.index', ['scales' => $scales, 'canEdit' => $this->canEdit($r), 'rooms' => Room::where('active', true)->orderBy('room_no')->get(),
            'counts' => $scales->countBy(fn ($s) => $s->inspectionStatus()), 'scale' => new Equipment(['active' => true, 'is_scale' => true])]);
    }

    public function show(Request $r, Equipment $scale)
    {
        abort_unless($scale->is_scale, 404);
        $scale->load('room', 'inspections.recorder', 'inspections.attachments');
        $history = AuditLog::with('user')->where('record_type', 'equipment')->where('record_id', $scale->id)->orderByDesc('id')->limit(100)->get();

        return view('scales.show', ['scale' => $scale, 'canEdit' => $this->canEdit($r), 'rooms' => Room::where('active', true)->orderBy('room_no')->get(),
            'history' => $history]);
    }

    private function fields(Request $r, ?Equipment $scale): array
    {
        $d = $r->validate([
            'equip_id' => ['required', 'string', 'max:40', Rule::unique('equipment', 'equip_id')->ignore($scale?->id)],
            'name' => ['required', 'string', 'max:255'],
            'serial' => ['nullable', 'string', 'max:255'],
            'room_id' => ['nullable', 'integer', 'exists:rooms,id'],
            'location' => ['nullable', 'string', 'max:255'],
            'capacity' => ['nullable', 'numeric', 'min:0'],
            'capacity_unit' => ['nullable', Rule::in(array_keys(self::UNITS))],
            'division' => ['nullable', 'numeric', 'min:0'],
            'wm_reported_on' => ['nullable', 'date'],
            'wm_sealed_on' => ['nullable', 'date'],
            'wm_seal_ref' => ['nullable', 'string', 'max:255'],
            'last_inspected_on' => ['nullable', 'date'],
            'inspection_due_on' => ['nullable', 'date'],
            'last_calibrated_on' => ['nullable', 'date'],
            'calibration_due_on' => ['nullable', 'date'],
            'maintenance_due_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        $d['active'] = $r->boolean('active');
        $d['is_scale'] = true;
        $d['type'] = $scale?->type ?: 'Scale';

        // Next inspection due defaults to last inspection + 12 months, and may never be later than that.
        $lastChanged = $scale === null || (string) ($d['last_inspected_on'] ?? '') !== (string) $scale->last_inspected_on?->toDateString();
        $dueUnchanged = $scale !== null && (string) ($d['inspection_due_on'] ?? '') === (string) $scale->inspection_due_on?->toDateString();
        if (! empty($d['last_inspected_on']) && (empty($d['inspection_due_on']) || ($lastChanged && $dueUnchanged))) {
            $d['inspection_due_on'] = Equipment::defaultDue(Carbon::parse($d['last_inspected_on']));
        }
        if (! empty($d['last_inspected_on']) && ! empty($d['inspection_due_on'])) {
            $max = Equipment::defaultDue(Carbon::parse($d['last_inspected_on']));
            if ($d['inspection_due_on'] > $max) {
                throw GateFailed::because('Next inspection due can be at most '.Equipment::inspectionMonths().' months after the last inspection ('.Carbon::parse($max)->format('M j, Y').'). NY requires scales to be inspected at least once a year.');
            }
        }
        foreach (['last_inspected_on', 'wm_sealed_on', 'wm_reported_on'] as $k) {
            if (! empty($d[$k]) && $d[$k] > now()->timezone(config('pbp.site_timezone'))->toDateString()) {
                throw GateFailed::because('Dates of past events (reported, sealed, last inspected) cannot be in the future.');
            }
        }

        return $d;
    }

    public function store(Request $r)
    {
        abort_unless($this->canEdit($r), 403);
        $scale = Equipment::create($this->fields($r, null) + ['recorded_by_user_id' => $r->user()->id]);

        return redirect()->route('scales.show', $scale)->with('status', "Scale {$scale->equip_id} added to the register.");
    }

    public function update(Request $r, Equipment $scale)
    {
        abort_unless($scale->is_scale, 404);
        abort_unless($this->canEdit($r), 403);
        $scale->update($this->fields($r, $scale));

        return redirect()->route('scales.show', $scale)->with('status', 'Saved. The change is kept in the history below.');
    }

    public function inspect(Request $r, Equipment $scale)
    {
        abort_unless($scale->is_scale, 404);
        abort_unless($this->canEdit($r), 403);
        $d = $r->validate([
            'kind' => ['required', Rule::in(array_keys(ScaleInspection::KINDS))],
            'inspected_on' => ['required', 'date', 'before_or_equal:'.now()->timezone(config('pbp.site_timezone'))->toDateString()],
            'inspector' => ['nullable', 'string', 'max:255'],
            'result' => ['required', Rule::in(array_keys(ScaleInspection::RESULTS))],
            'seal_number' => ['nullable', 'string', 'max:255'],
            'next_due_on' => ['nullable', 'date', 'after:inspected_on'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'certificate' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,heic,webp'],
        ]);
        $on = Carbon::parse($d['inspected_on']);
        $passed = in_array($d['result'], ['passed', 'passed_sealed'], true);
        if ($d['kind'] === 'wm_inspection' && $passed) {
            $d['next_due_on'] ??= Equipment::defaultDue($on);
            if ($d['next_due_on'] > Equipment::defaultDue($on)) {
                throw GateFailed::because('Next inspection due can be at most '.Equipment::inspectionMonths().' months after this inspection.');
            }
        }
        if ($d['result'] === 'passed_sealed' && blank($d['seal_number']) && blank($scale->wm_seal_ref)) {
            throw GateFailed::because('Enter the seal number for a scale that was sealed.');
        }

        $msg = DB::transaction(function () use ($d, $scale, $r, $on, $passed) {
            $ins = ScaleInspection::create(collect($d)->except('certificate')->all() + [
                'public_id' => PublicId::next('INS'), 'equipment_id' => $scale->id, 'recorded_by_user_id' => $r->user()->id]);
            if ($r->hasFile('certificate')) {
                AttachmentStore::store($ins, 'wm_certificate', $r->file('certificate'), $r->user());
            }
            $upd = [];
            $msg = "Inspection {$ins->public_id} recorded.";
            $isLatest = ! $scale->last_inspected_on || $on->toDateString() >= $scale->last_inspected_on->toDateString();
            if ($d['kind'] === 'wm_inspection' && $passed && $isLatest) {
                $upd += ['last_inspected_on' => $on->toDateString(), 'inspection_due_on' => $d['next_due_on']];
                if ($d['result'] === 'passed_sealed') {
                    $upd['wm_sealed_on'] = $on->toDateString();
                }
                if (filled($d['seal_number'])) {
                    $upd['wm_seal_ref'] = $d['seal_number'];
                }
                $msg .= ' Next inspection due '.Carbon::parse($d['next_due_on'])->format('M j, Y').'.';
            } elseif ($d['kind'] === 'wm_report') {
                $upd['wm_reported_on'] = $on->toDateString();
            } elseif ($d['kind'] === 'calibration' && $passed) {
                $upd['last_calibrated_on'] = $on->toDateString();
                if (! empty($d['next_due_on'])) {
                    $upd['calibration_due_on'] = $d['next_due_on'];
                }
            }
            if ($d['result'] === 'failed' && $scale->active) {
                $upd['active'] = false;
                $msg .= ' The scale failed, so it is now marked "not in use". Switch it back on after it passes.';
            }
            if ($upd) {
                $scale->update($upd);
            }

            return $msg;
        });

        return redirect()->route('scales.show', $scale)->with('status', $msg);
    }

    public function voidInspection(Request $r, ScaleInspection $inspection)
    {
        abort_unless($this->canEdit($r), 403, 'Only a supervisor or admin can void records.');
        $inspection->void((string) $r->input('reason'), $r->user());

        return redirect()->route('scales.show', $inspection->equipment_id)
            ->with('status', 'Inspection voided. It stays on file, marked VOID. Check the scale\'s dates above and correct them if they came from this inspection.');
    }
}
