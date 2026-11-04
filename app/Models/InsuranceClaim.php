<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InsuranceClaim extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'invoice_id',
        'patient_insurance_id',
        'claim_number',
        'preauthorization_number',
        'amount_claimed',
        'amount_approved',
        'status',
        'submitted_at',
        'responded_at',
        'rejection_reason',
        'submitted_by',
    ];

    protected function casts(): array
    {
        return [
            'amount_claimed' => 'decimal:2',
            'amount_approved' => 'decimal:2',
            'submitted_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function insurance(): BelongsTo
    {
        return $this->belongsTo(PatientInsurance::class, 'patient_insurance_id');
    }
}
