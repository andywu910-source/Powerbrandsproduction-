<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // admin | supervisor | operator
            $table->string('role', 20)->default('operator')->after('password')->index();
            $table->boolean('must_change_password')->default(false)->after('role');
            $table->boolean('is_active')->default(true)->after('must_change_password');
            $table->timestamp('last_login_at')->nullable()->after('is_active');
            // GOOGLE SIGN-IN (future): add a nullable unique `google_id` column here
            // in a new migration when Google sign-in is introduced.
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropColumn(['role', 'must_change_password', 'is_active', 'last_login_at']);
        });
    }
};
