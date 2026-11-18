<?php

namespace App\Models;

use App\Exceptions\GateFailed;

/** A Batch Production Record. Identified everywhere by its batch number. */
class Batch extends RecordModel
{
    public const STATUSES = ['in_process' => 'In production', 'in_qc' => 'Waiting for QC', 'released' => 'Released', 'rejected' => 'Rejected', 'imported' => 'Imported (sheet)', 'planned' => 'Incoming (not started)', 'void' => 'Void'];

    protected $casts = ['sheet_fields' => 'array', 'expiration_date' => 'date', 'planned_date' => 'date', 'started_at' => 'datetime', 'completed_at' => 'datetime', 'target_potency' => 'float', 'batch_size_g' => 'float',
        'yield_pct' => 'float', 'coa_potency' => 'float'];

    /** Batch Log status (Andy, Nov 17): the only status shown in the Batch Log. Changed by hand there (audited). */
    public const LOG_STATUSES = ['incoming' => 'Incoming', 'in_production' => 'In production', 'in_lab' => 'In lab', 'packaging_coa' => 'Packaging/COA labeling', 'finished' => 'Finished'];

    /** Text colours (AA on white): Incoming blue, In production orange, In lab teal, Packaging/COA purple, Finished green. */
    public const LOG_STATUS_COLORS = ['incoming' => '#4b5563', 'in_production' => '#b45309', 'in_lab' => '#1d4ed8', 'packaging_coa' => '#7e22ce', 'finished' => '#15803d'];

    /** Statuses a started (or imported) batch can be set to by hand. Incoming is only for jobs not started yet. */
    public const STARTED_LOG_STATUSES = ['in_production', 'in_lab', 'packaging_coa', 'finished'];

    /** How a batch's starting Batch Log status was chosen (existing batches, Nov 17 release). */
    public const LOG_STATUS_RULE = 'Imported from the sheet -> Finished; QC released or rejected (or a finished date) -> Finished; '
        .'waiting for QC, or at step 7 (Packaging & labeling) or 8 (Lab sampling) -> Packaging/COA labeling; otherwise (steps 1-6) -> In production. '
        .'Void batches get the value of the step they were at (they stay void and hidden unless the filter shows void).';

    public static function logStatusRule(array $b): string
    {
        $status = (string) ($b['status'] ?? '');
        if ($status === 'planned') {
            return 'incoming';
        }
        if ($status === 'imported' || ($b['source'] ?? 'site') === 'sheet') {
            return 'finished';
        }
        if (in_array($status, ['released', 'rejected'], true) || ! empty($b['qc_decision']) || ! empty($b['completed_at'])) {
            return 'finished';
        }
        if ($status === 'in_qc' || (int) ($b['current_step'] ?? 1) >= 7) {
            return 'packaging_coa';
        }

        return 'in_production';
    }

    public function logStatus(): string
    {
        return $this->log_status && isset(self::LOG_STATUSES[$this->log_status]) ? $this->log_status : self::logStatusRule($this->getAttributes());
    }

    public function logStatusLabel(): string
    {
        return self::LOG_STATUSES[$this->logStatus()];
    }

    /** CSS class for the coloured status text: lst lst-in_production etc. */
    public function logStatusClass(): string
    {
        return 'lst lst-'.$this->logStatus();
    }

    public function isIncoming(): bool
    {
        return $this->status === 'planned';
    }

    public array $correctable = ['output_metrc_uid', 'line', 'coa_lab', 'coa_sample_id'];

    protected static function booted(): void
    {
        static::updating(function (Batch $b) {
            if ($b->isDirty('batch_number')) {
                throw GateFailed::because('A batch number cannot be changed once the batch has started.');
            }
        });
    }

    public function getRouteKeyName(): string { return 'batch_number'; }

    /**
     * URLs use the batch number when it is URL-safe, otherwise "~<id>" (batch numbers with spaces,
     * slashes or other odd characters, e.g. imported ones, would 404 as a URL segment).
     */
    public function getRouteKey(): mixed
    {
        $n = (string) $this->batch_number;

        return preg_match('/^[A-Za-z0-9._-]+$/', $n) ? $n : '~'.$this->getKey();
    }

    public function resolveRouteBinding($value, $field = null)
    {
        if ($field === null && preg_match('/^~(\d+)$/', (string) $value, $m)) {
            return static::whereKey((int) $m[1])->first();
        }

        return static::where($field ?? 'batch_number', $value)->first();
    }

    /**
     * Batch picker search (newest first, void excluded). Every typed word must match one of: batch
     * number, barcode number, product name (it holds the strain, e.g. "Watermelon Zkittlez 0.7g"),
     * product type / label wording, product code or brand. Case-insensitive.
     */
    public static function search(string $q, int $limit = 50)
    {
        $q = trim(preg_replace('/\s+/u', ' ', $q));
        $query = static::with('product.brand')->where('status', '!=', 'void')->orderByDesc('id')->limit($limit);
        if ($q === '') {
            return $query->get();
        }
        foreach (preg_split('/\s+/u', $q) as $word) {
            $w = '%'.$word.'%';
            $lw = mb_strtolower($w);
            $query->where(fn ($x) => $x->whereRaw('LOWER(batch_number) LIKE ?', [$lw])->orWhereRaw('LOWER(COALESCE(barcode_number, \'\')) LIKE ?', [$lw])
                ->orWhereHas('product', fn ($p) => $p->whereRaw('LOWER(name) LIKE ?', [$lw])->orWhereRaw('LOWER(COALESCE(product_type, \'\')) LIKE ?', [$lw])
                    ->orWhereRaw('LOWER(COALESCE(label_type_text, \'\')) LIKE ?', [$lw])->orWhereRaw('LOWER(COALESCE(short_code, \'\')) LIKE ?', [$lw])
                    ->orWhereHas('brand', fn ($b) => $b->whereRaw('LOWER(name) LIKE ?', [$lw]))));
        }

        return $query->get();
    }

    public function product() { return $this->belongsTo(Product::class); }
    public function mmp() { return $this->belongsTo(Mmp::class); }
    public function room() { return $this->belongsTo(Room::class); }
    public function steps() { return $this->hasMany(BatchStep::class)->orderBy('step_no'); }
    public function people() { return $this->hasMany(BatchPerson::class)->whereNull('voided_at'); }
    public function equipment() { return $this->hasMany(BatchEquipment::class)->whereNull('voided_at'); }
    public function calculations() { return $this->hasMany(BatchCalculation::class)->orderBy('id'); }
    public function materialLines() { return $this->hasMany(BatchMaterialLine::class)->orderBy('id'); }
    public function mmpStepRecords() { return $this->hasMany(BatchMmpStep::class)->orderBy('step_no')->orderBy('id'); }
    public function deviations() { return $this->hasMany(Deviation::class)->orderBy('id'); }
    public function qcReviewer() { return $this->belongsTo(User::class, 'qc_reviewer_user_id'); }

    public function step(int $no): ?BatchStep
    {
        return $this->steps->firstWhere('step_no', $no) ?? $this->steps()->where('step_no', $no)->first();
    }

    public function currentCalculation(): ?BatchCalculation
    {
        return $this->calculations()->where('superseded', false)->latest('id')->first();
    }

    public function label(): string { return $this->batch_number; }

    // ---- OCM sampling / retention (computed from the planned units; see App\Support\OcmSampling)
    public function servingType(): string { return \App\Support\OcmSampling::servingFor($this->product); }
    public function labSamplesPerTest(): ?int { return \App\Support\OcmSampling::perTest((int) $this->target_units, $this->servingType()); }
    public function retentionSamples(): ?int { return \App\Support\OcmSampling::retention((int) $this->target_units, $this->servingType()); }
    public function retainUntil(): ?\Carbon\CarbonInterface { return \App\Support\OcmSampling::retainUntil($this->expiration_date); }
}
