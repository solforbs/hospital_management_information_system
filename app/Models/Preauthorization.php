<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Preauthorization extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'encounter_id', 'patient_id', 'source_type', 'source_id', 'preauth_number', 'gateway',
        'intervention_code', 'diagnosis_codes', 'justification', 'estimated_cost', 'status', 'approved_amount',
        'external_reference', 'external_status', 'response_note', 'gateway_response', 'requested_by',
        'submitted_at', 'responded_at',
    ];

    protected function casts(): array
    {
        return [
            'diagnosis_codes' => 'array',
            'gateway_response' => 'array',
            'estimated_cost' => 'decimal:2',
            'approved_amount' => 'decimal:2',
            'submitted_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isApproved(): bool
    {
        return in_array($this->status, ['approved', 'partially_approved'], true);
    }
}
