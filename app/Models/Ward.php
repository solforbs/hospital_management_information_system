<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ward extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'facility_id',
        'name',
        'code',
        'type',
        'gender',
        'daily_rate',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'daily_rate' => 'decimal:2',
        ];
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'facility_id');
    }

    public function beds(): HasMany
    {
        return $this->hasMany(Bed::class, 'ward_id');
    }

    public function admissions(): HasMany
    {
        return $this->hasMany(Admission::class, 'ward_id');
    }
}
