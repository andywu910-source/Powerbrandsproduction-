<?php

namespace App\Http\Controllers;

use App\Models\ProcessTemplate;
use App\Models\ProcessTemplateStage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Admin-editable manufacturing stages per product family (Production step 6). */
class ProcessTemplateController extends Controller
{
    public function index()
    {
        return view('setup.process', ['templates' => ProcessTemplate::with('stages')->orderBy('id')->get()]);
    }

    public function update(Request $r, ProcessTemplate $template)
    {
        $d = $r->validate(['name' => ['required', 'string', 'max:255'], 'stages' => ['array'], 'stages.*.name' => ['nullable', 'string', 'max:100'],
            'stages.*.code' => ['nullable', 'alpha_dash', 'max:30'], 'stages.*.default_steps' => ['nullable', 'string', 'max:5000']]);
        DB::transaction(function () use ($template, $d, $r) {
            $template->update(['name' => $d['name'], 'active' => $r->boolean('active', true)]);
            $no = 1;
            foreach ((array) ($d['stages'] ?? []) as $i => $row) {
                $code = strtolower(trim((string) ($row['code'] ?? '')));
                if ($code === '' || trim((string) ($row['name'] ?? '')) === '') {
                    continue;
                }
                // One starter step per line: "Instruction | parameter | unit | min | max"
                $steps = collect(preg_split('/\r?\n/', (string) ($row['default_steps'] ?? '')))->map('trim')->filter()->map(function ($line) {
                    $p = array_map('trim', explode('|', $line)) + ['', '', '', '', ''];

                    return ['instruction' => $p[0], 'parameter' => $p[1] ?: null, 'unit' => $p[2] ?: null,
                        'limit_min' => is_numeric($p[3]) ? (float) $p[3] : null, 'limit_max' => is_numeric($p[4]) ? (float) $p[4] : null];
                })->values()->all();
                $stage = ProcessTemplateStage::firstOrNew(['process_template_id' => $template->id, 'code' => $code]);
                $stage->fill(['stage_no' => $no++, 'name' => trim($row['name']), 'active' => ! empty($row['active']), 'default_steps' => $steps])->save();
            }
        });

        return back()->with('status', "{$template->name}: saved. Stages that are switched off are skipped in step 6 for this family.");
    }
}
