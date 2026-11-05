<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Set when a hospital admin adds someone who has no password yet.
            $table->timestamp('invited_at')->nullable()->after('email_verified_at');
            // Set once the invited person chooses their own password.
            $table->timestamp('password_set_at')->nullable()->after('invited_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['invited_at', 'password_set_at']);
        });
    }
};
