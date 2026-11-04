<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class RadiologyExam extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'modality',
        'price',
        'requires_preparation',
        'preparation_instructions',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'requires_preparation' => 'boolean',
            'price' => 'decimal:2',
        ];
    }
}
