<?php

namespace App\Support;

use Illuminate\Database\Schema\Blueprint;

/** Common columns every compliance record carries (BUILD-PLAN B3). */
class RecordColumns
{
    public static function add(Blueprint $t, bool $filing = true): void
    {
        $t->foreignId('recorded_by_user_id')->nullable()->constrained('users');
        $t->text('late_entry_reason')->nullable();
        $t->date('retention_until')->nullable()->index();
        $t->text('void_reason')->nullable();
        $t->foreignId('voided_by')->nullable()->constrained('users');
        $t->timestamp('voided_at')->nullable();
        if ($filing) {
            // none = nothing signed yet; pending = signed, waiting for Drive; filed; failed
            $t->string('filing_status', 12)->default('none')->index();
        }
        $t->timestamps();
    }
}
