<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-time guest login links for the PREVIEW site only. Only the sha256 of the token is
 * stored. The /guest route is never registered unless APP_ENV=staging and
 * PREVIEW_GUEST_LOGIN=true, so on the live site this table simply stays empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preview_guest_links', function (Blueprint $t) {
            $t->id();
            $t->char('token_hash', 64)->unique();
            $t->foreignId('user_id')->constrained('users');
            $t->timestamp('expires_at');
            $t->timestamp('used_at')->nullable();
            $t->string('used_ip', 45)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preview_guest_links');
    }
};
