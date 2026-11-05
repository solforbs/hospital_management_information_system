<?php

use App\Models\Patient;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SMS and M-Pesa (Daraja STK push) integrations with per-tenant encrypted
 * credentials; break-glass support access for platform administrators; and
 * blind indexes so encrypted patient identifiers stay searchable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 30);                  // sms, mpesa
            $table->string('environment', 20)->default('simulator'); // simulator, sandbox, production
            $table->text('config')->nullable();              // encrypted JSON
            $table->boolean('enabled')->default(false);
            $table->timestamps();
            $table->unique(['tenant_id', 'provider']);
        });

        Schema::create('sms_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->nullable()->constrained()->nullOnDelete();
            $table->string('to', 20);
            $table->text('message');
            $table->string('purpose', 40);                   // appointment_reminder, immunisation_due, anc_due, payment, manual
            $table->string('status', 20)->default('queued'); // queued, sent, failed
            $table->string('provider_message_id')->nullable();
            $table->string('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'purpose', 'created_at']);
        });

        Schema::create('mpesa_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('phone', 15);
            $table->decimal('amount', 12, 2);
            $table->string('merchant_request_id')->nullable();
            $table->string('checkout_request_id')->nullable()->unique();
            $table->string('status', 20)->default('pending');  // pending, success, failed, cancelled
            $table->string('result_code', 10)->nullable();
            $table->string('result_desc')->nullable();
            $table->string('mpesa_receipt')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('support_access_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reason');
            $table->string('ticket_reference')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'expires_at']);
        });

        // Government identifiers are encrypted at rest; exact-match lookups use a keyed hash.
        Schema::table('patients', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'national_id']);
            $table->dropIndex(['tenant_id', 'cr_number']);
        });

        Schema::table('patients', function (Blueprint $table) {
            $table->text('national_id')->nullable()->change();
            $table->text('cr_number')->nullable()->change();
            $table->text('sha_number')->nullable()->change();
            $table->string('national_id_hash', 64)->nullable();
            $table->index(['tenant_id', 'national_id_hash']);
        });

        // Encrypt existing plaintext identifiers in place.
        DB::table('patients')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                $update = [];
                foreach (['national_id', 'cr_number', 'sha_number'] as $col) {
                    if ($row->{$col} !== null && $row->{$col} !== '' && ! Patient::looksEncrypted($row->{$col})) {
                        $update[$col] = Crypt::encryptString($row->{$col});
                    }
                }
                if ($row->national_id !== null && $row->national_id !== '') {
                    $plain = Patient::looksEncrypted($row->national_id) ? Crypt::decryptString($row->national_id) : $row->national_id;
                    $update['national_id_hash'] = Patient::identifierHash($plain);
                }
                if ($update) {
                    DB::table('patients')->where('id', $row->id)->update($update);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('patients', fn (Blueprint $t) => $t->dropColumn('national_id_hash'));
        foreach (['support_access_grants', 'mpesa_requests', 'sms_messages', 'integration_credentials'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
