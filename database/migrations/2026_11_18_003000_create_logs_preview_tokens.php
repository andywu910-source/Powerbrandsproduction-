<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-time Compliance hub preview links (php artisan logs:preview-link). Only the sha256 of the
 * token is stored. The links do nothing unless PBP_PREVIEW_LINKS=true.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logs_preview_tokens', function (Blueprint $t) {
            $t->id();
            $t->char('token_hash', 64)->unique();
            $t->foreignId('user_id')->constrained('users');
            $t->timestamp('expires_at');
            $t->timestamp('used_at')->nullable();
            $t->string('used_ip', 45)->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logs_preview_tokens');
    }
};
