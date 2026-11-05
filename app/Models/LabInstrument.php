<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An analyser connected through a gateway. */
class LabInstrument extends Model
{
    use BelongsToTenant;

    public const PROTOCOLS = ['astm' => 'ASTM E1381/E1394 (LIS2-A2)', 'hl7' => 'HL7 v2.x (MLLP)'];

    protected $fillable = ['tenant_id', 'lab_gateway_id', 'code', 'name', 'manufacturer', 'model', 'protocol', 'bidirectional', 'active', 'last_message_at'];

    protected function casts(): array
    {
        return ['bidirectional' => 'boolean', 'active' => 'boolean', 'last_message_at' => 'datetime'];
    }

    public function gateway(): BelongsTo
    {
        return $this->belongsTo(LabGateway::class, 'lab_gateway_id');
    }

    public function mappings(): HasMany
    {
        return $this->hasMany(LabInstrumentMapping::class)->orderBy('sort')->orderBy('instrument_code');
    }
}
