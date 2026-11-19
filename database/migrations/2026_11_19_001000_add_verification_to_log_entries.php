<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive (Nov 19): every Compliance hub entry is verified by an admin (a different person than the one who
 * entered it). pending -> verified (admin e-signature) or returned (sent back with a reason: the entry is voided,
 * so it no longer counts, and the reason is kept). Existing entries start as pending.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('log_entries', function (Blueprint $t) {
            $t->string('verification_status', 12)->default('pending')->after('entered_by_user_id');   // pending | verified | returned
            $t->foreignId('verified_by_user_id')->nullable()->after('verification_status')->constrained('users');
            $t->timestamp('verified_at')->nullable()->after('verified_by_user_id');
            $t->unsignedBigInteger('verification_signature_id')->nullable()->after('verified_at');
            $t->index(['verification_status', 'voided_at']);
        });
        DB::table('log_entries')->whereNull('verification_status')->update(['verification_status' => 'pending']);
    }

    public function down(): void
    {
        Schema::table('log_entries', function (Blueprint $t) {
            $t->dropIndex(['verification_status', 'voided_at']);
            $t->dropConstrainedForeignId('verified_by_user_id');
            $t->dropColumn(['verification_status', 'verified_at', 'verification_signature_id']);
        });
    }
};
