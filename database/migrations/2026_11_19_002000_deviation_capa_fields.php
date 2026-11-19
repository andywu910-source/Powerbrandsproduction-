<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Nov 19: CAPA merged into Deviations (source, preventive action, effectiveness check). Adds nullable columns only. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deviations', function (Blueprint $t) {
            $t->string('origin', 30)->nullable()->after('source_id');        // complaint / internal_audit / ocm_finding / mock_recall / production / other
            $t->string('origin_ref', 120)->nullable()->after('origin');      // e.g. "CMP entry #12"
            $t->text('preventive_action')->nullable()->after('corrective_action');
            $t->text('effectiveness_check')->nullable()->after('preventive_action');
        });
    }

    public function down(): void
    {
        Schema::table('deviations', function (Blueprint $t) {
            $t->dropColumn(['origin', 'origin_ref', 'preventive_action', 'effectiveness_check']);
        });
    }
};
