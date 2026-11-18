<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive: the Batch Log status Andy uses (Nov 17: "status which will include in production,
 * packaging/ coa labeling, finished and no qc"): in_production / packaging_coa / finished.
 * Existing batches get a starting value from App\Models\Batch::logStatusRule() (written once here, with
 * one audit_log entry listing the counts). Nothing else changes: batches.status, the QC fields, units
 * packed and the finished date are kept as they are (the Batch Log just no longer shows them).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('batches', function (Blueprint $t) {
            $t->string('log_status', 20)->nullable()->after('status')->index();
        });
        $counts = ['in_production' => 0, 'packaging_coa' => 0, 'finished' => 0];
        foreach (DB::table('batches')->whereNull('log_status')->orderBy('id')->get(['id', 'status', 'current_step', 'source', 'completed_at', 'qc_decision']) as $b) {
            $v = \App\Models\Batch::logStatusRule((array) $b);
            DB::table('batches')->where('id', $b->id)->update(['log_status' => $v]);
            $counts[$v]++;
        }
        if (Schema::hasTable('audit_log') && array_sum($counts) > 0) {
            DB::table('audit_log')->insert(['action' => 'batch_log_status_start', 'record_type' => null, 'record_id' => null, 'user_id' => null,
                'details' => json_encode(['counts' => $counts, 'rule' => \App\Models\Batch::LOG_STATUS_RULE]), 'created_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::table('batches', function (Blueprint $t) {
            $t->dropIndex(['log_status']);
            $t->dropColumn('log_status');
        });
    }
};
