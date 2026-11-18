<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Database-level protection (BUILD-PLAN B4): the database itself refuses DELETE on
 * every compliance table, and refuses UPDATE on the append-only engine tables.
 * Works on MariaDB/MySQL (SIGNAL) and SQLite (RAISE, used by the test suite).
 * Other drivers are skipped (the app-level guards still apply).
 *
 * Note: a blanket "no DELETE" grant is not possible for the app's DB user, because
 * Laravel's own sessions / cache / jobs tables need DELETE. Triggers cover the
 * record tables instead.
 */
return new class extends Migration
{
    public const NO_DELETE = [
        'users', 'settings', 'id_sequences', 'signatures', 'revisions', 'audit_log', 'attachments',
        'drive_filings', 'people', 'training_records', 'rooms', 'equipment', 'suppliers', 'ingredients',
        'brands', 'products', 'product_aliases', 'process_templates', 'process_template_stages', 'pqps', 'mmps', 'mmp_lines', 'mmp_steps', 'material_lots', 'lot_ledger',
        'lot_reservations', 'batches', 'batch_steps', 'batch_people', 'batch_equipment',
        'batch_calculations', 'deviations', 'batch_material_lines', 'batch_mmp_steps',
        'checklist_templates', 'checklist_template_versions', 'checklist_template_items',
        'checklist_instances', 'checklist_instance_items',
    ];

    public const NO_UPDATE = ['revisions', 'audit_log', 'attachments', 'lot_ledger'];

    private const MSG_DEL = 'Records cannot be deleted. Void the record with a reason instead.';
    private const MSG_UPD = 'This record is append-only and cannot be changed.';

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb', 'sqlite'], true)) {
            return;
        }
        $mysql = $driver !== 'sqlite';

        foreach (self::NO_DELETE as $table) {
            DB::unprepared($mysql
                ? "CREATE TRIGGER pbp_nodel_{$table} BEFORE DELETE ON `{$table}` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".self::MSG_DEL."'"
                : "CREATE TRIGGER pbp_nodel_{$table} BEFORE DELETE ON \"{$table}\" BEGIN SELECT RAISE(ABORT, '".self::MSG_DEL."'); END");
        }

        foreach (self::NO_UPDATE as $table) {
            DB::unprepared($mysql
                ? "CREATE TRIGGER pbp_noupd_{$table} BEFORE UPDATE ON `{$table}` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".self::MSG_UPD."'"
                : "CREATE TRIGGER pbp_noupd_{$table} BEFORE UPDATE ON \"{$table}\" BEGIN SELECT RAISE(ABORT, '".self::MSG_UPD."'); END");
        }

        // Batch numbers never change once a batch exists.
        DB::unprepared($mysql
            ? "CREATE TRIGGER pbp_noupd_batch_number BEFORE UPDATE ON `batches` FOR EACH ROW BEGIN
                IF NOT (OLD.batch_number <=> NEW.batch_number) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A batch number cannot be changed once the batch has started.';
                END IF;
              END"
            : "CREATE TRIGGER pbp_noupd_batch_number BEFORE UPDATE OF batch_number ON \"batches\"
                WHEN OLD.batch_number IS NOT NEW.batch_number
                BEGIN SELECT RAISE(ABORT, 'A batch number cannot be changed once the batch has started.'); END");

        // Signatures: only the one-time "invalidated by correction" mark may be set.
        $core = ['signable_type', 'signable_id', 'meaning', 'meaning_text', 'user_id', 'recorded_by_user_id', 'person_id', 'signed_at', 'auth_method', 'ip', 'user_agent', 'record_hash'];
        if ($mysql) {
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

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb', 'sqlite'], true)) {
            return;
        }
        foreach (self::NO_DELETE as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS pbp_nodel_{$table}");
        }
        DB::unprepared('DROP TRIGGER IF EXISTS pbp_noupd_batch_number');
        foreach (array_merge(self::NO_UPDATE, ['signatures']) as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS pbp_noupd_{$table}");
        }
    }
};
