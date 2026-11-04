<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One timed entry on an ICU/HDU/NICU flow chart: haemodynamics,
 * respiratory support, neurology and fluid balance.
 */
class CriticalCareObservation extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'admission_id', 'recorded_by', 'recorded_at',
        'heart_rate', 'systolic', 'diastolic', 'map', 'resp_rate', 'spo2', 'temperature', 'gcs',
        'fio2', 'ventilation', 'peep', 'blood_glucose', 'intake_ml', 'output_ml', 'urine_ml',
        'sedation_score', 'pain_score', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
            'temperature' => 'decimal:1',
            'blood_glucose' => 'decimal:1',
        ];
    }

    public function admission(): BelongsTo
    {
        return $this->belongsTo(Admission::class, 'admission_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** Values outside safe adult ranges, for highlighting on the chart. */
    public function alerts(): array
    {
        $a = [];
        if ($this->heart_rate !== null && ($this->heart_rate < 50 || $this->heart_rate > 120)) {
            $a[] = 'heart_rate';
        }
        if ($this->map !== null && $this->map < 65) {
            $a[] = 'map';
        }
        if ($this->systolic !== null && $this->systolic < 90) {
            $a[] = 'systolic';
        }
        if ($this->spo2 !== null && $this->spo2 < 92) {
            $a[] = 'spo2';
        }
        if ($this->resp_rate !== null && ($this->resp_rate < 10 || $this->resp_rate > 25)) {
            $a[] = 'resp_rate';
        }
        if ($this->gcs !== null && $this->gcs <= 8) {
            $a[] = 'gcs';
        }
        if ($this->temperature !== null && ((float) $this->temperature >= 38.5 || (float) $this->temperature < 35.5)) {
            $a[] = 'temperature';
        }

        return $a;
    }
}
