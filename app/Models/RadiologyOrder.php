<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RadiologyOrder extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'patient_id',
        'encounter_id',
        'radiology_exam_id',
        'ordered_by',
        'order_number',
        'priority',
        'status',
        'clinical_indication',
        'scheduled_at',
        'performed_at',
        'performed_by',
        'findings',
        'impression',
        'reported_at',
        'reported_by',
        'verified_at',
        'verified_by',
        'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'performed_at' => 'datetime',
            'reported_at' => 'datetime',
            'verified_at' => 'datetime',
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

    public function exam(): BelongsTo
    {
        return $this->belongsTo(RadiologyExam::class, 'radiology_exam_id');
    }

    public function orderedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ordered_by');
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }
}
