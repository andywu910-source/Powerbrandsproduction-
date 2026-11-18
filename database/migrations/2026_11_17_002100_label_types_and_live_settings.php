<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Labels go live (m4-labels-live). Additive only:
 *  - label_prints.label_type (master / case) and .partial: the print log records which label was printed.
 *  - "Refuse scales that are overdue for inspection" is set OFF (Andy has not decided yet; an admin can
 *    switch it on in Settings). The change is in the audit log.
 * Existing brands keep requires_barcode as it is (false unless already set); Andy picks them in Setup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('label_prints', function (Blueprint $t) {
            $t->string('label_type', 10)->default('master')->after('template');
            $t->boolean('partial')->default(false)->after('label_type');
        });
        Setting::put('gate_refuse_uninspected_scales', false);
    }

    public function down(): void
    {
        Schema::table('label_prints', function (Blueprint $t) {
            $t->dropColumn(['label_type', 'partial']);
        });
    }
};
