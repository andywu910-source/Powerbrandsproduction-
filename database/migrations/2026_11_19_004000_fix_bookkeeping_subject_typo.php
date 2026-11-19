<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Nov 19: default email subject typo. Only a saved default row that still has the exact typo is changed. */
return new class extends Migration
{
    public const TYPO = 'Oustanding Invoice(s) from Powerbrands';
    public const FIXED = 'Outstanding Invoice(s) from Powerbrands';

    public function up(): void
    {
        if (Schema::hasTable('bookkeeping_templates')) {
            DB::table('bookkeeping_templates')->where('company_id', '')->where('subject', self::TYPO)
                ->update(['subject' => self::FIXED, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Not reverted: the corrected spelling is right either way.
    }
};
