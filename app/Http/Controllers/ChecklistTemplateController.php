<?php

namespace App\Http\Controllers;

use App\Models\ChecklistTemplate;
use App\Models\ChecklistTemplateItem;
use App\Models\ChecklistTemplateVersion;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Admins add / change checklists. Each change = new version for FUTURE checklists only. */
class ChecklistTemplateController extends Controller
{
    public function create()
    {
        $t = new ChecklistTemplate(['frequency' => 'daily', 'category' => 'cleaning', 'due_time' => Setting::get('checklist_default_due_time', '10:00'),
            'grace_hours' => Setting::get('checklist_default_grace_hours', 2), 'scope_type' => 'none', 'active' => true]);

        return view('checklists.template', ['t' => $t, 'v' => new ChecklistTemplateVersion(['completed_by_role' => 'operator']), 'items' => collect()]);
    }

    public function edit(ChecklistTemplate $template)
    {
        $template->load('currentVersion.items', 'versions');

        return view('checklists.template', ['t' => $template, 'v' => $template->currentVersion, 'items' => $template->currentVersion?->items ?? collect()]);
    }

    private function rules(?ChecklistTemplate $t): array
    {
        return [
            'code' => [$t?->exists ? 'nullable' : 'required', 'alpha_dash', 'max:60', Rule::unique('checklist_templates', 'code')->ignore($t?->id)],
            'name' => ['required', 'string', 'max:255'], 'category' => ['required', Rule::in(array_keys(ChecklistTemplate::CATEGORIES))],
            'spec_ref' => ['nullable', 'string', 'max:100'], 'frequency' => ['required', Rule::in(array_keys(ChecklistTemplate::FREQUENCIES))],
            'weekday' => ['nullable', 'integer', 'between:1,7'], 'day_of_month' => ['nullable', 'integer', 'between:1,31'],
            'due_time' => ['required', 'date_format:H:i'], 'grace_hours' => ['required', 'integer', 'between:0,720'],
            'scope_type' => ['required', Rule::in(['none', 'room', 'equipment', 'scale'])],
            'completed_by_role' => ['required', Rule::in(array_keys(ChecklistTemplate::ROLES))], 'verifier_role' => ['nullable', Rule::in(array_keys(ChecklistTemplate::ROLES))],
            'change_note' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array'], 'items.*.prompt' => ['nullable', 'string', 'max:1000'],
            'items.*.response_type' => ['nullable', Rule::in(array_keys(ChecklistTemplateItem::TYPES))],
            'items.*.min' => ['nullable', 'numeric'], 'items.*.max' => ['nullable', 'numeric'], 'items.*.unit' => ['nullable', 'string', 'max:20'],
            'items.*.fail_requires' => ['nullable', Rule::in(['note', 'deviation'])],
        ];
    }

    public function store(Request $r)
    {
        $d = $r->validate($this->rules(null));
        $t = DB::transaction(function () use ($d, $r) {
            $t = ChecklistTemplate::create(collect($d)->only(['code', 'name', 'category', 'spec_ref', 'frequency', 'weekday', 'day_of_month', 'due_time', 'grace_hours', 'scope_type'])->all() + ['active' => true, 'recorded_by_user_id' => $r->user()->id]);
            $this->newVersion($t, $d, $r);

            return $t;
        });

        return redirect()->route('checklists.templates.edit', $t)->with('status', 'Checklist created.');
    }

    public function update(Request $r, ChecklistTemplate $template)
    {
        $d = $r->validate($this->rules($template));
        DB::transaction(function () use ($template, $d, $r) {
            $template->update(collect($d)->only(['name', 'category', 'spec_ref', 'frequency', 'weekday', 'day_of_month', 'due_time', 'grace_hours', 'scope_type'])->all() + ['active' => $r->boolean('active')]);
            $cur = $template->currentVersion()->with('items')->first();
            $new = $this->itemRows($d['items']);
            $old = $cur ? $cur->items->map(fn ($i) => ['prompt' => $i->prompt, 'response_type' => $i->response_type, 'min' => $i->min, 'max' => $i->max, 'unit' => $i->unit, 'fail_requires' => $i->fail_requires])->all() : [];
            $changed = ! $cur || $cur->completed_by_role !== $d['completed_by_role'] || (string) $cur->verifier_role !== (string) ($d['verifier_role'] ?? '')
                || json_encode($old) !== json_encode($new);
            if ($changed) {
                $this->newVersion($template, $d, $r);
            }
        });

        return redirect()->route('checklists.templates.edit', $template)->with('status', 'Saved. Changes to items apply to new checklists only; past ones keep their version.');
    }

    private function itemRows(array $items): array
    {
        return collect($items)->filter(fn ($i) => trim((string) ($i['prompt'] ?? '')) !== '')->map(fn ($i) => [
            'prompt' => trim($i['prompt']), 'response_type' => $i['response_type'] ?? 'pass_fail',
            'min' => isset($i['min']) && $i['min'] !== '' ? (float) $i['min'] : null, 'max' => isset($i['max']) && $i['max'] !== '' ? (float) $i['max'] : null,
            'unit' => ($i['unit'] ?? '') !== '' ? $i['unit'] : null, 'fail_requires' => $i['fail_requires'] ?? 'note',
        ])->values()->all();
    }

    private function newVersion(ChecklistTemplate $t, array $d, Request $r): void
    {
        $rows = $this->itemRows($d['items']);
        if (! $rows) {
            throw new \App\Exceptions\GateFailed(['A checklist needs at least one item.']);
        }
        $no = (int) ChecklistTemplateVersion::where('template_id', $t->id)->max('version_no') + 1;
        $v = ChecklistTemplateVersion::create(['template_id' => $t->id, 'version_no' => $no, 'completed_by_role' => $d['completed_by_role'],
            'verifier_role' => $d['verifier_role'] ?? null, 'change_note' => $d['change_note'] ?? null, 'created_by' => $r->user()->id]);
        foreach ($rows as $i => $row) {
            ChecklistTemplateItem::create($row + ['version_id' => $v->id, 'item_no' => $i + 1, 'required' => true]);
        }
        $t->update(['current_version_id' => $v->id]);
    }
}
