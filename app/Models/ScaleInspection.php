<?php

namespace App\Models;

/** One Weights & Measures inspection / report / calibration of a scale. Never edited; void with a reason. */
class ScaleInspection extends RecordModel
{
    public const KINDS = [
        'wm_inspection' => 'Weights & Measures inspection',
        'wm_report' => 'Reported to local Weights & Measures',
        'calibration' => 'Calibration / service',
        'other' => 'Other',
    ];
    public const RESULTS = [
        'passed_sealed' => 'Passed and sealed',
        'passed' => 'Passed',
        'reported' => 'Reported (no inspection yet)',
        'failed' => 'Failed / rejected',
    ];

    protected $casts = ['inspected_on' => 'date', 'next_due_on' => 'date'];

    public function scale() { return $this->belongsTo(Equipment::class, 'equipment_id'); }
    public function recorder() { return $this->belongsTo(User::class, 'recorded_by_user_id'); }
}
