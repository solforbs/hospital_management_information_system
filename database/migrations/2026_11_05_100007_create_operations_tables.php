<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operations: patient merge markers, refunds, and general stores &
 * purchasing (suppliers, items, purchase orders, receipts and issues).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->foreignId('merged_into_id')->nullable()->constrained('patients')->nullOnDelete();
            $table->timestamp('merged_at')->nullable();
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('refund_number');
            $table->decimal('amount', 14, 2);
            $table->string('method', 30);
            $table->string('reference')->nullable();
            $table->string('reason');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'refund_number']);
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('kra_pin', 20)->nullable();
            $table->string('contact_person')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->string('category', 40);                 // consumables, lab_reagents, linen, stationery, cleaning, equipment, other
            $table->string('unit', 20);
            $table->decimal('stock_on_hand', 12, 2)->default(0);
            $table->decimal('reorder_level', 12, 2)->default(0);
            $table->decimal('last_unit_cost', 12, 2)->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->string('po_number');
            $table->string('status', 20)->default('draft');  // draft, approved, partially_received, received, cancelled
            $table->decimal('total', 14, 2)->default(0);
            $table->string('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'po_number']);
        });

        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 12, 2);
            $table->decimal('unit_cost', 12, 2);
            $table->decimal('quantity_received', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);                      // receipt, issue, adjustment
            $table->decimal('quantity', 12, 2);              // signed: + in, − out
            $table->decimal('unit_cost', 12, 2)->nullable();
            $table->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference')->nullable();
            $table->string('reason')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'inventory_item_id']);
        });
    }

    public function down(): void
    {
        foreach (['inventory_movements', 'purchase_order_lines', 'purchase_orders', 'inventory_items', 'suppliers', 'refunds'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('patients', function (Blueprint $t) {
            $t->dropConstrainedForeignId('merged_into_id');
            $t->dropColumn('merged_at');
        });
    }
};
