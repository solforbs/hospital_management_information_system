<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A device gateway: the small agent on the hospital network that talks
        // to analysers (ASTM / HL7 v2) and relays to HMIS over HTTPS.
        Schema::create('lab_gateways', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('facility_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('token_hash', 64)->unique();
            $table->string('token_hint', 8);
            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->string('version', 30)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('lab_instruments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lab_gateway_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 40); // identifier used in the gateway config
            $table->string('name');
            $table->string('manufacturer')->nullable();
            $table->string('model')->nullable();
            $table->string('protocol', 10)->default('astm'); // astm, hl7
            $table->boolean('bidirectional')->default(false); // receives orders (worklist) from HMIS
            $table->boolean('active')->default(true);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
        });

        // Analyser assay/parameter code -> HMIS test (and component for panels).
        Schema::create('lab_instrument_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lab_instrument_id')->constrained()->cascadeOnDelete();
            $table->string('instrument_code', 40);
            $table->foreignId('lab_test_id')->constrained()->cascadeOnDelete();
            $table->string('component', 60)->nullable(); // null = the test's single result
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
            // One analyser code may serve several tests (GLU -> RBS or FBS); the
            // sample's ordered test decides which mapping applies.
            $table->unique(['lab_instrument_id', 'instrument_code', 'lab_test_id'], 'lab_instrument_mapping_unique');
        });

        // Individual parameters of a multi-parameter result (e.g. FBC: WBC, HGB, PLT...).
        Schema::create('lab_result_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lab_order_item_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('value', 60);
            $table->string('unit', 30)->nullable();
            $table->string('reference_range', 60)->nullable();
            $table->string('flag', 10)->nullable(); // low, high, critical, normal
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
            $table->unique(['lab_order_item_id', 'name']);
        });

        // Every message to/from a gateway, for troubleshooting and audit.
        Schema::create('lab_instrument_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lab_gateway_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lab_instrument_id')->nullable()->constrained()->nullOnDelete();
            $table->string('direction', 3); // in, out
            $table->string('type', 20); // results, orders, query
            $table->string('sample_id', 60)->nullable();
            $table->string('status', 20); // accepted, partial, unmatched, rejected, sent
            $table->text('detail')->nullable();
            $table->longText('payload')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status', 'created_at']);
        });

        Schema::table('lab_order_items', function (Blueprint $table) {
            $table->foreignId('resulted_by_instrument_id')->nullable()->after('resulted_by')->constrained('lab_instruments')->nullOnDelete();
            $table->index(['tenant_id', 'specimen_number']);
        });
    }

    public function down(): void
    {
        Schema::table('lab_order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('resulted_by_instrument_id');
            $table->dropIndex(['tenant_id', 'specimen_number']);
        });
        Schema::dropIfExists('lab_instrument_messages');
        Schema::dropIfExists('lab_result_components');
        Schema::dropIfExists('lab_instrument_mappings');
        Schema::dropIfExists('lab_instruments');
        Schema::dropIfExists('lab_gateways');
    }
};
