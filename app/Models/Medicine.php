<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Medicine extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'code',
        'generic_name',
        'brand_name',
        'form',
        'strength',
        'unit',
        'unit_price',
        'reorder_level',
        'is_controlled',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'is_controlled' => 'boolean',
            'unit_price' => 'decimal:2',
        ];
    }

    public function batches(): HasMany
    {
        return $this->hasMany(MedicineBatch::class, 'medicine_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'medicine_id');
    }

    public function displayName(): string
    {
        return trim($this->generic_name.' '.$this->strength.' '.$this->form);
    }

    public function stockOnHand(): int
    {
        return (int) $this->batches()->whereDate('expiry_date', '>=', now())->sum('quantity_available');
    }
}
