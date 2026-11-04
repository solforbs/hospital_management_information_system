<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * National HIE (DHA AfyaLink) and SHA claims integration: registry
 * identifiers on facilities, patients and practitioners, SHA intervention
 * mapping on billable catalogs, eligibility/consent state on encounters,
 * per-tenant HIE credentials and a request log for audit and support.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facilities', function (Blueprint $table) {
            $table->string('fr_code', 40)->nullable()->after('code');   // Facility Registry code, e.g. FID-47-115307-8
            $table->string('mfl_code', 20)->nullable()->after('fr_code'); // Kenya Master Facility List code
            $table->string('keph_level', 20)->nullable()->after('mfl_code');
            $table->string('sha_contract_status')->nullable()->after('keph_level');
            $table->timestamp('registry_verified_at')->nullable()->after('sha_contract_status');
        });

        Schema::table('patients', function (Blueprint $table) {
            $table->string('cr_number', 40)->nullable()->after('national_id');   // Client Registry ID, e.g. CR7678914660684-5
            $table->string('sha_number', 40)->nullable()->after('cr_number');
            $table->string('identification_type', 40)->nullable()->after('sha_number');
            $table->timestamp('registry_synced_at')->nullable()->after('identification_type');
            $table->index(['tenant_id', 'cr_number']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('regulator', 10)->nullable();              // KMPDC, NCK, COC, PPB
            $table->string('registration_number', 40)->nullable();
            $table->date('license_valid_until')->nullable();
            $table->timestamp('registry_verified_at')->nullable();
        });

        foreach (['service_items', 'lab_tests', 'radiology_exams'] as $catalog) {
            Schema::table($catalog, function (Blueprint $table) {
                $table->string('sha_intervention_code', 30)->nullable();
            });
        }

        Schema::table('encounters', function (Blueprint $table) {
            $table->string('sha_service_type', 20)->nullable();        // CAPITATION, OUTPATIENT, INPATIENT, EMERGENCY
            $table->json('sha_interventions')->nullable();             // intervention codes consented for this visit
            $table->json('sha_eligibility')->nullable();               // last eligibility response snapshot
            $table->timestamp('sha_checked_at')->nullable();
            $table->text('sha_consent_token')->nullable();
            $table->string('sha_consent_method', 20)->nullable();      // otp, biometric
            $table->timestamp('sha_consented_at')->nullable();
            $table->string('sha_auth_guid')->nullable();               // pending biometric authorisation
        });

        Schema::table('insurance_claims', function (Blueprint $table) {
            $table->string('gateway', 20)->default('manual');
            $table->string('external_reference')->nullable();          // SHA invoice number / claim GUID
            $table->string('external_status')->nullable();
            $table->json('gateway_response')->nullable();
            $table->timestamp('last_synced_at')->nullable();
        });

        Schema::create('hie_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('environment', 20)->default('simulator'); // simulator, uat, production
            $table->string('base_url')->nullable();
            $table->string('token_url')->nullable();
            $table->text('client_id')->nullable();
            $table->text('client_secret')->nullable();
            $table->boolean('enabled')->default(false);
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_result')->nullable();
            $table->timestamps();
        });

        Schema::create('hie_request_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('environment', 20);
            $table->string('method', 10);
            $table->string('path');
            $table->unsignedSmallInteger('status')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->json('request')->nullable();
            $table->json('response')->nullable();
            $table->string('error')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hie_request_logs');
        Schema::dropIfExists('hie_credentials');

        Schema::table('insurance_claims', fn (Blueprint $t) => $t->dropColumn(['gateway', 'external_reference', 'external_status', 'gateway_response', 'last_synced_at']));
        Schema::table('encounters', fn (Blueprint $t) => $t->dropColumn(['sha_service_type', 'sha_interventions', 'sha_eligibility', 'sha_checked_at', 'sha_consent_token', 'sha_consent_method', 'sha_consented_at', 'sha_auth_guid']));
        foreach (['service_items', 'lab_tests', 'radiology_exams'] as $catalog) {
            Schema::table($catalog, fn (Blueprint $t) => $t->dropColumn('sha_intervention_code'));
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['regulator', 'registration_number', 'license_valid_until', 'registry_verified_at']));
        Schema::table('patients', function (Blueprint $t) {
            $t->dropIndex(['tenant_id', 'cr_number']);
            $t->dropColumn(['cr_number', 'sha_number', 'identification_type', 'registry_synced_at']);
        });
        Schema::table('facilities', fn (Blueprint $t) => $t->dropColumn(['fr_code', 'mfl_code', 'keph_level', 'sha_contract_status', 'registry_verified_at']));
    }
};
