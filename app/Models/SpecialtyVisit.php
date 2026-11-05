<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpecialtyVisit extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'patient_id', 'encounter_id', 'clinic', 'data', 'notes', 'next_visit_on', 'seen_by'];

    protected function casts(): array
    {
        return ['data' => 'array', 'next_visit_on' => 'date'];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function clinician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seen_by');
    }
}
