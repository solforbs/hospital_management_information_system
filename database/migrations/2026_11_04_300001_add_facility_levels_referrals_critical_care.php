<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kenya Essential Package for Health (KEPH) levels 1–6 on facilities, with
 * per-facility module overrides; inter-facility referrals (out, in and
 * back-referral); and critical-care (ICU/HDU/NICU) charting with per-ward
 * SHA intervention codes for per-diem claims.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facilities', function (Blueprint $table) {
            $table->unsignedTinyInteger('level')->nullable()->after('type'); // KEPH 1–6
            $table->json('enabled_modules')->nullable()->after('level');     // {module_key: bool} overrides
        });

        Schema::table('wards', function (Blueprint $table) {
            $table->string('sha_intervention_code', 30)->nullable();
        });

        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('facility_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('encounter_id')->nullable()->constrained()->nullOnDelete();          // visit referred from (outgoing)
            $table->foreignId('received_encounter_id')->nullable()->constrained('encounters')->nullOnDelete(); // visit opened on arrival (incoming)
            $table->string('referral_number');
            $table->string('direction');                     // outgoing, incoming
            $table->string('kind')->default('referral');     // referral, back_referral
            $table->string('other_facility_name');
            $table->string('other_facility_code', 40)->nullable(); // FR code / MFL
            $table->unsignedTinyInteger('other_facility_level')->nullable();
            $table->string('department')->nullable();        // service / specialty referred to
            $table->string('urgency')->default('routine');   // routine, urgent, emergency
            $table->text('reason');
            $table->text('clinical_summary')->nullable();
            $table->text('investigations')->nullable();
            $table->text('treatment_given')->nullable();
            $table->string('transport')->nullable();         // ambulance, private, public, self
            $table->string('referring_clinician')->nullable();
            $table->string('status')->default('draft');      // draft, sent, accepted, arrived, completed, rejected, cancelled
            $table->text('feedback')->nullable();            // back-referral / outcome notes from the receiver
            $table->string('status_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'referral_number']);
            $table->index(['tenant_id', 'direction', 'status']);
        });

        Schema::create('critical_care_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('admission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at');
            $table->unsignedSmallInteger('heart_rate')->nullable();
            $table->unsignedSmallInteger('systolic')->nullable();
            $table->unsignedSmallInteger('diastolic')->nullable();
            $table->unsignedSmallInteger('map')->nullable();
            $table->unsignedSmallInteger('resp_rate')->nullable();
            $table->unsignedTinyInteger('spo2')->nullable();
            $table->decimal('temperature', 4, 1)->nullable();
            $table->unsignedTinyInteger('gcs')->nullable();
            $table->unsignedTinyInteger('fio2')->nullable();         // %
            $table->string('ventilation')->nullable();               // room_air, oxygen, niv, invasive
            $table->unsignedTinyInteger('peep')->nullable();
            $table->decimal('blood_glucose', 5, 1)->nullable();
            $table->unsignedSmallInteger('intake_ml')->nullable();
            $table->unsignedSmallInteger('output_ml')->nullable();   // incl. urine
            $table->unsignedSmallInteger('urine_ml')->nullable();
            $table->string('sedation_score', 10)->nullable();        // RASS
            $table->unsignedTinyInteger('pain_score')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['admission_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('critical_care_observations');
        Schema::dropIfExists('referrals');
        Schema::table('wards', fn (Blueprint $t) => $t->dropColumn('sha_intervention_code'));
        Schema::table('facilities', fn (Blueprint $t) => $t->dropColumn(['level', 'enabled_modules']));
    }
};
