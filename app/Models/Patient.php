<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Patient extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'mrn',
        'first_name',
        'middle_name',
        'last_name',
        'date_of_birth',
        'date_of_birth_estimated',
        'sex',
        'national_id',
        'passport_number',
        'phone',
        'alternative_phone',
        'email',
        'address',
        'county',
        'next_of_kin_name',
        'next_of_kin_phone',
        'next_of_kin_relationship',
        'preferred_language',
        'category',
        'photo_path',
        'registered_at',
        'registered_by',
        'facility_id',
        'cr_number',
        'sha_number',
        'identification_type',
        'registry_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'registry_synced_at' => 'datetime',
            'date_of_birth' => 'date',
            'date_of_birth_estimated' => 'boolean',
            'registered_at' => 'datetime',
        ];
    }

    protected function fullName(): Attribute
    {
        return Attribute::get(fn () => trim("{$this->first_name} {$this->middle_name} {$this->last_name}"));
    }

    protected function age(): Attribute
    {
        return Attribute::get(fn () => $this->date_of_birth?->age);
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    public function encounters(): HasMany
    {
        return $this->hasMany(Encounter::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function allergies(): HasMany
    {
        return $this->hasMany(PatientAllergy::class);
    }

    public function insurances(): HasMany
    {
        return $this->hasMany(PatientInsurance::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function admissions(): HasMany
    {
        return $this->hasMany(Admission::class);
    }

    public function pregnancies(): HasMany
    {
        return $this->hasMany(Pregnancy::class);
    }

    public function vitalSigns(): HasMany
    {
        return $this->hasMany(VitalSign::class);
    }

    public function diagnoses(): HasMany
    {
        return $this->hasMany(Diagnosis::class);
    }

    public function activeEncounter(): ?Encounter
    {
        return $this->encounters()->where('status', 'open')->latest('started_at')->first();
    }
}
