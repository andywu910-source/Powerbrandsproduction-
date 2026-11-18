<?php

namespace App\Http\Controllers;

use App\Exceptions\GateFailed;
use App\Models\Setting;
use App\Models\User;
use App\Services\BatchNumberGenerator;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function edit()
    {
        $values = [];
        foreach (config('pbp_settings') as $k => $def) {
            $values[$k] = Setting::get($k);
        }

        return view('settings.edit', ['defs' => config('pbp_settings'), 'values' => $values,
            'users' => User::where('is_active', true)->where('role', '!=', User::ROLE_VIEWER)->orderBy('name')->get(),
            'preview' => BatchNumberGenerator::preview()]);
    }

    public function update(Request $r)
    {
        $errors = [];
        $new = [];
        foreach (config('pbp_settings') as $k => $def) {
            $in = $r->input($k);
            $new[$k] = match ($def['type']) {
                'int' => (int) $in,
                'decimal' => (float) $in,
                'bool' => $r->boolean($k),
                'users' => array_values(array_map('intval', (array) $in)),
                'user' => $in ? (int) $in : null,
                'list' => array_values(array_filter(array_map(fn ($v) => strtolower(trim($v)), explode(',', (string) $in)))),
                'map' => collect((array) $in)->map(fn ($v) => strtoupper(trim((string) $v)))->all(),
                'select' => array_key_exists((string) $in, $def['options']) ? (string) $in : $def['default'],
                default => trim((string) $in),
            };
        }
        if ($e = BatchNumberGenerator::validatePattern($new['batch_number_pattern'])) {
            $errors = array_merge($errors, array_map(fn ($m) => "Batch number pattern: $m", $e));
        }
        foreach ($new['family_type_codes'] as $fam => $code) {
            if (! preg_match('/^[A-Z0-9]{1,6}$/', $code)) $errors[] = "Type code for {$fam} must be 1–6 letters/numbers.";
        }
        if (! preg_match('/^[A-Z0-9]+$/', strtoupper($new['code_charset'])) || strlen($new['code_charset']) < 10) {
            $errors[] = 'Allowed code characters: at least 10 uppercase letters/numbers.';
        }
        $new['code_charset'] = strtoupper($new['code_charset']);
        if ($new['brand_code_length'] < 1 || $new['brand_code_length'] > 8) $errors[] = 'Brand code length must be 1–8.';
        if ($new['product_code_length'] < 1 || $new['product_code_length'] > 10) $errors[] = 'Product code length must be 1–10.';
        if ($new['scale_inspection_interval_months'] < 1 || $new['scale_inspection_interval_months'] > 12) $errors[] = 'Scale inspections must be at least yearly: 1–12 months.';
        if ($new['scale_due_soon_days'] < 1 || $new['scale_due_soon_days'] > 180) $errors[] = 'Due-soon warning must be 1–180 days.';
        if ($new['retention_years'] < 5) $errors[] = 'Records must be kept at least 5 years.';
        if (! preg_match('/^\d{2}:\d{2}$/', $new['checklist_default_due_time'])) $errors[] = 'Due time must look like 10:00.';
        if ($new['qc_default_reviewer_user_id'] && ! in_array($new['qc_default_reviewer_user_id'], $new['qc_reviewer_user_ids'], true)) {
            $errors[] = 'The default QC reviewer must also be ticked as a QC reviewer.';
        }
        if ($errors) {
            throw new GateFailed($errors);
        }
        if ($new[\App\Services\ScaleBlock::SETTING] !== (bool) Setting::get(\App\Services\ScaleBlock::SETTING)) {
            Setting::put(\App\Services\ScaleBlock::ADMIN_DECIDED, now()->toIso8601String(), $r->user());   // an admin decided: no more automatic switching
        }
        foreach ($new as $k => $v) {
            Setting::put($k, $v, $r->user());
        }

        return back()->with('status', 'Settings saved. Batch number pattern changes only affect new batches.');
    }

    /** Live preview for the pattern box. */
    public function preview(Request $r)
    {
        $p = (string) $r->input('pattern');
        $e = BatchNumberGenerator::validatePattern($p);

        return response()->json(['ok' => ! $e, 'errors' => $e, 'preview' => $e ? '' : BatchNumberGenerator::preview(null, $p)]);
    }
}
