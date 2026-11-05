<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryMovement extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'inventory_item_id', 'type', 'quantity', 'unit_cost', 'purchase_order_id', 'department_id', 'reference', 'reason', 'user_id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2', 'unit_cost' => 'decimal:2'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
