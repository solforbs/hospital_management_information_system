<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Analyser assay code -> HMIS lab test (and component, for panels such as FBC). */
class LabInstrumentMapping extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'lab_instrument_id', 'instrument_code', 'lab_test_id', 'component', 'sort'];

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(LabInstrument::class, 'lab_instrument_id');
    }

    public function test(): BelongsTo
    {
        return $this->belongsTo(LabTest::class, 'lab_test_id');
    }
}
