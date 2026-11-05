<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BloodRequest extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'patient_id', 'encounter_id', 'request_number', 'patient_abo', 'patient_rh', 'component', 'units_requested', 'urgency', 'indication', 'status', 'requested_by'];

    protected function casts(): array
    {
        return [];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function issues(): HasMany
    {
        return $this->hasMany(BloodIssue::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
