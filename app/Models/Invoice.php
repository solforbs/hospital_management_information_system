<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'patient_id',
        'encounter_id',
        'patient_insurance_id',
        'invoice_number',
        'payer_type',
        'status',
        'subtotal',
        'discount',
        'total',
        'amount_paid',
        'balance',
        'discount_reason',
        'discount_approved_by',
        'voided_at',
        'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'total' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'balance' => 'decimal:2',
            'voided_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class, 'encounter_id');
    }

    public function insurance(): BelongsTo
    {
        return $this->belongsTo(PatientInsurance::class, 'patient_insurance_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class, 'invoice_id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class, 'invoice_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'invoice_id');
    }

    public function claims(): HasMany
    {
        return $this->hasMany(InsuranceClaim::class, 'invoice_id');
    }
}
