<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Additive: a schedule note (Going to lab / Pending) can point at a batch. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('schedule_notes', 'batch_id')) {
            Schema::table('schedule_notes', function (Blueprint $t) {
                $t->foreignId('batch_id')->nullable()->after('section')->constrained('batches');
            });
        }
    }

    public function down(): void
    {
        Schema::table('schedule_notes', function (Blueprint $t) {
            $t->dropConstrainedForeignId('batch_id');
        });
    }
};
