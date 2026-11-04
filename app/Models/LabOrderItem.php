<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabOrderItem extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'lab_order_id',
        'lab_test_id',
        'status',
        'specimen_number',
        'collected_at',
        'collected_by',
        'result_value',
        'result_unit',
        'reference_range',
        'flag',
        'comments',
        'resulted_at',
        'resulted_by',
        'verified_at',
        'verified_by',
        'released_at',
        'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'collected_at' => 'datetime',
            'resulted_at' => 'datetime',
            'verified_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(LabOrder::class, 'lab_order_id');
    }

    public function test(): BelongsTo
    {
        return $this->belongsTo(LabTest::class, 'lab_test_id');
    }

    public function resultedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resulted_by');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
