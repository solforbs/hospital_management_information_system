<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laboratory and radiology: tenant catalogs, orders raised against an
 * encounter, and per-test result lifecycle (collect → enter → verify → release).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_tests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->string('category')->nullable();
            $table->string('specimen_type')->default('blood');
            $table->string('unit')->nullable();
            $table->decimal('reference_low', 10, 3)->nullable();
            $table->decimal('reference_high', 10, 3)->nullable();
            $table->string('reference_text')->nullable();
            $table->string('result_type')->default('numeric'); // numeric, text
            $table->decimal('price', 12, 2)->default(0);
            $table->unsignedSmallInteger('turnaround_minutes')->default(60);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('lab_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('encounter_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ordered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('order_number');
            $table->string('priority')->default('routine'); // routine, urgent, stat
            $table->string('status')->default('ordered'); // ordered, in_progress, completed, cancelled
            $table->text('clinical_notes')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'order_number']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('lab_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lab_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lab_test_id')->constrained()->restrictOnDelete();
            $table->string('status')->default('ordered'); // ordered, collected, resulted, verified, released, cancelled
            $table->string('specimen_number')->nullable();
            $table->timestamp('collected_at')->nullable();
            $table->foreignId('collected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('result_value')->nullable();
            $table->string('result_unit')->nullable();
            $table->string('reference_range')->nullable();
            $table->string('flag')->nullable(); // low, normal, high, critical
            $table->text('comments')->nullable();
            $table->timestamp('resulted_at')->nullable();
            $table->foreignId('resulted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('radiology_exams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->string('modality');
            $table->decimal('price', 12, 2)->default(0);
            $table->boolean('requires_preparation')->default(false);
            $table->string('preparation_instructions')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('radiology_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('encounter_id')->constrained()->cascadeOnDelete();
            $table->foreignId('radiology_exam_id')->constrained()->restrictOnDelete();
            $table->foreignId('ordered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('order_number');
            $table->string('priority')->default('routine');
            $table->string('status')->default('ordered'); // ordered, scheduled, performed, reported, verified, cancelled
            $table->text('clinical_indication')->nullable();
            $table->dateTime('scheduled_at')->nullable();
            $table->timestamp('performed_at')->nullable();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('findings')->nullable();
            $table->text('impression')->nullable();
            $table->timestamp('reported_at')->nullable();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'order_number']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        foreach (['radiology_orders', 'radiology_exams', 'lab_order_items', 'lab_orders', 'lab_tests'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
