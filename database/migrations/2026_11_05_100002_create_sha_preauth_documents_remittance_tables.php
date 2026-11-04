<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SHA completion: pre-authorisations, supporting claim documents,
 * remittances (payment advice) with automatic claim matching, and the
 * per-tenant secret that signs SHA/HIE status callbacks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preauthorizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('encounter_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->nullableMorphs('source');                     // admission, surgery
            $table->string('preauth_number');
            $table->string('gateway', 20)->default('manual');      // manual, sha
            $table->string('intervention_code', 30)->nullable();
            $table->json('diagnosis_codes')->nullable();
            $table->text('justification');
            $table->decimal('estimated_cost', 14, 2)->default(0);
            $table->string('status')->default('draft');           // draft, submitted, approved, partially_approved, rejected, cancelled
            $table->decimal('approved_amount', 14, 2)->nullable();
            $table->string('external_reference')->nullable();
            $table->string('external_status')->nullable();
            $table->string('response_note')->nullable();
            $table->json('gateway_response')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'preauth_number']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('claim_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('insurance_claim_id')->constrained()->cascadeOnDelete();
            $table->string('document_type');                       // discharge_summary, lab_report, invoice, consent, referral, other
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->string('external_reference')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('remittances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('payer')->default('SHA');
            $table->string('reference');                           // payment advice / batch no.
            $table->date('paid_on')->nullable();
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->string('source', 20);                          // callback, import, manual
            $table->unsignedInteger('matched_count')->default(0);
            $table->unsignedInteger('unmatched_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'payer', 'reference']);
        });

        Schema::create('remittance_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('remittance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('insurance_claim_id')->nullable()->constrained()->nullOnDelete();
            $table->string('claim_reference');
            $table->decimal('amount', 14, 2);
            $table->string('status', 20);                          // matched, unmatched, duplicate, exceeds_balance
            $table->string('note')->nullable();
            $table->timestamps();
        });

        Schema::table('hie_credentials', function (Blueprint $table) {
            $table->text('webhook_secret')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('hie_credentials', fn (Blueprint $t) => $t->dropColumn('webhook_secret'));
        foreach (['remittance_lines', 'remittances', 'claim_documents', 'preauthorizations'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
