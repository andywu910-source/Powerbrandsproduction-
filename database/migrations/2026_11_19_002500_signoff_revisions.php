<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Revise-and-reconfirm history for sign-offs (Andy, Nov 19). A signed compliance item can be revised by the person who
 * signed it (or an admin) and must then be re-confirmed. Every revision and confirmation is a new row here: who, when,
 * the old and new values, and the reason. Rows can never be changed or deleted (the database refuses both), so the
 * original sign-off + every revision + the final confirmation stay on record for OCM.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('signoff_revisions')) {
            Schema::create('signoff_revisions', function (Blueprint $t) {
                $t->id();
                $t->string('signable_type', 120);
                $t->unsignedBigInteger('signable_id');
                $t->unsignedBigInteger('signature_id')->nullable();   // the sign-off this revision belongs to / the re-confirmation signature
                $t->string('kind', 20);                               // revised | edited | confirmed
                $t->unsignedBigInteger('user_id')->nullable();
                $t->string('user_name', 255);
                $t->text('changes')->nullable();                      // JSON: {"Field": ["old", "new"]}
                $t->text('reason')->nullable();
                $t->timestamp('created_at')->nullable();
                $t->index(['signable_type', 'signable_id']);
            });
        }
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb', 'sqlite'], true)) {
            return;
        }
        DB::unprepared('DROP TRIGGER IF EXISTS pbp_nodel_signoff_revisions');
        DB::unprepared('DROP TRIGGER IF EXISTS pbp_noupd_signoff_revisions');
        if ($driver !== 'sqlite') {
            DB::unprepared("CREATE TRIGGER pbp_nodel_signoff_revisions BEFORE DELETE ON `signoff_revisions` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Records cannot be deleted. Void the record with a reason instead.'");
            DB::unprepared("CREATE TRIGGER pbp_noupd_signoff_revisions BEFORE UPDATE ON `signoff_revisions` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This record is append-only and cannot be changed.'");
        } else {
            DB::unprepared("CREATE TRIGGER pbp_nodel_signoff_revisions BEFORE DELETE ON \"signoff_revisions\" BEGIN SELECT RAISE(ABORT, 'Records cannot be deleted. Void the record with a reason instead.'); END");
            DB::unprepared("CREATE TRIGGER pbp_noupd_signoff_revisions BEFORE UPDATE ON \"signoff_revisions\" BEGIN SELECT RAISE(ABORT, 'This record is append-only and cannot be changed.'); END");
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS pbp_nodel_signoff_revisions');
        DB::unprepared('DROP TRIGGER IF EXISTS pbp_noupd_signoff_revisions');
        Schema::dropIfExists('signoff_revisions');
    }
};
