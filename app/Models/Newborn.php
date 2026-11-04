<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Newborn extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'labour_id', 'mother_patient_id', 'baby_patient_id', 'birth_order', 'sex', 'outcome',
        'birth_weight_g', 'apgar_1', 'apgar_5', 'apgar_10', 'resuscitated', 'vitamin_k', 'teo_given',
        'bcg_given', 'opv0_given', 'breastfed_within_1h', 'chlorhexidine_cord', 'birth_notification_number', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'resuscitated' => 'boolean',
            'vitamin_k' => 'boolean',
            'teo_given' => 'boolean',
            'bcg_given' => 'boolean',
            'opv0_given' => 'boolean',
            'breastfed_within_1h' => 'boolean',
            'chlorhexidine_cord' => 'boolean',
        ];
    }

    public function labour(): BelongsTo
    {
        return $this->belongsTo(Labour::class);
    }

    public function baby(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'baby_patient_id');
    }

    public function mother(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'mother_patient_id');
    }

    public function isLowBirthWeight(): bool
    {
        return $this->birth_weight_g !== null && $this->birth_weight_g < 2500;
    }
}
