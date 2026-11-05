<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Medical certificates / sick notes, and death notifications (cause of
 * death in ICD-11, WHO certificate structure) which the mortuary module
 * builds on for body storage and release.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medical_certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('encounter_id')->nullable()->constrained()->nullOnDelete();
            $table->string('certificate_number');
            $table->string('type', 20);                 // sick_leave, fitness, attendance
            $table->date('from_date')->nullable();
            $table->date('to_date')->nullable();
            $table->unsignedSmallInteger('days')->nullable();
            $table->string('diagnosis_text')->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'certificate_number']);
        });

        Schema::create('death_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('facility_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('encounter_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('admission_id')->nullable()->constrained()->nullOnDelete();
            $table->string('notification_number');
            $table->timestamp('died_at');
            $table->string('place', 30);                 // inpatient, casualty, theatre, brought_in_dead
            $table->string('immediate_cause');
            $table->string('immediate_icd', 20)->nullable();
            $table->string('underlying_cause')->nullable();
            $table->string('underlying_icd', 20)->nullable();
            $table->text('other_conditions')->nullable();
            $table->string('manner', 20)->default('natural'); // natural, accident, suicide, homicide, pending, unknown
            $table->boolean('is_maternal')->default(false);
            $table->boolean('autopsy_requested')->default(false);
            $table->foreignId('certified_by')->nullable()->constrained('users')->nullOnDelete();
            // Mortuary
            $table->string('body_status', 20)->default('in_ward'); // in_ward, in_mortuary, released
            $table->string('storage_unit')->nullable();
            $table->timestamp('mortuary_admitted_at')->nullable();
            $table->string('released_to')->nullable();
            $table->string('released_to_id_number')->nullable();
            $table->string('released_to_relationship')->nullable();
            $table->string('burial_permit_number')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'notification_number']);
            $table->index(['tenant_id', 'body_status']);
        });

        Schema::table('patients', function (Blueprint $table) {
            $table->timestamp('deceased_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('patients', fn (Blueprint $t) => $t->dropColumn('deceased_at'));
        Schema::dropIfExists('death_notifications');
        Schema::dropIfExists('medical_certificates');
    }
};
