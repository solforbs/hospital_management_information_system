<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClinicalNote extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'patient_id',
        'encounter_id',
        'author_id',
        'type',
        'presenting_complaint',
        'history',
        'examination',
        'assessment',
        'plan',
        'status',
        'signed_at',
        'amends_note_id',
        'amendment_reason',
    ];

    protected function casts(): array
    {
        return [
            'signed_at' => 'datetime',
        ];
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class, 'encounter_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function amends(): BelongsTo
    {
        return $this->belongsTo(ClinicalNote::class, 'amends_note_id');
    }

    public function isSigned(): bool
    {
        return $this->status !== 'draft';
    }
}
