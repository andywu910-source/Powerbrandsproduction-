<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Shared engine: settings, ids, signatures, corrections, audit, attachments, Drive outbox. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            // Unused while signing_auth_mode = name. Lets PINs be switched on later.
            $t->string('signing_pin_hash')->nullable()->after('password');
        });

        Schema::create('settings', function (Blueprint $t) {
            $t->id();
            $t->string('key', 80)->unique();
            $t->json('value')->nullable();
            $t->foreignId('updated_by')->nullable()->constrained('users');
            $t->timestamps();
        });

        Schema::create('id_sequences', function (Blueprint $t) {
            $t->string('prefix', 40)->primary();
            $t->unsignedBigInteger('last_value')->default(0);
            $t->timestamps();
        });

        Schema::create('signatures', function (Blueprint $t) {
            $t->id();
            $t->string('signable_type', 80);
            $t->unsignedBigInteger('signable_id');
            $t->string('meaning', 40);          // performed / verified / approved / received / qc_release ...
            $t->string('meaning_text', 255);    // plain-English meaning shown next to the signature
            $t->foreignId('user_id')->constrained('users');               // who signed
            $t->foreignId('recorded_by_user_id')->constrained('users');   // who was logged in
            $t->unsignedBigInteger('person_id')->nullable();
            $t->timestamp('signed_at');
            $t->string('auth_method', 20);      // name / pin / password
            $t->string('ip', 45)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->string('record_hash', 64);
            $t->timestamp('invalidated_at')->nullable();
            $t->unsignedBigInteger('invalidated_by_revision_id')->nullable();
            $t->timestamps();
            $t->index(['signable_type', 'signable_id']);
        });

        Schema::create('revisions', function (Blueprint $t) {
            $t->id();
            $t->string('record_type', 80);
            $t->unsignedBigInteger('record_id');
            $t->string('field', 80);
            $t->text('old_value')->nullable();
            $t->text('new_value')->nullable();
            $t->text('reason');
            $t->foreignId('corrected_by')->constrained('users');
            $t->foreignId('recorded_by_user_id')->constrained('users');
            $t->string('initials', 10);
            $t->timestamp('corrected_at');
            $t->timestamps();
            $t->index(['record_type', 'record_id']);
        });

        Schema::create('audit_log', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained('users');
            $t->string('action', 40);
            $t->string('record_type', 80)->nullable();
            $t->unsignedBigInteger('record_id')->nullable();
            $t->json('details')->nullable();
            $t->string('ip', 45)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['record_type', 'record_id']);
        });

        Schema::create('attachments', function (Blueprint $t) {
            $t->id();
            $t->string('record_type', 80);
            $t->unsignedBigInteger('record_id');
            $t->string('kind', 40);       // coa / attestation / source_records / coc / sop / other
            $t->string('filename');
            $t->string('path');
            $t->string('mime', 120)->nullable();
            $t->unsignedBigInteger('size')->default(0);
            $t->string('sha256', 64);
            $t->string('drive_file_id')->nullable();
            $t->foreignId('uploaded_by')->constrained('users');
            $t->timestamps();
            $t->index(['record_type', 'record_id']);
        });

        Schema::create('drive_filings', function (Blueprint $t) {
            $t->id();
            $t->string('record_type', 80);
            $t->unsignedBigInteger('record_id');
            $t->string('event', 40);            // signed / completed / released / correction / void ...
            $t->string('sheet_key', 80);        // e.g. Checklist_daily_room_clean
            $t->string('period', 7);            // YYYY-MM
            $t->json('row');
            $t->string('status', 12)->default('pending')->index(); // pending / filed / failed
            $t->unsignedInteger('attempts')->default(0);
            $t->text('last_error')->nullable();
            $t->string('spreadsheet_id')->nullable();
            $t->string('row_ref')->nullable();
            $t->timestamp('filed_at')->nullable();
            $t->timestamps();
            $t->index(['record_type', 'record_id']);
        });

        Schema::create('drive_spreadsheets', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();        // <sheet_key>_<YYYY-MM>_v1
            $t->string('spreadsheet_id');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['drive_spreadsheets', 'drive_filings', 'attachments', 'audit_log', 'revisions', 'signatures', 'id_sequences', 'settings'] as $tbl) {
            Schema::dropIfExists($tbl);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('signing_pin_hash'));
    }
};
