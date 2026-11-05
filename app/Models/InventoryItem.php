<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class InventoryItem extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'code', 'name', 'category', 'unit', 'stock_on_hand', 'reorder_level', 'last_unit_cost', 'active'];

    protected function casts(): array
    {
        return ['stock_on_hand' => 'decimal:2', 'reorder_level' => 'decimal:2', 'last_unit_cost' => 'decimal:2', 'active' => 'boolean'];
    }

    public const CATEGORIES = ['consumables' => 'Medical consumables', 'lab_reagents' => 'Lab reagents', 'linen' => 'Linen', 'stationery' => 'Stationery', 'cleaning' => 'Cleaning & sanitation', 'equipment' => 'Small equipment', 'other' => 'Other'];

    public function isLow(): bool
    {
        return (float) $this->stock_on_hand <= (float) $this->reorder_level;
    }
}
