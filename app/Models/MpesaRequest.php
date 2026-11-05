<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MpesaRequest extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'invoice_id', 'payment_id', 'phone', 'amount', 'merchant_request_id', 'checkout_request_id', 'status', 'result_code', 'result_desc', 'mpesa_receipt', 'requested_by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
