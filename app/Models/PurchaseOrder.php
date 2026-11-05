<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'supplier_id', 'po_number', 'status', 'total', 'notes', 'created_by', 'approved_by', 'approved_at'];

    protected function casts(): array
    {
        return ['total' => 'decimal:2', 'approved_at' => 'datetime'];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }
}
