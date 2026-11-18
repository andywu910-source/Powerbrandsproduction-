<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One-off import of the Google Sheet 'AR/Distro Pickup' › Remediation/Lab into the Batch Log
 * (additive): where a batch came from (site / sheet), which import and sheet row, the Batch ID
 * exactly as written in the sheet and the sheet's other values. Imported batches have no recipe
 * and may have no unit count, so mmp_id / mmp_version / target_units become nullable
 * (batches started on the site still always have them; the production form requires them).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('batches', function (Blueprint $t) {
            $t->string('source', 10)->default('site')->index();        // site / sheet
            $t->string('import_id', 40)->nullable()->index();          // e.g. sheet-20261117-remediation-lab
            $t->unsignedInteger('sheet_row')->nullable();
            $t->string('legacy_batch_id', 120)->nullable();            // Batch ID cell exactly as in the sheet
            $t->json('sheet_fields')->nullable();                      // the sheet row's values
            $t->text('import_note')->nullable();
        });
        Schema::table('batches', function (Blueprint $t) {
            $t->unsignedBigInteger('mmp_id')->nullable()->change();
            $t->unsignedInteger('mmp_version')->nullable()->change();
            $t->unsignedInteger('target_units')->nullable()->change();
        });
        $this->restoreBatchTriggers();
    }

    /** SQLite rebuilds the table on ->change() and loses its triggers; put them back (MariaDB keeps them). */
    private function restoreBatchTriggers(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }
        $have = collect(DB::select("SELECT name FROM sqlite_master WHERE type='trigger' AND tbl_name='batches'"))->pluck('name')->all();
        if (! in_array('pbp_nodel_batches', $have, true)) {
            DB::unprepared("CREATE TRIGGER pbp_nodel_batches BEFORE DELETE ON \"batches\" BEGIN SELECT RAISE(ABORT, 'Records cannot be deleted. Void the record with a reason instead.'); END");
        }
        if (! in_array('pbp_noupd_batch_number', $have, true)) {
            DB::unprepared("CREATE TRIGGER pbp_noupd_batch_number BEFORE UPDATE OF batch_number ON \"batches\"
                WHEN OLD.batch_number IS NOT NEW.batch_number
                BEGIN SELECT RAISE(ABORT, 'A batch number cannot be changed once the batch has started.'); END");
        }
    }

    public function down(): void
    {
        Schema::table('batches', function (Blueprint $t) {
            $t->dropIndex(['source']);
            $t->dropIndex(['import_id']);
            $t->dropColumn(['source', 'import_id', 'sheet_row', 'legacy_batch_id', 'sheet_fields', 'import_note']);
        });
        $this->restoreBatchTriggers();
    }
};
