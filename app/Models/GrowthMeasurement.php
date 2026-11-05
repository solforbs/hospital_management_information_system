<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class GrowthMeasurement extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'patient_id', 'measured_on', 'weight_kg', 'height_cm', 'muac_cm', 'head_circumference_cm', 'nutrition_status', 'vitamin_a', 'deworming', 'notes', 'recorded_by'];

    protected function casts(): array
    {
        return ['measured_on' => 'date', 'vitamin_a' => 'boolean', 'deworming' => 'boolean'];
    }
}
