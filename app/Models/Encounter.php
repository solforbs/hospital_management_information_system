<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Encounter extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'facility_id',
        'patient_id',
        'appointment_id',
        'department_id',
        'attending_doctor_id',
        'encounter_number',
        'type',
        'stage',
        'status',
        'priority',
        'triage_category',
        'queue_number',
        'payer_type',
        'chief_complaint',
        'created_by',
        'started_at',
        'completed_at',
        'sha_service_type',
        'sha_interventions',
        'sha_eligibility',
        'sha_checked_at',
        'sha_consent_token',
        'sha_consent_method',
        'sha_consented_at',
        'sha_auth_guid',
        'shr_status',
        'shr_sent_at',
        'shr_error',
    ];

    protected $hidden = ['sha_consent_token'];

    protected function casts(): array
    {
        return [
            'sha_interventions' => 'array',
            'sha_eligibility' => 'array',
            'sha_checked_at' => 'datetime',
            'sha_consented_at' => 'datetime',
            'sha_consent_token' => 'encrypted',
            'shr_sent_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'appointment_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attending_doctor_id');
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'facility_id');
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(EncounterTransition::class, 'encounter_id');
    }

    public function vitalSigns(): HasMany
    {
        return $this->hasMany(VitalSign::class, 'encounter_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(ClinicalNote::class, 'encounter_id');
    }

    public function diagnoses(): HasMany
    {
        return $this->hasMany(Diagnosis::class, 'encounter_id');
    }

    public function labOrders(): HasMany
    {
        return $this->hasMany(LabOrder::class, 'encounter_id');
    }

    public function radiologyOrders(): HasMany
    {
        return $this->hasMany(RadiologyOrder::class, 'encounter_id');
    }

    public function prescriptions(): HasMany
    {
        return $this->hasMany(Prescription::class, 'encounter_id');
    }

    public function admissions(): HasMany
    {
        return $this->hasMany(Admission::class, 'encounter_id');
    }

    public function surgeries(): HasMany
    {
        return $this->hasMany(Surgery::class, 'encounter_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'encounter_id');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function latestVitals(): HasOne
    {
        return $this->hasOne(VitalSign::class)->latestOfMany('recorded_at');
    }
}
