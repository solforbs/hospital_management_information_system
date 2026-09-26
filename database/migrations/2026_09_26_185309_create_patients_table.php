<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('mrn');
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->date('date_of_birth')->nullable();
            $table->boolean('date_of_birth_estimated')->default(false);
            $table->string('sex'); // male, female, unknown
            $table->string('national_id')->nullable();
            $table->string('passport_number')->nullable();
            $table->string('phone')->nullable();
            $table->string('alternative_phone')->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('county')->nullable();
            $table->string('next_of_kin_name')->nullable();
            $table->string('next_of_kin_phone')->nullable();
            $table->string('next_of_kin_relationship')->nullable();
            $table->string('preferred_language')->nullable();
            $table->string('category')->default('general'); // general, minor, newborn, unidentified
            $table->string('photo_path')->nullable();
            $table->timestamp('registered_at')->useCurrent();
            $table->foreignId('registered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('facility_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'mrn']);
            $table->index(['tenant_id', 'last_name', 'first_name']);
            $table->index(['tenant_id', 'phone']);
            $table->index(['tenant_id', 'national_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patients');
    }
};
