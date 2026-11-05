<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'resulted_by_instrument_id',
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

    /** The analyser that produced the result, when it came from an instrument. */
    public function instrument(): BelongsTo
    {
        return $this->belongsTo(LabInstrument::class, 'resulted_by_instrument_id');
    }

    /** Parameters of a multi-parameter result (e.g. a full blood count). */
    public function components(): HasMany
    {
        return $this->hasMany(LabResultComponent::class)->orderBy('sort');
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
