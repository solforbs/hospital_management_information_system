<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Maternity (Kenya MCH): pregnancy register with risk assessment, antenatal
 * visits (MOH ANC profile), labour with WHO partograph, delivery record,
 * newborns (registered as patients), and postnatal visits for mother and baby.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pregnancies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('facility_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->string('anc_number');
            $table->date('lmp')->nullable();
            $table->date('edd')->nullable();
            $table->boolean('edd_by_ultrasound')->default(false);
            $table->unsignedTinyInteger('gravida')->default(1);
            $table->unsignedTinyInteger('parity')->default(0);
            $table->unsignedTinyInteger('abortions')->default(0);
            $table->unsignedTinyInteger('living_children')->default(0);
            $table->unsignedTinyInteger('previous_caesareans')->default(0);
            $table->string('blood_group', 5)->nullable();
            $table->string('rhesus', 10)->nullable();            // positive, negative
            $table->string('hiv_status', 20)->nullable();        // negative, positive, unknown
            $table->string('syphilis_status', 20)->nullable();   // negative, positive, unknown
            $table->string('hepatitis_b_status', 20)->nullable();
            $table->decimal('height_cm', 5, 1)->nullable();
            $table->json('risk_factors')->nullable();
            $table->string('risk_level', 10)->default('low');    // low, high
            $table->string('birth_plan_facility')->nullable();
            $table->string('status')->default('active');         // active, delivered, closed
            $table->string('outcome')->nullable();               // delivered, miscarriage, ectopic, transferred_out, lost_to_follow_up
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'anc_number']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('anc_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pregnancy_id')->constrained()->cascadeOnDelete();
            $table->foreignId('encounter_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('visit_number');
            $table->date('visit_date');
            $table->unsignedTinyInteger('gestation_weeks')->nullable();
            $table->decimal('weight_kg', 5, 1)->nullable();
            $table->unsignedSmallInteger('systolic')->nullable();
            $table->unsignedSmallInteger('diastolic')->nullable();
            $table->unsignedTinyInteger('fundal_height_cm')->nullable();
            $table->unsignedSmallInteger('fetal_heart_rate')->nullable();
            $table->string('presentation')->nullable();          // cephalic, breech, transverse, unknown
            $table->boolean('fetal_movement')->nullable();
            $table->string('urine_protein', 10)->nullable();     // nil, trace, +, ++, +++
            $table->decimal('hb', 4, 1)->nullable();
            $table->boolean('iptp_given')->default(false);       // IPTp-SP (malaria)
            $table->unsignedTinyInteger('td_dose')->nullable();  // tetanus-diphtheria dose number
            $table->boolean('ifas_given')->default(false);       // iron + folic acid
            $table->boolean('deworming')->default(false);
            $table->boolean('llin_given')->default(false);       // mosquito net
            $table->json('danger_signs')->nullable();
            $table->text('notes')->nullable();
            $table->date('next_visit_date')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('labours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pregnancy_id')->constrained()->cascadeOnDelete();
            $table->foreignId('encounter_id')->nullable()->constrained()->nullOnDelete();
            $table->string('labour_number');
            $table->timestamp('admitted_at');
            $table->string('onset')->default('spontaneous');     // spontaneous, induced, none (elective CS)
            $table->string('membranes')->default('intact');      // intact, ruptured
            $table->timestamp('membranes_ruptured_at')->nullable();
            $table->unsignedTinyInteger('gestation_weeks')->nullable();
            $table->string('status')->default('in_labour');      // in_labour, delivered, referred, cancelled
            $table->timestamp('delivered_at')->nullable();
            $table->string('delivery_mode')->nullable();         // svd, vacuum, forceps, breech, caesarean
            $table->boolean('amtsl')->nullable();                // active management of 3rd stage
            $table->boolean('uterotonic_given')->nullable();
            $table->boolean('placenta_complete')->nullable();
            $table->unsignedSmallInteger('blood_loss_ml')->nullable();
            $table->string('perineum')->nullable();              // intact, first, second, third, fourth, episiotomy
            $table->string('mother_condition')->nullable();      // stable, pph, eclampsia, sepsis, deceased, other
            $table->text('complications')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('delivered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'labour_number']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('partograph_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('labour_id')->constrained()->cascadeOnDelete();
            $table->timestamp('recorded_at');
            $table->unsignedSmallInteger('fetal_heart_rate')->nullable();
            $table->string('liquor', 2)->nullable();             // I intact, C clear, M meconium, B blood, A absent
            $table->string('moulding', 4)->nullable();           // 0, +, ++, +++
            $table->decimal('cervix_cm', 3, 1)->nullable();
            $table->unsignedTinyInteger('descent_fifths')->nullable();
            $table->unsignedTinyInteger('contractions_per_10')->nullable();
            $table->string('contraction_strength', 10)->nullable(); // <20, 20-40, >40 seconds
            $table->decimal('oxytocin_units', 4, 1)->nullable();
            $table->string('drugs')->nullable();
            $table->unsignedSmallInteger('pulse')->nullable();
            $table->unsignedSmallInteger('systolic')->nullable();
            $table->unsignedSmallInteger('diastolic')->nullable();
            $table->decimal('temperature', 4, 1)->nullable();
            $table->string('urine_protein', 10)->nullable();
            $table->unsignedSmallInteger('urine_volume_ml')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['labour_id', 'recorded_at']);
        });

        Schema::create('newborns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('labour_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mother_patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignId('baby_patient_id')->nullable()->constrained('patients')->nullOnDelete();
            $table->unsignedTinyInteger('birth_order')->default(1);
            $table->string('sex', 10);
            $table->string('outcome')->default('live');          // live, fresh_stillbirth, macerated_stillbirth
            $table->unsignedSmallInteger('birth_weight_g')->nullable();
            $table->unsignedTinyInteger('apgar_1')->nullable();
            $table->unsignedTinyInteger('apgar_5')->nullable();
            $table->unsignedTinyInteger('apgar_10')->nullable();
            $table->boolean('resuscitated')->default(false);
            $table->boolean('vitamin_k')->default(false);
            $table->boolean('teo_given')->default(false);        // tetracycline eye ointment
            $table->boolean('bcg_given')->default(false);
            $table->boolean('opv0_given')->default(false);
            $table->boolean('breastfed_within_1h')->default(false);
            $table->boolean('chlorhexidine_cord')->default(false);
            $table->string('birth_notification_number')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('pnc_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pregnancy_id')->constrained()->cascadeOnDelete();
            $table->foreignId('newborn_id')->nullable()->constrained()->nullOnDelete();
            $table->date('visit_date');
            $table->string('timing', 10);                        // 48h, 1-2wk, 4-6wk, 6mo
            $table->unsignedSmallInteger('systolic')->nullable();
            $table->unsignedSmallInteger('diastolic')->nullable();
            $table->decimal('temperature', 4, 1)->nullable();
            $table->string('uterus')->nullable();                // involuted, tender, not involuting
            $table->string('lochia')->nullable();                // normal, foul, excessive
            $table->string('breasts')->nullable();
            $table->string('perineum_wound')->nullable();
            $table->decimal('baby_weight_kg', 4, 2)->nullable();
            $table->decimal('baby_temperature', 4, 1)->nullable();
            $table->string('cord')->nullable();                  // clean, infected, fallen
            $table->string('feeding')->nullable();               // exclusive_breastfeeding, mixed, replacement
            $table->boolean('family_planning_counselled')->default(false);
            $table->string('family_planning_method')->nullable();
            $table->json('danger_signs')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['pnc_visits', 'newborns', 'partograph_entries', 'labours', 'anc_visits', 'pregnancies'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
