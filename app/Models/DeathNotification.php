<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeathNotification extends Model
{
    use BelongsToTenant;

    public const PLACES = ['inpatient' => 'Inpatient ward', 'casualty' => 'Casualty / emergency', 'theatre' => 'Theatre', 'brought_in_dead' => 'Brought in dead'];

    public const MANNERS = ['natural' => 'Natural', 'accident' => 'Accident', 'suicide' => 'Suicide', 'homicide' => 'Homicide', 'pending' => 'Pending investigation', 'unknown' => 'Could not be determined'];

    protected $fillable = [
        'tenant_id', 'facility_id', 'patient_id', 'encounter_id', 'admission_id', 'notification_number', 'died_at', 'place',
        'immediate_cause', 'immediate_icd', 'underlying_cause', 'underlying_icd', 'other_conditions', 'manner', 'is_maternal',
        'autopsy_requested', 'certified_by', 'body_status', 'storage_unit', 'mortuary_admitted_at', 'released_to',
        'released_to_id_number', 'released_to_relationship', 'burial_permit_number', 'released_at', 'released_by',
    ];

    protected function casts(): array
    {
        return [
            'died_at' => 'datetime',
            'mortuary_admitted_at' => 'datetime',
            'released_at' => 'datetime',
            'is_maternal' => 'boolean',
            'autopsy_requested' => 'boolean',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function certifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'certified_by');
    }

    public function releaser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    public function admission(): BelongsTo
    {
        return $this->belongsTo(Admission::class);
    }

    /** Days the body has been in the mortuary (billed per day). */
    public function storageDays(): int
    {
        if (! $this->mortuary_admitted_at) {
            return 0;
        }

        return max(1, (int) ceil($this->mortuary_admitted_at->diffInHours($this->released_at ?? now()) / 24));
    }
}
