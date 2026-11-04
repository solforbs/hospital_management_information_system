<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PncVisit extends Model
{
    use BelongsToTenant;

    public const TIMINGS = ['48h' => 'Within 48 hours', '1-2wk' => '1–2 weeks', '4-6wk' => '4–6 weeks', '6mo' => '4–6 months'];

    protected $fillable = [
        'tenant_id', 'pregnancy_id', 'newborn_id', 'visit_date', 'timing', 'systolic', 'diastolic', 'temperature',
        'uterus', 'lochia', 'breasts', 'perineum_wound', 'baby_weight_kg', 'baby_temperature', 'cord', 'feeding',
        'family_planning_counselled', 'family_planning_method', 'danger_signs', 'notes', 'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'visit_date' => 'date',
            'temperature' => 'decimal:1',
            'baby_temperature' => 'decimal:1',
            'baby_weight_kg' => 'decimal:2',
            'family_planning_counselled' => 'boolean',
            'danger_signs' => 'array',
        ];
    }

    public function pregnancy(): BelongsTo
    {
        return $this->belongsTo(Pregnancy::class);
    }

    public function newborn(): BelongsTo
    {
        return $this->belongsTo(Newborn::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
