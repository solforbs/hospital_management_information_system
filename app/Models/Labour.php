<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Labour extends Model
{
    use BelongsToTenant;

    public const MODES = ['svd' => 'Spontaneous vaginal delivery', 'vacuum' => 'Vacuum-assisted', 'forceps' => 'Forceps-assisted', 'breech' => 'Assisted breech', 'caesarean' => 'Caesarean section'];

    protected $fillable = [
        'tenant_id', 'pregnancy_id', 'encounter_id', 'labour_number', 'admitted_at', 'onset', 'membranes',
        'membranes_ruptured_at', 'gestation_weeks', 'status', 'delivered_at', 'delivery_mode', 'amtsl',
        'uterotonic_given', 'placenta_complete', 'blood_loss_ml', 'perineum', 'mother_condition',
        'complications', 'notes', 'delivered_by', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'admitted_at' => 'datetime',
            'membranes_ruptured_at' => 'datetime',
            'delivered_at' => 'datetime',
            'amtsl' => 'boolean',
            'uterotonic_given' => 'boolean',
            'placenta_complete' => 'boolean',
        ];
    }

    public function pregnancy(): BelongsTo
    {
        return $this->belongsTo(Pregnancy::class);
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(PartographEntry::class)->orderBy('recorded_at');
    }

    public function newborns(): HasMany
    {
        return $this->hasMany(Newborn::class)->orderBy('birth_order');
    }

    public function deliveredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delivered_by');
    }
}
