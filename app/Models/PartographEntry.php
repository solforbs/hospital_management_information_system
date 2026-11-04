<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartographEntry extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'labour_id', 'recorded_at', 'fetal_heart_rate', 'liquor', 'moulding', 'cervix_cm',
        'descent_fifths', 'contractions_per_10', 'contraction_strength', 'oxytocin_units', 'drugs',
        'pulse', 'systolic', 'diastolic', 'temperature', 'urine_protein', 'urine_volume_ml', 'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
            'cervix_cm' => 'decimal:1',
            'oxytocin_units' => 'decimal:1',
            'temperature' => 'decimal:1',
        ];
    }

    public function labour(): BelongsTo
    {
        return $this->belongsTo(Labour::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
