<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Staff sign-off (Andy, Nov 19): every sign-off keeps the signer's NAME as it was when signed, and an admin can
 * void a sign-off with a reason (the sign-off stays on record, marked VOID; the reason is also in the audit log).
 * The database trigger that makes signatures append-only is rebuilt so the stored name is locked too.
 * Existing signatures are not touched (signer_name stays empty for them; the screen falls back to the user's name).
 */
return new class extends Migration
{
    private const MSG_UPD = 'This record is append-only and cannot be changed.';

    private const CORE = ['signable_type', 'signable_id', 'meaning', 'meaning_text', 'user_id', 'recorded_by_user_id', 'person_id', 'signed_at', 'auth_method', 'ip', 'user_agent', 'record_hash'];

    public function up(): void
    {
        if (! Schema::hasColumn('signatures', 'signer_name')) {
            Schema::table('signatures', function (Blueprint $t) {
                $t->string('signer_name', 255)->nullable()->after('user_id');
                $t->unsignedBigInteger('voided_by_user_id')->nullable();
                $t->text('void_reason')->nullable();
            });
        }
        $this->rebuildTrigger(array_merge(self::CORE, ['signer_name']));
    }

    public function down(): void
    {
        $this->rebuildTrigger(self::CORE);
        Schema::table('signatures', fn (Blueprint $t) => $t->dropColumn(['signer_name', 'voided_by_user_id', 'void_reason']));
    }

    private function rebuildTrigger(array $core): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb', 'sqlite'], true)) {
            return;
        }
        DB::unprepared('DROP TRIGGER IF EXISTS pbp_noupd_signatures');
        if ($driver !== 'sqlite') {
            $changed = implode(' OR ', array_map(fn ($c) => "NOT (OLD.`{$c}` <=> NEW.`{$c}`)", $core));
            DB::unprepared("CREATE TRIGGER pbp_noupd_signatures BEFORE UPDATE ON `signatures` FOR EACH ROW BEGIN
                IF {$changed} OR OLD.invalidated_at IS NOT NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".self::MSG_UPD."';
                END IF;
            END");
        } else {
            $changed = implode(' OR ', array_map(fn ($c) => "OLD.\"{$c}\" IS NOT NEW.\"{$c}\"", $core));
            DB::unprepared("CREATE TRIGGER pbp_noupd_signatures BEFORE UPDATE ON \"signatures\"
                WHEN {$changed} OR OLD.invalidated_at IS NOT NULL
                BEGIN SELECT RAISE(ABORT, '".self::MSG_UPD."'); END");
        }
    }
};
