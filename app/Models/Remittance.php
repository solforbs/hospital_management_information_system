<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Remittance extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'payer', 'reference', 'paid_on', 'total_amount', 'source', 'matched_count', 'unmatched_count', 'created_by',
    ];

    protected function casts(): array
    {
        return ['paid_on' => 'date', 'total_amount' => 'decimal:2'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(RemittanceLine::class);
    }
}
