<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Additive: optional planned date for Incoming jobs (batches with status "planned", not started yet). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('batches', function (Blueprint $t) {
            $t->date('planned_date')->nullable()->after('started_at');
        });
    }

    public function down(): void
    {
        Schema::table('batches', function (Blueprint $t) {
            $t->dropColumn('planned_date');
        });
    }
};
