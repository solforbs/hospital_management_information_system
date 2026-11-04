<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedicineBatch extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'medicine_id',
        'batch_number',
        'expiry_date',
        'quantity_received',
        'quantity_available',
        'unit_cost',
        'supplier',
        'received_by',
    ];

    protected function casts(): array
    {
        return [
            'expiry_date' => 'date',
            'unit_cost' => 'decimal:2',
        ];
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class, 'medicine_id');
    }
}
