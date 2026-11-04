<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Inter-facility referral (KEPH referral system). Outgoing referrals send a
 * patient to another facility (usually a higher level); incoming referrals
 * are patients received from elsewhere. A back-referral returns the patient
 * to the referring facility with feedback.
 */
class Referral extends Model
{
    use BelongsToTenant;

    public const STATUSES = ['draft', 'sent', 'accepted', 'arrived', 'completed', 'rejected', 'cancelled'];

    protected $fillable = [
        'tenant_id', 'facility_id', 'patient_id', 'encounter_id', 'received_encounter_id',
        'referral_number', 'direction', 'kind',
        'other_facility_name', 'other_facility_code', 'other_facility_level',
        'department', 'urgency', 'reason', 'clinical_summary', 'investigations', 'treatment_given',
        'transport', 'referring_clinician', 'status', 'feedback', 'status_reason',
        'created_by', 'updated_by', 'sent_at', 'arrived_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'other_facility_level' => 'integer',
            'sent_at' => 'datetime',
            'arrived_at' => 'datetime',
            'completed_at' => 'datetime',
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

    public function receivedEncounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class, 'received_encounter_id');
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'facility_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isOutgoing(): bool
    {
        return $this->direction === 'outgoing';
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            'draft' => 'zinc', 'sent' => 'sky', 'accepted' => 'indigo', 'arrived' => 'amber',
            'completed' => 'lime', 'rejected', 'cancelled' => 'red', default => 'zinc',
        };
    }
}
