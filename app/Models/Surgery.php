<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Surgery extends Model
{
    use BelongsToTenant;

    protected $table = 'surgeries';

    protected $fillable = [
        'tenant_id',
        'patient_id',
        'encounter_id',
        'admission_id',
        'theatre_room_id',
        'surgeon_id',
        'anesthetist_id',
        'surgery_number',
        'procedure_name',
        'urgency',
        'anesthesia_type',
        'scheduled_at',
        'estimated_minutes',
        'status',
        'checklist',
        'consent_obtained',
        'started_at',
        'ended_at',
        'operative_notes',
        'complications',
        'fee',
        'booked_by',
        'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'checklist' => 'array',
            'consent_obtained' => 'boolean',
            'fee' => 'decimal:2',
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

    public function room(): BelongsTo
    {
        return $this->belongsTo(TheatreRoom::class, 'theatre_room_id');
    }

    public function surgeon(): BelongsTo
    {
        return $this->belongsTo(User::class, 'surgeon_id');
    }

    public function anesthetist(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anesthetist_id');
    }
}
