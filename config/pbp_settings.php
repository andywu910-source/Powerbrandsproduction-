<?php

/*
 * Editable settings (Admin -> Settings). These are the DEFAULTS; anything saved on
 * the Settings page is stored in the `settings` table and wins over these.
 * Values Andy has not decided yet are marked "default, change any time".
 */
return [
    'signing_auth_mode' => [
        'label' => 'How people sign',
        'type' => 'select',
        'options' => ['name' => 'Pick your name (no PIN)', 'pin' => 'Name + personal PIN', 'password' => 'Name + account password'],
        'default' => 'name',
        'help' => 'Andy approved "pick your name" for now. PINs can be switched on later without changes to the data.',
    ],
    'qc_reviewer_user_ids' => [
        'label' => 'QC reviewers',
        'type' => 'users',
        'default' => [],
        'help' => 'Andy, Leo and Jenny. A reviewer can never release a batch they worked on.',
    ],
    'qc_default_reviewer_user_id' => [
        'label' => 'Default QC reviewer',
        'type' => 'user',
        'default' => null,
        'help' => 'Leo by default.',
    ],
    'late_entry_minutes' => [
        'label' => 'Late entry after (minutes)',
        'type' => 'int', 'default' => 60,
        'help' => 'Entries made this long after the work need a reason. Default, change any time.',
    ],
    'retention_years' => [
        'label' => 'Keep records for (years)',
        'type' => 'int', 'default' => 5,
        'help' => 'Minimum 5 years under NY rules. Nothing is ever purged automatically.',
    ],
    'batch_number_pattern' => [
        'label' => 'Batch number pattern',
        'type' => 'pattern', 'default' => '{BRAND}{PRODUCT}{YYMMDD}{SUFFIX}',
        'options' => [
            '{BRAND}{PRODUCT}{YYMMDD}{SUFFIX}' => 'Brand + product + date, e.g. ST5BR261117 (2nd batch that day ST5BR261117B, then C, D...)',
            '{BRAND}-{PRODUCT}-{YYMMDD}{SUFFIX_NUM}' => 'With dashes, e.g. ST-5BR-261117 (then -02, -03)',
            '{TYPE}-{YYMMDD}-{SEQ2}' => 'Type + date + number, e.g. PR-261117-01',
        ],
        'help' => 'Batch numbers are the main ID for every batch. Tokens: {BRAND} (or {BRAND2}), {PRODUCT} (or {PRODUCT3}), {TYPE}, {PRODUCT_CODE}, {YYYY} {YY} {MM} {DD} {YYMMDD}, {SUFFIX} (none for the first batch that day, then B, C...), {SUFFIX_NUM} (-02, -03...), {SEQ2}, {SEQ3}. Must include one of {SUFFIX}, {SUFFIX_NUM}, {SEQ2}, {SEQ3}. Changing this only affects new batches; existing numbers never change.',
    ],
    'brand_code_length' => ['label' => 'Brand code length', 'type' => 'int', 'default' => 2, 'help' => 'Exactly this many characters.'],
    'product_code_length' => ['label' => 'Product code length', 'type' => 'int', 'default' => 3, 'help' => 'Exactly this many characters. Give size/format variants different codes.'],
    'code_charset' => [
        'label' => 'Characters allowed in codes',
        'type' => 'text', 'default' => 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789',
        'help' => 'Uppercase letters and digits only. Default leaves out O, 0, I and 1 so labels can\'t be misread.',
    ],
    'family_type_codes' => [
        'label' => 'Batch number type codes',
        'type' => 'map',
        'default' => ['preroll' => 'PR', 'vape' => 'VP', 'flower' => 'FL', 'rosin' => 'RS', 'green_mantis' => 'GM'],
        'help' => 'The {TYPE} code for each product family (letters/numbers only).',
    ],
    'tolerance_cannabis_active_pct' => ['label' => 'Weigh tolerance: distillate / cannabis (±%)', 'type' => 'decimal', 'default' => 2.0, 'help' => 'Used when a recipe line has no tolerance of its own. Default, change any time.'],
    'tolerance_terpene_pct' => ['label' => 'Weigh tolerance: terpenes (±%)', 'type' => 'decimal', 'default' => 5.0, 'help' => 'Default, change any time.'],
    'tolerance_carrier_pct' => ['label' => 'Weigh tolerance: carriers (±%)', 'type' => 'decimal', 'default' => 5.0, 'help' => 'Default, change any time.'],
    'tolerance_other_pct' => ['label' => 'Weigh tolerance: other ingredients (±%)', 'type' => 'decimal', 'default' => 5.0, 'help' => 'Default, change any time.'],
    'tolerance_count_pct' => ['label' => 'Count tolerance: hardware / packaging (±%)', 'type' => 'decimal', 'default' => 0.0, 'help' => '0 means the count must be exact. Default, change any time.'],
    'tolerance_deviation_qc' => [
        'label' => 'Out-of-tolerance deviations need QC approval',
        'type' => 'select', 'options' => ['all' => 'Every ingredient', 'active_only' => 'Only the cannabis (active) ingredient'],
        'default' => 'all', 'help' => 'Before the batch can continue past a weighing outside tolerance.',
    ],
    'coa_min_pct_of_label' => ['label' => 'COA potency: lowest % of label', 'type' => 'decimal', 'default' => 85.0, 'help' => 'NY rule: 85–115% of the labeled amount.'],
    'coa_max_pct_of_label' => ['label' => 'COA potency: highest % of label', 'type' => 'decimal', 'default' => 115.0, 'help' => ''],
    'gate_require_daily_scale_check' => [
        'label' => 'Equipment check needs today\'s scale checklist',
        'type' => 'bool', 'default' => true,
        'help' => 'Production step 3 is blocked until today\'s scale check is signed for every scale on the batch.',
    ],
    'gate_refuse_uninspected_scales' => [
        'label' => 'Refuse scales that are overdue for inspection',
        'type' => 'bool', 'default' => false,
        'help' => 'Off at first. It turns on by itself (once) when every active scale has a last inspection date in the scale register; after that only an admin changes it. When on, production step 3 (and weighing) will not accept a scale whose Weights & Measures inspection is overdue or missing in the scale register. Inactive scales are always refused.',
    ],
    'scale_inspection_interval_months' => ['label' => 'Scale inspection every (months)', 'type' => 'int', 'default' => 12, 'help' => 'Next inspection due = last inspection + this many months. NY Agriculture & Markets Law §182 / 1 NYCRR §220.5: at least once a year (1–12).'],
    'scale_due_soon_days' => ['label' => 'Flag scales as "due soon" (days before)', 'type' => 'int', 'default' => 30, 'help' => 'Shown on the scale register and the dashboard.'],
    'training_required_categories' => [
        'label' => 'Training needed to work on a batch',
        'type' => 'list', 'default' => ['rwt', 'task'],
        'help' => 'Training categories each person must have on file (comma separated: rwt, task, gmp, safety, other).',
    ],
    'checklist_default_due_time' => ['label' => 'Daily checklists due by', 'type' => 'time', 'default' => '10:00', 'help' => 'Used for new checklist templates. Default, change any time.'],
    'checklist_default_grace_hours' => ['label' => 'Overdue after due time (hours)', 'type' => 'int', 'default' => 2, 'help' => 'Used for new checklist templates. Default, change any time.'],
    'site_licensee_name' => ['label' => 'Licensee name', 'type' => 'text', 'default' => 'Cannacure NY Inc.', 'help' => ''],
    'site_license_no' => ['label' => 'Processor license #', 'type' => 'text', 'default' => 'OCM-PROC-24-000134', 'help' => ''],
];
