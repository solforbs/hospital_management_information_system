<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Institution profile (identity, statutory numbers, contacts, branding,
 * payment details, document text) and signatories who sign the documents
 * the system prints (receipts, invoices, referral letters, discharge
 * summaries, lab reports, medical certificates...).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('short_name', 40)->nullable()->after('name');
            $table->string('motto')->nullable()->after('short_name');
            $table->string('ownership')->nullable()->after('type');            // public, private, faith_based, ngo, county
            $table->string('license_number')->nullable()->after('registration_number'); // KMPDC facility licence
            $table->string('kra_pin', 20)->nullable()->after('license_number');
            $table->string('nhif_sha_code')->nullable()->after('kra_pin');      // SHA provider / contract code
            $table->string('phone_alt')->nullable()->after('phone');
            $table->string('emergency_phone')->nullable()->after('phone_alt');
            $table->string('website')->nullable()->after('email');
            $table->string('po_box')->nullable()->after('address');
            $table->string('town')->nullable()->after('po_box');
            $table->string('sub_county')->nullable()->after('county');
            $table->string('logo_path')->nullable();
            $table->string('stamp_path')->nullable();
        });

        Schema::create('signatories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('title');                        // e.g. Medical Superintendent
            $table->string('qualifications')->nullable();   // e.g. MBChB, MMed
            $table->string('registration')->nullable();     // e.g. KMPDC A1234
            $table->string('signature_path')->nullable();
            $table->json('documents')->nullable();          // document keys this person signs
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['tenant_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signatories');
        Schema::table('tenants', fn (Blueprint $t) => $t->dropColumn([
            'short_name', 'motto', 'ownership', 'license_number', 'kra_pin', 'nhif_sha_code', 'phone_alt',
            'emergency_phone', 'website', 'po_box', 'town', 'sub_county', 'logo_path', 'stamp_path',
        ]));
    }
};
