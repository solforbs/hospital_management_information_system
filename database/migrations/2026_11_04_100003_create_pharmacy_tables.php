<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pharmacy: medicine catalog, batch-level stock with expiry (dispensed
 * first-expiry-first-out), prescriptions and an append-only stock ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medicines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('generic_name');
            $table->string('brand_name')->nullable();
            $table->string('form')->default('tablet');
            $table->string('strength')->nullable();
            $table->string('unit')->default('tablet');
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->unsignedInteger('reorder_level')->default(0);
            $table->boolean('is_controlled')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('medicine_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('medicine_id')->constrained()->cascadeOnDelete();
            $table->string('batch_number');
            $table->date('expiry_date');
            $table->unsignedInteger('quantity_received');
            $table->unsignedInteger('quantity_available');
            $table->decimal('unit_cost', 12, 2)->default(0);
            $table->string('supplier')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['medicine_id', 'expiry_date']);
        });

        Schema::create('prescriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('encounter_id')->constrained()->cascadeOnDelete();
            $table->foreignId('prescribed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('prescription_number');
            $table->string('status')->default('pending'); // pending, partially_dispensed, dispensed, cancelled
            $table->text('notes')->nullable();
            $table->timestamp('dispensed_at')->nullable();
            $table->foreignId('dispensed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'prescription_number']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('prescription_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('prescription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('medicine_id')->constrained()->restrictOnDelete();
            $table->string('dose');
            $table->string('frequency');
            $table->string('route')->default('oral');
            $table->unsignedSmallInteger('duration_days')->default(1);
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('quantity_dispensed')->default(0);
            $table->string('instructions')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('medicine_id')->constrained()->cascadeOnDelete();
            $table->foreignId('medicine_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type'); // receipt, dispense, adjustment, return, expiry_writeoff
            $table->integer('quantity'); // signed
            $table->nullableMorphs('reference');
            $table->string('reason')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['medicine_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['stock_movements', 'prescription_items', 'prescriptions', 'medicine_batches', 'medicines'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
