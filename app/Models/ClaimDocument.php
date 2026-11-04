<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClaimDocument extends Model
{
    use BelongsToTenant;

    public const TYPES = [
        'discharge_summary' => 'Discharge summary',
        'lab_report' => 'Laboratory report',
        'radiology_report' => 'Radiology report',
        'invoice' => 'Itemised invoice',
        'consent' => 'Consent form',
        'referral' => 'Referral letter',
        'theatre_notes' => 'Theatre / operation notes',
        'other' => 'Other',
    ];

    protected $fillable = [
        'tenant_id', 'insurance_claim_id', 'document_type', 'path', 'original_name', 'mime', 'size',
        'external_reference', 'sent_at', 'uploaded_by',
    ];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(InsuranceClaim::class, 'insurance_claim_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
