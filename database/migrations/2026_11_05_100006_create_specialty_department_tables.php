<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Level 5–6 departments: blood bank (units, requests, issue, reactions),
 * dialysis sessions, and specialist clinics (oncology, physiotherapy,
 * dental, eye, ENT, ...) with structured, per-clinic visit records.
 * The mortuary builds on death_notifications.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blood_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('unit_number');
            $table->string('abo', 2);                       // A, B, AB, O
            $table->string('rh', 3);                        // pos, neg
            $table->string('component', 30);                // whole_blood, prbc, ffp, platelets, cryo
            $table->unsignedSmallInteger('volume_ml')->nullable();
            $table->date('collected_on')->nullable();
            $table->date('expires_on');
            $table->string('source')->default('KNBTS');     // KNBTS (Kenya National Blood Transfusion Service), donation
            $table->string('donor_name')->nullable();
            $table->string('status', 20)->default('available'); // available, reserved, issued, discarded, expired
            $table->foreignId('reserved_for_request_id')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->string('discard_reason')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'unit_number']);
            $table->index(['tenant_id', 'status', 'abo', 'rh']);
        });

        Schema::create('blood_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('encounter_id')->nullable()->constrained()->nullOnDelete();
            $table->string('request_number');
            $table->string('patient_abo', 2)->nullable();
            $table->string('patient_rh', 3)->nullable();
            $table->string('component', 30);
            $table->unsignedTinyInteger('units_requested');
            $table->string('urgency', 20)->default('routine'); // routine, urgent, emergency
            $table->string('indication');
            $table->string('status', 20)->default('pending');   // pending, crossmatched, issued, cancelled
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'request_number']);
        });

        Schema::create('blood_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('blood_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('blood_unit_id')->constrained()->cascadeOnDelete();
            $table->string('crossmatch', 20);                   // compatible, incompatible
            $table->timestamp('issued_at');
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reaction', 20)->nullable();         // none, mild, severe
            $table->text('reaction_notes')->nullable();
            $table->timestamps();
        });

        Schema::create('dialysis_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('encounter_id')->nullable()->constrained()->nullOnDelete();
            $table->string('session_number');
            $table->string('modality', 20)->default('hd');      // hd, hdf, pd
            $table->string('machine')->nullable();
            $table->string('access', 20)->nullable();           // av_fistula, catheter, graft
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->decimal('pre_weight_kg', 5, 2)->nullable();
            $table->decimal('post_weight_kg', 5, 2)->nullable();
            $table->unsignedSmallInteger('pre_systolic')->nullable();
            $table->unsignedSmallInteger('pre_diastolic')->nullable();
            $table->unsignedSmallInteger('post_systolic')->nullable();
            $table->unsignedSmallInteger('post_diastolic')->nullable();
            $table->unsignedSmallInteger('uf_goal_ml')->nullable();
            $table->unsignedSmallInteger('uf_achieved_ml')->nullable();
            $table->string('heparin')->nullable();
            $table->text('complications')->nullable();
            $table->string('status', 20)->default('in_progress'); // in_progress, completed, aborted
            $table->foreignId('nurse_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'session_number']);
        });

        Schema::create('specialty_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('encounter_id')->nullable()->constrained()->nullOnDelete();
            $table->string('clinic', 30);                       // oncology, physiotherapy, dental, eye, ent, ...
            $table->json('data')->nullable();                   // structured clinic fields
            $table->text('notes')->nullable();
            $table->date('next_visit_on')->nullable();
            $table->foreignId('seen_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'clinic', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['specialty_visits', 'dialysis_sessions', 'blood_issues', 'blood_requests', 'blood_units'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
