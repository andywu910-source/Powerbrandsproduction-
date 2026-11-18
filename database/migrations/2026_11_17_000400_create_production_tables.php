<?php

use App\Support\RecordColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Production: batches (BPRs), their 9 gated steps, batching lines, MMP step records, deviations. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('batches', function (Blueprint $t) {
            $t->id();
            $t->string('public_id', 30)->unique();        // BPR-000001
            // THE batch identifier (Andy's rule): required, unique, never changes once the batch starts.
            // Also the finished lot number. Generated from the batch_number_pattern setting.
            $t->string('batch_number', 60)->unique();
            $t->text('batch_number_override_reason')->nullable();
            $t->foreignId('product_id')->constrained('products');
            $t->foreignId('mmp_id')->constrained('mmps');
            $t->unsignedInteger('mmp_version');
            $t->foreignId('room_id')->nullable()->constrained('rooms');
            $t->string('line')->nullable();
            $t->unsignedInteger('target_units');
            $t->decimal('target_potency', 10, 3)->nullable();
            $t->decimal('batch_size_g', 14, 4)->nullable();
            // in_process / in_qc / released / rejected / void
            $t->string('status', 20)->default('in_process')->index();
            $t->unsignedTinyInteger('current_step')->default(1);
            $t->timestamp('started_at')->nullable();
            $t->timestamp('completed_at')->nullable()->index();
            $t->string('output_metrc_uid')->nullable()->index();
            // Packaging (step 7)
            $t->unsignedInteger('units_packed')->nullable();
            $t->decimal('yield_pct', 7, 2)->nullable();
            // QC (step 9)
            $t->string('coa_lab')->nullable();
            $t->string('coa_sample_id')->nullable();
            $t->decimal('coa_potency', 10, 3)->nullable();
            $t->string('coa_result', 10)->nullable();   // pass / fail
            $t->string('qc_decision', 12)->nullable();   // released / rejected (human only)
            $t->foreignId('qc_reviewer_user_id')->nullable()->constrained('users');
            $t->text('qc_note')->nullable();
            RecordColumns::add($t);
        });

        Schema::create('batch_steps', function (Blueprint $t) {
            $t->id();
            $t->foreignId('batch_id')->constrained('batches');
            $t->unsignedTinyInteger('step_no');
            $t->string('code', 20);
            $t->string('status', 12)->default('locked');   // locked / open / complete
            $t->json('data')->nullable();
            $t->timestamp('opened_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->unsignedBigInteger('checklist_instance_id')->nullable();  // step 2 changeover cleaning
            $t->timestamps();
            $t->unique(['batch_id', 'step_no']);
        });

        Schema::create('batch_people', function (Blueprint $t) {
            $t->id();
            $t->foreignId('batch_id')->constrained('batches');
            $t->foreignId('person_id')->constrained('people');
            $t->string('task')->nullable();
            $t->boolean('ppe_ok')->default(false);
            $t->json('training_record_ids')->nullable();
            $t->foreignId('added_by')->constrained('users');
            $t->text('void_reason')->nullable();
            $t->timestamp('voided_at')->nullable();
            $t->timestamps();
        });

        Schema::create('batch_equipment', function (Blueprint $t) {
            $t->id();
            $t->foreignId('batch_id')->constrained('batches');
            $t->foreignId('equipment_id')->constrained('equipment');
            $t->date('calibration_due_on')->nullable();   // snapshot at check time
            $t->date('maintenance_due_on')->nullable();
            $t->boolean('condition_ok')->default(false);
            $t->foreignId('added_by')->constrained('users');
            $t->text('void_reason')->nullable();
            $t->timestamp('voided_at')->nullable();
            $t->timestamps();
        });

        Schema::create('batch_calculations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('batch_id')->constrained('batches');
            $t->foreignId('mmp_id')->constrained('mmps');
            $t->unsignedInteger('mmp_version');
            $t->unsignedInteger('target_units');
            $t->decimal('target_potency', 10, 3)->nullable();
            $t->json('inputs');
            $t->json('results');
            $t->foreignId('calculated_by')->constrained('users');
            $t->boolean('superseded')->default(false);
            $t->timestamps();
        });

        Schema::create('deviations', function (Blueprint $t) {
            $t->id();
            $t->string('public_id', 30)->unique();          // DEV-000001
            $t->foreignId('batch_id')->nullable()->constrained('batches');
            $t->foreignId('material_lot_id')->nullable()->constrained('material_lots');
            $t->string('source_type', 80)->nullable();      // what triggered it
            $t->unsignedBigInteger('source_id')->nullable();
            $t->string('title');
            $t->text('description');
            $t->text('impact')->nullable();
            $t->boolean('active_ingredient')->default(false);
            $t->boolean('requires_qc_approval')->default(false);
            // open / approved_to_continue / closed / void
            $t->string('status', 24)->default('open')->index();
            $t->text('root_cause')->nullable();
            $t->text('corrective_action')->nullable();
            $t->text('disposition')->nullable();
            $t->timestamp('closed_at')->nullable();
            RecordColumns::add($t);
        });

        Schema::create('batch_material_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('batch_id')->constrained('batches');
            $t->foreignId('mmp_line_id')->constrained('mmp_lines');
            $t->foreignId('ingredient_id')->constrained('ingredients');
            $t->foreignId('batch_calculation_id')->constrained('batch_calculations');
            $t->decimal('planned_qty', 14, 4);
            $t->string('unit', 10);
            $t->foreignId('material_lot_id')->constrained('material_lots');
            $t->string('metrc_package_uid')->nullable();
            $t->foreignId('reservation_id')->nullable()->constrained('lot_reservations');
            $t->decimal('actual_qty', 14, 4)->nullable();
            $t->decimal('variance_pct', 9, 3)->nullable();
            $t->decimal('tolerance_pct', 6, 3)->nullable();
            $t->boolean('within_tolerance')->nullable();
            $t->foreignId('scale_equipment_id')->nullable()->constrained('equipment');
            $t->foreignId('deviation_id')->nullable()->constrained('deviations');
            $t->unsignedBigInteger('ledger_entry_id')->nullable();
            $t->string('status', 12)->default('picked');  // picked / weighed / verified / consumed / void
            $t->text('void_reason')->nullable();
            $t->timestamp('voided_at')->nullable();
            $t->foreignId('voided_by')->nullable()->constrained('users');
            $t->timestamps();
        });

        Schema::create('batch_mmp_steps', function (Blueprint $t) {
            $t->id();
            $t->foreignId('batch_id')->constrained('batches');
            $t->foreignId('mmp_step_id')->constrained('mmp_steps');
            $t->unsignedInteger('step_no');
            $t->string('stage_code', 30)->nullable();
            $t->decimal('actual_value', 14, 4)->nullable();
            $t->text('observation')->nullable();
            $t->boolean('within_limit')->nullable();
            $t->timestamp('performed_at');
            $t->unsignedBigInteger('performed_by_person_id')->nullable();
            $t->json('staff_person_ids')->nullable();   // everyone who worked this step, by name
            $t->foreignId('deviation_id')->nullable()->constrained('deviations');
            $t->string('status', 12)->default('recorded');  // recorded / verified / void
            $t->foreignId('recorded_by_user_id')->constrained('users');
            $t->text('late_entry_reason')->nullable();
            $t->text('void_reason')->nullable();
            $t->timestamp('voided_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['batch_mmp_steps', 'batch_material_lines', 'deviations', 'batch_calculations', 'batch_equipment', 'batch_people', 'batch_steps', 'batches'] as $tbl) {
            Schema::dropIfExists($tbl);
        }
    }
};
