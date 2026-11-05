<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DialysisSession extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'patient_id', 'encounter_id', 'session_number', 'modality', 'machine', 'access', 'started_at', 'ended_at', 'pre_weight_kg', 'post_weight_kg', 'pre_systolic', 'pre_diastolic', 'post_systolic', 'post_diastolic', 'uf_goal_ml', 'uf_achieved_ml', 'heparin', 'complications', 'status', 'nurse_id'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime', 'pre_weight_kg' => 'decimal:2', 'post_weight_kg' => 'decimal:2'];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function nurse(): BelongsTo
    {
        return $this->belongsTo(User::class, 'nurse_id');
    }

    /** Interdialytic weight change achieved this session (kg). */
    public function weightLossKg(): ?float
    {
        return $this->pre_weight_kg !== null && $this->post_weight_kg !== null ? round((float) $this->pre_weight_kg - (float) $this->post_weight_kg, 2) : null;
    }
}
