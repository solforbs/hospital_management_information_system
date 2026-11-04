<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pregnancy extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'facility_id', 'patient_id', 'anc_number', 'lmp', 'edd', 'edd_by_ultrasound',
        'gravida', 'parity', 'abortions', 'living_children', 'previous_caesareans',
        'blood_group', 'rhesus', 'hiv_status', 'syphilis_status', 'hepatitis_b_status', 'height_cm',
        'risk_factors', 'risk_level', 'birth_plan_facility', 'status', 'outcome', 'created_by', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'lmp' => 'date',
            'edd' => 'date',
            'edd_by_ultrasound' => 'boolean',
            'risk_factors' => 'array',
            'height_cm' => 'decimal:1',
            'closed_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function ancVisits(): HasMany
    {
        return $this->hasMany(AncVisit::class)->orderBy('visit_number');
    }

    public function labours(): HasMany
    {
        return $this->hasMany(Labour::class);
    }

    public function pncVisits(): HasMany
    {
        return $this->hasMany(PncVisit::class)->orderBy('visit_date');
    }

    /** Gestational age in completed weeks on a date (from LMP, or back-calculated from EDD). */
    public function gestationWeeksOn(?CarbonInterface $date = null): ?int
    {
        $date ??= now();
        $start = $this->lmp ?? $this->edd?->copy()->subDays(280);

        if (! $start || $date->lt($start)) {
            return null;
        }

        return (int) floor($start->diffInDays($date) / 7);
    }

    /** "G3 P1+1" obstetric summary. */
    public function obstetricFormula(): string
    {
        return 'G'.$this->gravida.' P'.$this->parity.'+'.$this->abortions;
    }

    public function isHighRisk(): bool
    {
        return $this->risk_level === 'high';
    }
}
