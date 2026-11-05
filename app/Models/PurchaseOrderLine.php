<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderLine extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'purchase_order_id', 'inventory_item_id', 'quantity', 'unit_cost', 'quantity_received'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2', 'unit_cost' => 'decimal:2', 'quantity_received' => 'decimal:2'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }
}
