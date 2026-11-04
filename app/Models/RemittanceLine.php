<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RemittanceLine extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'remittance_id', 'insurance_claim_id', 'claim_reference', 'amount', 'status', 'note'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(InsuranceClaim::class, 'insurance_claim_id');
    }
}
