<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Admission extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'patient_id',
        'encounter_id',
        'ward_id',
        'bed_id',
        'admitting_doctor_id',
        'admission_number',
        'type',
        'status',
        'reason',
        'requested_by',
        'requested_at',
        'admitted_at',
        'admitted_by',
        'discharged_at',
        'discharged_by',
        'discharge_type',
        'discharge_summary',
        'follow_up_date',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'admitted_at' => 'datetime',
            'discharged_at' => 'datetime',
            'follow_up_date' => 'date',
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

    public function ward(): BelongsTo
    {
        return $this->belongsTo(Ward::class, 'ward_id');
    }

    public function bed(): BelongsTo
    {
        return $this->belongsTo(Bed::class, 'bed_id');
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admitting_doctor_id');
    }

    public function bedAssignments(): HasMany
    {
        return $this->hasMany(BedAssignment::class, 'admission_id');
    }

    public function nursingNotes(): HasMany
    {
        return $this->hasMany(NursingNote::class, 'admission_id');
    }

    public function criticalCareObservations(): HasMany
    {
        return $this->hasMany(CriticalCareObservation::class, 'admission_id');
    }

    public function medicationAdministrations(): HasMany
    {
        return $this->hasMany(MedicationAdministration::class, 'admission_id');
    }

    public function lengthOfStayDays(): int
    {
        if (! $this->admitted_at) {
            return 0;
        }

        return max(1, (int) ceil($this->admitted_at->diffInHours($this->discharged_at ?? now()) / 24));
    }
}
