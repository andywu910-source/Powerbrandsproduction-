<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Weekly bookkeeping. Statements live on the private disk, never in this table. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookkeeping_weeks', function (Blueprint $table) {
            $table->id();
            $table->date('week_start')->unique();
            $table->string('ledger_status', 40)->default('idle');
            $table->date('ledger_updated_on')->nullable();
            $table->string('ledger_summary', 500)->nullable();
            $table->foreignId('ledger_updated_by')->nullable()->constrained('users');
            $table->timestamp('ledger_updated_at')->nullable();
            $table->timestamp('payments_recorded_at')->nullable();
            $table->foreignId('payments_recorded_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        Schema::create('bookkeeping_statements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bookkeeping_week_id')->constrained('bookkeeping_weeks');
            $table->string('account', 4);
            $table->string('original_name', 200);
            $table->string('path', 255);
            $table->string('mime', 80);
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64);
            $table->foreignId('uploaded_by')->constrained('users');
            $table->timestamps();
            $table->index(['bookkeeping_week_id', 'account']);
        });

        Schema::create('bookkeeping_templates', function (Blueprint $table) {
            $table->id();
            $table->string('company_id', 64)->default('');
            $table->string('company_name')->nullable();
            $table->string('subject', 200);
            $table->text('body');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique('company_id');
        });

        Schema::create('bookkeeping_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bookkeeping_week_id')->constrained('bookkeeping_weeks');
            $table->string('company_id', 64);
            $table->string('company_name');
            $table->json('recipients');
            $table->json('invoices');
            $table->string('subject', 200);
            $table->string('status', 20);
            $table->text('error')->nullable();
            $table->foreignId('sent_by')->constrained('users');
            $table->timestamp('sent_at');
            $table->timestamps();
            $table->index(['bookkeeping_week_id', 'sent_at']);
        });

        Schema::create('bookkeeping_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80)->unique();
            $table->json('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookkeeping_settings');
        Schema::dropIfExists('bookkeeping_sends');
        Schema::dropIfExists('bookkeeping_templates');
        Schema::dropIfExists('bookkeeping_statements');
        Schema::dropIfExists('bookkeeping_weeks');
    }
};
