<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Tracks each visit's submission to the national Shared Health Record (FHIR). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('encounters', function (Blueprint $table) {
            $table->string('shr_status', 20)->nullable();   // pending, sent, failed
            $table->timestamp('shr_sent_at')->nullable();
            $table->string('shr_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('encounters', fn (Blueprint $t) => $t->dropColumn(['shr_status', 'shr_sent_at', 'shr_error']));
    }
};
