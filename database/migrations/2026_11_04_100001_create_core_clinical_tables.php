<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Core clinical workflow: generic numbering, audit trail, appointments,
 * encounters (the visit that every department order hangs off), the
 * encounter workflow history, triage vitals, allergies, notes, diagnoses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('number_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('key', 40);
            $table->string('period', 10);
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'key', 'period']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 80);
            $table->nullableMorphs('auditable');
            $table->json('properties')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'action']);
        });

        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('facility_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('doctor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('appointment_number');
            $table->dateTime('scheduled_at');
            $table->unsignedSmallInteger('duration_minutes')->default(15);
            $table->string('type')->default('consultation');
            $table->string('status')->default('booked'); // booked, confirmed, checked_in, completed, cancelled, no_show
            $table->string('reason')->nullable();
            $table->string('source')->default('reception');
            $table->foreignId('booked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'appointment_number']);
            $table->index(['tenant_id', 'scheduled_at']);
            $table->index(['tenant_id', 'doctor_id', 'scheduled_at']);
        });

        Schema::create('encounters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('facility_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('attending_doctor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('encounter_number');
            $table->string('type')->default('outpatient'); // outpatient, inpatient, emergency
            $table->string('stage')->default('waiting_triage');
            $table->string('status')->default('open'); // open, completed, cancelled
            $table->string('priority')->default('normal'); // normal, urgent, emergency
            $table->string('triage_category')->nullable(); // red, orange, yellow, green, blue
            $table->unsignedInteger('queue_number')->nullable();
            $table->string('payer_type')->default('cash');
            $table->string('chief_complaint')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'encounter_number']);
            $table->index(['tenant_id', 'stage', 'status']);
            $table->index(['tenant_id', 'patient_id']);
        });

        Schema::create('encounter_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('encounter_id')->constrained()->cascadeOnDelete();
            $table->string('from_stage')->nullable();
            $table->string('to_stage');
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['encounter_id', 'created_at']);
        });

        Schema::create('vital_signs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('encounter_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('admission_id')->nullable()->index();
            $table->decimal('temperature', 4, 1)->nullable();
            $table->unsignedSmallInteger('pulse')->nullable();
            $table->unsignedSmallInteger('respiratory_rate')->nullable();
            $table->unsignedSmallInteger('systolic_bp')->nullable();
            $table->unsignedSmallInteger('diastolic_bp')->nullable();
            $table->unsignedTinyInteger('spo2')->nullable();
            $table->decimal('weight_kg', 5, 1)->nullable();
            $table->decimal('height_cm', 5, 1)->nullable();
            $table->decimal('bmi', 4, 1)->nullable();
            $table->unsignedTinyInteger('pain_score')->nullable();
            $table->decimal('blood_glucose', 5, 1)->nullable();
            $table->string('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at')->useCurrent();
            $table->timestamps();
            $table->index(['patient_id', 'recorded_at']);
        });

        Schema::create('patient_allergies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->string('allergen');
            $table->string('reaction')->nullable();
            $table->string('severity')->default('moderate');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('clinical_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('encounter_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type')->default('consultation');
            $table->text('presenting_complaint')->nullable();
            $table->text('history')->nullable();
            $table->text('examination')->nullable();
            $table->text('assessment')->nullable();
            $table->text('plan')->nullable();
            $table->string('status')->default('draft'); // draft, signed, amended
            $table->timestamp('signed_at')->nullable();
            $table->foreignId('amends_note_id')->nullable()->constrained('clinical_notes')->nullOnDelete();
            $table->string('amendment_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('diagnoses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('encounter_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20)->nullable();
            $table->string('description');
            $table->string('type')->default('primary');
            $table->string('certainty')->default('confirmed');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['diagnoses', 'clinical_notes', 'patient_allergies', 'vital_signs', 'encounter_transitions', 'encounters', 'appointments', 'audit_logs', 'number_sequences'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
