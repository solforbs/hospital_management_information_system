<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Diagnosis extends Model
{
    use BelongsToTenant;

    protected $table = 'diagnoses';

    protected $fillable = [
        'tenant_id',
        'patient_id',
        'encounter_id',
        'code',
        'code_system',
        'description',
        'type',
        'certainty',
        'recorded_by',
    ];

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class, 'encounter_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }
}
