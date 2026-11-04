<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AncVisit extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'pregnancy_id', 'encounter_id', 'visit_number', 'visit_date', 'gestation_weeks',
        'weight_kg', 'systolic', 'diastolic', 'fundal_height_cm', 'fetal_heart_rate', 'presentation',
        'fetal_movement', 'urine_protein', 'hb', 'iptp_given', 'td_dose', 'ifas_given', 'deworming',
        'llin_given', 'danger_signs', 'notes', 'next_visit_date', 'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'visit_date' => 'date',
            'next_visit_date' => 'date',
            'weight_kg' => 'decimal:1',
            'hb' => 'decimal:1',
            'fetal_movement' => 'boolean',
            'iptp_given' => 'boolean',
            'ifas_given' => 'boolean',
            'deworming' => 'boolean',
            'llin_given' => 'boolean',
            'danger_signs' => 'array',
        ];
    }

    public function pregnancy(): BelongsTo
    {
        return $this->belongsTo(Pregnancy::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
