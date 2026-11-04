<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InsuranceProvider extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'name',
        'code',
        'type',
        'contact_email',
        'contact_phone',
        'requires_preauthorization',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'requires_preauthorization' => 'boolean',
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(PatientInsurance::class, 'insurance_provider_id');
    }
}
