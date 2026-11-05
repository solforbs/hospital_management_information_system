<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Child welfare clinic: KEPI immunisations, growth monitoring, and the
 * HIV-exposed infant (HEI) follow-up cohort with infant testing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('immunizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->string('vaccine', 30);           // KEPI key, e.g. penta_1
            $table->date('given_on');
            $table->string('batch_number', 40)->nullable();
            $table->string('site', 40)->nullable();
            $table->boolean('given_elsewhere')->default(false);
            $table->string('notes')->nullable();
            $table->foreignId('given_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['patient_id', 'vaccine']);
        });

        Schema::create('growth_measurements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->date('measured_on');
            $table->decimal('weight_kg', 5, 2)->nullable();
            $table->decimal('height_cm', 5, 1)->nullable();
            $table->decimal('muac_cm', 4, 1)->nullable();
            $table->decimal('head_circumference_cm', 4, 1)->nullable();
            $table->string('nutrition_status', 20)->nullable();  // normal, mam, sam, overweight
            $table->boolean('vitamin_a')->default(false);
            $table->boolean('deworming')->default(false);
            $table->string('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('hei_enrolments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('mother_patient_id')->nullable()->constrained('patients')->nullOnDelete();
            $table->string('hei_number');
            $table->date('enrolled_on');
            $table->string('infant_prophylaxis')->nullable();   // AZT+NVP, NVP
            $table->date('ctx_started_on')->nullable();         // cotrimoxazole
            $table->string('feeding')->nullable();              // exclusive_breastfeeding, replacement, mixed
            $table->string('status', 30)->default('active');    // active, negative_discharged, positive_linked, lost, died, transferred_out
            $table->date('closed_on')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'hei_number']);
        });

        Schema::create('hei_tests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hei_enrolment_id')->constrained()->cascadeOnDelete();
            $table->string('test', 20);                         // pcr_6wk, pcr_6mo, pcr_12mo, antibody_18mo, confirmatory
            $table->date('sample_date');
            $table->string('result', 20)->default('pending');   // pending, negative, positive, rejected
            $table->date('result_date')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['hei_tests', 'hei_enrolments', 'growth_measurements', 'immunizations'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
