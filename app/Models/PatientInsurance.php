<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientInsurance extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'patient_id',
        'insurance_provider_id',
        'member_number',
        'scheme_name',
        'principal_member',
        'valid_until',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'valid_until' => 'date',
            'active' => 'boolean',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(InsuranceProvider::class, 'insurance_provider_id');
    }

    public function isValid(): bool
    {
        return $this->active && (! $this->valid_until || $this->valid_until->endOfDay()->isFuture());
    }
}
