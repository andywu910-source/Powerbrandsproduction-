<?php

use App\Support\RecordColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Checklists engine: versioned templates/items, scheduled instances, item answers. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checklist_templates', function (Blueprint $t) {
            $t->id();
            $t->string('code', 60)->unique();
            $t->string('name');
            $t->string('category', 20);       // cleaning / equipment / security / pest / audit / environment / signage
            $t->string('spec_ref')->nullable();
            $t->string('frequency', 12);      // daily / weekly / monthly / on_demand
            $t->unsignedTinyInteger('weekday')->nullable();       // 1 = Monday (weekly)
            $t->unsignedTinyInteger('day_of_month')->nullable();  // monthly
            $t->string('due_time', 5)->default('10:00');          // site time HH:MM
            $t->unsignedInteger('grace_hours')->default(2);       // overdue this long after due
            $t->string('scope_type', 12)->default('none');        // none / room / equipment
            $t->unsignedBigInteger('current_version_id')->nullable();
            $t->boolean('active')->default(true);
            RecordColumns::add($t, false);
        });

        Schema::create('checklist_template_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('template_id')->constrained('checklist_templates');
            $t->unsignedInteger('version_no');
            $t->string('completed_by_role', 20)->default('operator');  // operator / supervisor / qc
            $t->string('verifier_role', 20)->nullable();                 // null = no verifier
            $t->text('change_note')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->unique(['template_id', 'version_no']);
        });

        Schema::create('checklist_template_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('version_id')->constrained('checklist_template_versions');
            $t->unsignedInteger('item_no');
            $t->text('prompt');
            $t->string('response_type', 12)->default('pass_fail'); // pass_fail / yes_no / numeric / text
            $t->decimal('min', 14, 4)->nullable();
            $t->decimal('max', 14, 4)->nullable();
            $t->string('unit', 20)->nullable();
            $t->boolean('required')->default(true);
            $t->string('fail_requires', 12)->default('note'); // note / deviation
            $t->string('sop_ref')->nullable();
            $t->timestamps();
        });

        Schema::create('checklist_instances', function (Blueprint $t) {
            $t->id();
            $t->string('public_id', 30)->unique();          // CHK-000001
            $t->foreignId('template_id')->constrained('checklist_templates');
            $t->foreignId('version_id')->constrained('checklist_template_versions');
            $t->string('scope_key', 60)->default('');        // '' / room:3 / equipment:5 / batch:12
            $t->string('period_key', 20);                     // 2026-11-17 / 2026-W47 / 2026-11 / batch-12-1
            $t->timestamp('due_at')->nullable();
            $t->timestamp('overdue_at')->nullable();
            // due / in_progress / awaiting_verification / completed / overdue / void
            $t->string('status', 24)->default('due')->index();
            $t->foreignId('assigned_user_id')->nullable()->constrained('users');
            $t->unsignedBigInteger('performed_by_person_id')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->unsignedBigInteger('batch_id')->nullable()->index();
            $t->text('notes')->nullable();
            RecordColumns::add($t);
            $t->unique(['template_id', 'scope_key', 'period_key']);
        });

        Schema::create('checklist_instance_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('instance_id')->constrained('checklist_instances');
            $t->foreignId('template_item_id')->constrained('checklist_template_items');
            $t->string('response')->nullable();
            $t->boolean('within_limit')->nullable();
            $t->text('note')->nullable();
            $t->foreignId('deviation_id')->nullable()->constrained('deviations');
            $t->foreignId('answered_by')->nullable()->constrained('users');
            $t->timestamp('answered_at')->nullable();
            $t->timestamps();
            $t->unique(['instance_id', 'template_item_id']);
        });
    }

    public function down(): void
    {
        foreach (['checklist_instance_items', 'checklist_instances', 'checklist_template_items', 'checklist_template_versions', 'checklist_templates'] as $tbl) {
            Schema::dropIfExists($tbl);
        }
    }
};
