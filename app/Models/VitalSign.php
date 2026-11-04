<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VitalSign extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'patient_id',
        'encounter_id',
        'admission_id',
        'temperature',
        'pulse',
        'respiratory_rate',
        'systolic_bp',
        'diastolic_bp',
        'spo2',
        'weight_kg',
        'height_cm',
        'bmi',
        'pain_score',
        'blood_glucose',
        'notes',
        'recorded_by',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class, 'encounter_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function bloodPressure(): ?string
    {
        return $this->systolic_bp ? "{$this->systolic_bp}/{$this->diastolic_bp}" : null;
    }
}
