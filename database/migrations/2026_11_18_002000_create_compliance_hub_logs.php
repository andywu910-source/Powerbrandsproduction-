<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Compliance hub (Andy, Nov 18): one definition per OCM / GMP log (fields = its Drive sheet headers, from
 * config/pbp_logs.php) and one entry per row anyone records. Entries are never deleted (void with a reason).
 * log_entry_links holds the indexed link keys (Metrc UID, batch, equipment, room, employee) used for "Related logs".
 */
return new class extends Migration
{
    private const NO_DELETE = ['log_definitions', 'log_entries', 'log_entry_links'];

    public function up(): void
    {
        Schema::create('log_definitions', function (Blueprint $t) {
            $t->id();
            $t->string('code', 20)->unique();
            $t->string('slug', 40)->unique();
            $t->string('name', 160);
            $t->string('group_key', 30)->index();
            $t->string('finding', 10)->nullable()->index();     // '1'..'14', 'T-1' or null
            $t->string('frequency', 20);                         // daily | weekly | monthly | quarterly | annual | per_batch | per_event
            $t->boolean('manager_only')->default(false);
            $t->boolean('confirmed')->default(true);             // false = placeholder fields (no Drive sheet found)
            $t->boolean('partial')->default(false);              // true = only part of the sheet's headers mapped
            $t->json('fields');                                  // [{key,label,type,req,link,options,meaning,new}]
            $t->string('drive_url', 500)->nullable();
            $t->text('note')->nullable();
            $t->unsignedInteger('sort_order')->default(0);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('log_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('log_definition_id')->constrained('log_definitions');
            $t->json('data');
            $t->date('occurred_on')->index();                    // the entry's own date (first date field), else the day it was entered
            $t->foreignId('entered_by_user_id')->constrained('users');
            $t->timestamp('voided_at')->nullable();
            $t->foreignId('voided_by')->nullable()->constrained('users');
            $t->text('void_reason')->nullable();
            $t->timestamps();
            $t->index(['log_definition_id', 'occurred_on']);
        });

        Schema::create('log_entry_links', function (Blueprint $t) {
            $t->id();
            $t->foreignId('log_entry_id')->constrained('log_entries');
            $t->string('kind', 20);                              // metrc_uid | batch | equipment | room | employee
            $t->string('value', 120);                            // normalised (upper case; employee names lower case)
            $t->string('field_key', 80)->nullable();
            $t->timestamps();
            $t->index(['kind', 'value']);
        });

        \App\Support\LogHub::syncDefinitions();
        if (DB::table('log_definitions')->count() === 0) {
            throw new \RuntimeException('Compliance hub: no log definitions were created from config/pbp_logs.php.');
        }

        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb', 'sqlite'], true)) {
            $msg = 'Records cannot be deleted. Void the record with a reason instead.';
            foreach (self::NO_DELETE as $table) {
                DB::unprepared($driver !== 'sqlite'
                    ? "CREATE TRIGGER pbp_nodel_{$table} BEFORE DELETE ON `{$table}` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$msg}'"
                    : "CREATE TRIGGER pbp_nodel_{$table} BEFORE DELETE ON \"{$table}\" BEGIN SELECT RAISE(ABORT, '{$msg}'); END");
            }
        }
    }

    public function down(): void
    {
        foreach (self::NO_DELETE as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS pbp_nodel_{$table}");
        }
        Schema::dropIfExists('log_entry_links');
        Schema::dropIfExists('log_entries');
        Schema::dropIfExists('log_definitions');
    }
};
