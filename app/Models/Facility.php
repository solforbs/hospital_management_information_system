<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Facility extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'name',
        'code',
        'type',
        'email',
        'phone',
        'address',
        'county',
        'timezone',
        'patient_number_prefix',
        'encounter_number_prefix',
        'invoice_number_prefix',
        'is_main',
        'active',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'is_main' => 'boolean',
            'active' => 'boolean',
            'settings' => 'array',
        ];
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    public function staffAssignments(): HasMany
    {
        return $this->hasMany(StaffAssignment::class);
    }
}
