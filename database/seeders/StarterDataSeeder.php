<?php

namespace Database\Seeders;

use App\Models\ChecklistTemplate;
use App\Models\ChecklistTemplateItem;
use App\Models\ChecklistTemplateVersion;
use App\Models\Person;
use App\Models\ProcessTemplate;
use App\Models\ProcessTemplateStage;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Starter data. Idempotent: only adds what is missing, never overwrites anything an
 * admin has edited. Values Andy hasn't decided are sensible defaults, editable later.
 */
class StarterDataSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $this->settings();
            $this->processTemplates();
            $this->checklists();
            $this->people();
        });
    }

    private function settings(): void
    {
        foreach (config('pbp_settings') as $key => $def) {
            if (! Setting::where('key', $key)->exists()) {
                Setting::create(['key' => $key, 'value' => ['v' => $def['default']]]);
            }
        }
        // QC reviewers: Andy, Leo, Jenny (Leo default), matched to existing logins by first name.
        Setting::flush();
        $ids = Setting::qcReviewerIds();
        if (! $ids) {
            $found = [];
            foreach (['Andy', 'Leo', 'Jenny'] as $first) {
                $u = User::where('is_active', true)->where('role', '!=', User::ROLE_VIEWER)->where('name', 'like', $first.'%')->first();
                if ($u) {
                    $found[$first] = $u->id;
                }
            }
            if ($found) {
                Setting::put('qc_reviewer_user_ids', array_values($found));
            }
            if (isset($found['Leo']) && ! Setting::get('qc_default_reviewer_user_id')) {
                Setting::put('qc_default_reviewer_user_id', $found['Leo']);
            }
        }
        Setting::flush();
    }

    /** Andy's process per family (from the Production Log tabs). */
    public static function processDefinitions(): array
    {
        $pack = ['packaging', 'Packaging', [
            ['instruction' => 'Fill / weigh each unit to the label amount', 'parameter' => 'Unit net weight check', 'unit' => 'g'],
            ['instruction' => 'Seal, apply tamper-evident seal and Metrc UID label', 'parameter' => null, 'unit' => null],
            ['instruction' => 'Count units into cases / master boxes', 'parameter' => null, 'unit' => null],
        ]];

        return [
            'preroll' => ['Prerolls', [
                ['grinding', 'Grinding', [
                    ['instruction' => 'Grind and de-stem source material; record weight after de-stem', 'parameter' => 'Weight after de-stem', 'unit' => 'g'],
                ]],
                ['infusion', 'Infusion', [
                    ['instruction' => 'Infuse ground material per the recipe (infusion type and %)', 'parameter' => 'Infusion weight added', 'unit' => 'g'],
                ]],
                ['filling', 'Filling', [
                    ['instruction' => 'Fill cones; check fill weight on the checked scale', 'parameter' => 'Fill weight per preroll', 'unit' => 'g'],
                    ['instruction' => 'Count defects pulled', 'parameter' => 'Defects', 'unit' => 'units'],
                ]],
                $pack,
            ]],
            'vape' => ['Vapes', [
                ['mixing', 'Mixing', [
                    ['instruction' => 'Mix oil per the recipe (distillate + terps / HTE / live rosin)', 'parameter' => 'Mix temperature', 'unit' => '°C'],
                ]],
                ['filling', 'Filling', [
                    ['instruction' => 'Fill hardware; check fill amount', 'parameter' => 'Fill per unit', 'unit' => 'g'],
                ]],
                $pack,
            ]],
            'flower' => ['Flower', [$pack]],
            'rosin' => ['Rosin', [$pack]],
            'green_mantis' => ['Green Mantis (tinctures & topicals)', [$pack]],
        ];
    }

    private function processTemplates(): void
    {
        foreach (self::processDefinitions() as $family => [$name, $stages]) {
            $t = ProcessTemplate::firstWhere('family', $family) ?? ProcessTemplate::create(['family' => $family, 'name' => $name, 'active' => true]);
            foreach ($stages as $i => [$code, $label, $steps]) {
                if (! ProcessTemplateStage::where('process_template_id', $t->id)->where('code', $code)->exists()) {
                    ProcessTemplateStage::create(['process_template_id' => $t->id, 'stage_no' => $i + 1, 'code' => $code, 'name' => $label, 'active' => true, 'default_steps' => $steps]);
                }
            }
        }
    }

    /** Phase 1 starter checklists: cleaning, equipment, security. */
    public static function checklistDefinitions(): array
    {
        $pf = fn (string $p, string $fail = 'note') => ['prompt' => $p, 'response_type' => 'pass_fail', 'fail_requires' => $fail];

        return [
            ['daily_room_clean', 'Production room cleaning & sanitation (incl. restrooms, hand-wash)', 'cleaning', '2.5, §125.7(a)(5),(c)', 'daily', 'operator', 'supervisor', [
                $pf('Production room floors, walls and surfaces cleaned'),
                $pf('Work tables and contact surfaces sanitized (agent + concentration per SOP)'),
                $pf('Restrooms cleaned and sanitized'),
                $pf('Hand-wash stations stocked (soap, towels) and clean'),
                $pf('Trash removed; waste containers closed'),
                ['prompt' => 'Cleaning agent and concentration used', 'response_type' => 'text', 'fail_requires' => 'note'],
            ]],
            ['weekly_deep_clean', 'Deep clean (rooms, equipment, drains)', 'cleaning', '2.5', 'weekly', 'operator', 'supervisor', [
                $pf('Drains cleaned'), $pf('Equipment broken down and deep cleaned'), $pf('Storage shelves and bins cleaned'), $pf('Ceiling vents and lights wiped'),
            ]],
            ['changeover_clean', 'Changeover cleaning (Production step 2)', 'cleaning', '2.5', 'on_demand', 'operator', 'operator', [
                $pf('Previous product, labels and materials removed from the line'),
                $pf('Room and line surfaces cleaned and sanitized'),
                $pf('Every piece of equipment for this batch cleaned and sanitized'),
                $pf('Line clearance: nothing left from the last batch'),
                ['prompt' => 'Cleaning agent and concentration used', 'response_type' => 'text', 'fail_requires' => 'note'],
            ]],
            ['scale_check_daily', 'Scale check with test weight', 'equipment', '2.6, §125.8(a)(5)', 'daily', 'operator', 'supervisor', [
                $pf('Scale level, clean and W&M seal intact'),
                ['prompt' => 'Test weight reading (100 g test weight)', 'response_type' => 'numeric', 'min' => 99.9, 'max' => 100.1, 'unit' => 'g', 'fail_requires' => 'deviation'],
            ]],
            ['weekly_equipment', 'Equipment condition & maintenance-due check', 'equipment', '2.6', 'weekly', 'operator', 'supervisor', [
                $pf('All equipment in good condition (no damage, leaks, loose parts)'),
                $pf('No calibration or maintenance due in the next 7 days without a plan'),
                $pf('Equipment labels / IDs readable'),
            ]],
            ['monthly_calibration_review', 'Calibration & maintenance review', 'equipment', '2.6', 'monthly', 'supervisor', 'qc', [
                $pf('Every scale has a current calibration record'),
                $pf('Maintenance log complete (type, technician, date) for the month'),
                $pf('Equipment register up to date'),
            ]],
            ['monthly_security_test', 'Security test: locks, alarm, cameras, video retention', 'security', '2.14, §125.3(c)(6)', 'monthly', 'operator', 'supervisor', [
                $pf('Locks tested', 'deviation'), $pf('Alarm system tested', 'deviation'), $pf('All cameras recording', 'deviation'),
                $pf('Video retention is at least 60 days', 'deviation'),
            ]],
            ['daily_security_walk', 'Opening / closing security walk', 'security', '2.14 [best practice]', 'daily', 'supervisor', null, [
                $pf('All doors and windows secure'), $pf('Alarm armed / disarmed correctly'), $pf('Cameras showing on the monitor'), $pf('Limited-access areas locked'),
            ]],
        ];
    }

    private function checklists(): void
    {
        $due = (string) Setting::get('checklist_default_due_time', '10:00');
        $grace = (int) Setting::get('checklist_default_grace_hours', 2);
        foreach (self::checklistDefinitions() as [$code, $name, $cat, $ref, $freq, $by, $verifier, $items]) {
            if (ChecklistTemplate::where('code', $code)->exists()) {
                continue;
            }
            $t = ChecklistTemplate::create(['code' => $code, 'name' => $name, 'category' => $cat, 'spec_ref' => $ref, 'frequency' => $freq,
                'weekday' => $freq === 'weekly' ? 1 : null, 'day_of_month' => $freq === 'monthly' ? 1 : null,
                'due_time' => $freq === 'daily' ? $due : '17:00', 'grace_hours' => $freq === 'daily' ? $grace : ($freq === 'weekly' ? 24 : 72), 'active' => true,
                'scope_type' => $code === 'scale_check_daily' ? 'scale' : 'none']);
            $v = ChecklistTemplateVersion::create(['template_id' => $t->id, 'version_no' => 1, 'completed_by_role' => $by, 'verifier_role' => $verifier, 'change_note' => 'Starter template']);
            foreach ($items as $i => $it) {
                ChecklistTemplateItem::create(['version_id' => $v->id, 'item_no' => $i + 1, 'prompt' => $it['prompt'], 'response_type' => $it['response_type'],
                    'min' => $it['min'] ?? null, 'max' => $it['max'] ?? null, 'unit' => $it['unit'] ?? null, 'required' => true, 'fail_requires' => $it['fail_requires']]);
            }
            $t->update(['current_version_id' => $v->id]);
        }
    }

    private function people(): void
    {
        $staff = [
            ['Andy Wu', 'AW', 'Owner', false, 'Andy'],
            ['Leo', 'L', 'QC reviewer', false, 'Leo'],
            ['Jenny', 'J', 'Records / compliance', false, 'Jenny'],
            ['Jack', 'JK', 'Head of Manufacturing', true, null],  // never assigned paperwork or signatures
            ['Sae', 'S', 'Labels', false, 'Sae'],
        ];
        foreach ($staff as [$name, $ini, $title, $noDoc, $login]) {
            $first = explode(' ', $name)[0];
            if (Person::where('full_name', 'like', $first.'%')->exists()) {
                continue;
            }
            $user = $login ? User::where('name', 'like', $login.'%')->first() : null;
            if ($user && Person::where('user_id', $user->id)->exists()) {
                $user = null;
            }
            Person::create(['full_name' => $name, 'initials' => $ini, 'job_title' => $title, 'no_documentation' => $noDoc,
                'user_id' => $noDoc ? null : $user?->id, 'active' => true]);
        }
    }
}
