<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Additive: the Weekly Schedule tab (new tables only; nothing existing changes). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_employees', function (Blueprint $t) {
            $t->id();
            $t->string('name', 80);
            $t->string('role', 80)->nullable();
            $t->decimal('default_hours', 4, 2)->default(8);
            $t->decimal('hourly_rate', 8, 2)->nullable();            // only used when labor dollars are switched on
            $t->json('recurring_off')->nullable();                   // weekdays always off: 1 = Monday ... 5 = Friday
            $t->boolean('active')->default(true);
            $t->unsignedInteger('sort_order')->default(0);
            $t->foreignId('user_id')->nullable()->constrained('users');
            $t->timestamps();
        });
        Schema::create('schedule_weeks', function (Blueprint $t) {
            $t->id();
            $t->date('week_start')->unique();                         // the Monday
            $t->foreignId('copied_from_id')->nullable()->constrained('schedule_weeks');
            $t->foreignId('created_by')->nullable()->constrained('users');
            $t->timestamps();
        });
        Schema::create('schedule_roster', function (Blueprint $t) {
            $t->id();
            $t->foreignId('week_id')->constrained('schedule_weeks');
            $t->foreignId('employee_id')->constrained('schedule_employees');
            $t->decimal('daily_hours', 4, 2);                         // that week's default day (copied from the employee)
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamp('removed_at')->nullable();
            $t->timestamps();
            $t->unique(['week_id', 'employee_id']);
        });
        Schema::create('schedule_cells', function (Blueprint $t) {
            $t->id();
            $t->foreignId('week_id')->constrained('schedule_weeks');
            $t->foreignId('employee_id')->constrained('schedule_employees');
            $t->date('day');
            $t->string('status', 10)->nullable();                     // working / off / partial; null = not scheduled
            $t->decimal('hours', 4, 2)->nullable();                   // partial-day hours
            $t->text('note')->nullable();
            $t->boolean('from_recurring')->default(false);
            $t->timestamps();
            $t->unique(['employee_id', 'day']);
        });
        Schema::create('schedule_jobs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cell_id')->constrained('schedule_cells');
            $t->foreignId('product_id')->nullable()->constrained('products');
            $t->unsignedInteger('target_qty')->nullable();
            $t->string('process_step', 60)->nullable();
            $t->string('note', 255)->nullable();
            $t->foreignId('batch_id')->nullable()->constrained('batches');
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamp('removed_at')->nullable();
            $t->timestamps();
        });
        Schema::create('schedule_notes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('week_id')->constrained('schedule_weeks');
            $t->string('section', 80);
            $t->string('text', 255);
            $t->boolean('done')->default(false);
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamp('removed_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['schedule_notes', 'schedule_jobs', 'schedule_cells', 'schedule_roster', 'schedule_weeks', 'schedule_employees'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
