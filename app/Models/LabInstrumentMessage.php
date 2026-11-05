<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Log of every gateway message (results in, orders out), for troubleshooting and audit. */
class LabInstrumentMessage extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'lab_gateway_id', 'lab_instrument_id', 'direction', 'type', 'sample_id', 'status', 'detail', 'payload'];

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(LabInstrument::class, 'lab_instrument_id');
    }
}
