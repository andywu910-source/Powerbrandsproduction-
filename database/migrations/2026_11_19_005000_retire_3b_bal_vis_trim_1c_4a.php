<?php

use App\Support\LogHub;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Nov 19 (Andy): retire hub logs 3-B Container Label, BAL Balance Check and VIS Visitor Log (definitions and past
 * entries stay; inactive), drop the 1-C checkboxes from new entries, and give 4-A one Location + "Wrong location?"
 * (old Expected / Actual Location values still show on past entries). Nothing is deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        LogHub::syncDefinitions();   // reads config/pbp_logs.php directly if the cached config is old
        if (DB::table('log_definitions')->whereIn('code', ['3-B', 'BAL', 'VIS'])->where('active', true)->exists()) {
            throw new RuntimeException('Hub logs 3-B / BAL / VIS were not retired (old config?). Run config:clear and migrate again.');
        }
        $fourA = json_decode((string) DB::table('log_definitions')->where('code', '4-A')->value('fields'), true) ?: [];
        if (! collect($fourA)->contains('key', 'wrong_location')) {
            throw new RuntimeException('4-A was not updated (old config?). Run config:clear and migrate again.');
        }
    }

    public function down(): void
    {
        DB::table('log_definitions')->whereIn('code', ['3-B', 'BAL', 'VIS'])->update(['active' => true]);
        // Field changes follow config/pbp_logs.php: restore the old file and run LogHub::syncDefinitions().
    }
};
