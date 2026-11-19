<?php

use App\Support\LogHub;
use App\Support\Retirements;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Nov 19 (Andy): retire hub logs LBL, WST, CAPA (definitions stay, set inactive; new 1-A Waste type field) and the
 * Checklists templates that duplicate CLN / BAL / SEC / EQ-CM. Nothing is deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        LogHub::syncDefinitions();   // reads config/pbp_logs.php directly if the cached config is old
        if (DB::table('log_definitions')->whereIn('code', ['LBL', 'WST', 'CAPA'])->where('active', true)->exists()) {
            throw new RuntimeException('Hub logs LBL / WST / CAPA were not retired (old config?). Run config:clear and migrate again.');
        }
        Retirements::applyChecklists();
    }

    public function down(): void
    {
        DB::table('log_definitions')->whereIn('code', ['LBL', 'WST', 'CAPA'])->update(['active' => true]);
        Retirements::undoChecklists();
    }
};
