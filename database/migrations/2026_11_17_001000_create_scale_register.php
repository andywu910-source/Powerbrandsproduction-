<?php

use App\Support\RecordColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * m4a: Scale register. Adds Weights & Measures details to scales, plus an append-only
 * inspection history. Also makes the daily "Scale check with test weight" checklist
 * one-per-scale, so each checklist records which scale was checked.
 * Nothing is dropped or deleted. The database refuses deletes on scale_inspections.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipment', function (Blueprint $t) {
            $t->string('location')->nullable()->after('room_id');
            $t->decimal('capacity', 14, 4)->nullable();
            $t->string('capacity_unit', 10)->nullable();
            $t->decimal('division', 14, 6)->nullable();          // readability "d", same unit as capacity
            $t->date('wm_reported_on')->nullable();              // reported to local weights & measures
            $t->date('wm_sealed_on')->nullable();                // inspected and sealed
            $t->date('last_inspected_on')->nullable();
            $t->date('inspection_due_on')->nullable()->index();
            $t->text('notes')->nullable();
        });

        Schema::create('scale_inspections', function (Blueprint $t) {
            $t->id();
            $t->string('public_id', 30)->unique();               // INS-000001
            $t->foreignId('equipment_id')->constrained('equipment');
            $t->string('kind', 20);                               // wm_inspection / wm_report / calibration / other
            $t->date('inspected_on');
            $t->string('inspector')->nullable();                  // agency / inspector / company
            $t->string('result', 20);                             // passed_sealed / passed / reported / failed
            $t->string('seal_number')->nullable();
            $t->date('next_due_on')->nullable();
            $t->text('notes')->nullable();
            RecordColumns::add($t, false);
        });

        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb', 'sqlite'], true)) {
            $del = 'Records cannot be deleted. Void the record with a reason instead.';
            DB::unprepared($driver !== 'sqlite'
                ? "CREATE TRIGGER pbp_nodel_scale_inspections BEFORE DELETE ON `scale_inspections` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$del}'"
                : "CREATE TRIGGER pbp_nodel_scale_inspections BEFORE DELETE ON \"scale_inspections\" BEGIN SELECT RAISE(ABORT, '{$del}'); END");
        }

        // The daily scale check becomes one checklist per active scale (it records which scale).
        $tpl = DB::table('checklist_templates')->where('code', 'scale_check_daily')->first();
        if ($tpl && $tpl->scope_type === 'none') {
            DB::table('checklist_templates')->where('id', $tpl->id)->update(['scope_type' => 'scale', 'updated_at' => now()]);
            DB::table('audit_log')->insert([
                'user_id' => null, 'action' => 'update', 'record_type' => 'checklist_template', 'record_id' => $tpl->id,
                'details' => json_encode(['old' => ['scope_type' => 'none'], 'new' => ['scope_type' => 'scale'], 'note' => 'm4a upgrade: one daily scale check per registered scale']),
                'ip' => null, 'user_agent' => 'console (m4a upgrade)', 'created_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS pbp_nodel_scale_inspections');
        Schema::dropIfExists('scale_inspections');
        DB::table('checklist_templates')->where('code', 'scale_check_daily')->where('scope_type', 'scale')->update(['scope_type' => 'none']);
        Schema::table('equipment', function (Blueprint $t) {
            $t->dropIndex(['inspection_due_on']);
            $t->dropColumn(['location', 'capacity', 'capacity_unit', 'division', 'wm_reported_on', 'wm_sealed_on', 'last_inspected_on', 'inspection_due_on', 'notes']);
        });
    }
};
