<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Immunization extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'patient_id', 'vaccine', 'given_on', 'batch_number', 'site', 'given_elsewhere', 'notes', 'given_by'];

    protected function casts(): array
    {
        return ['given_on' => 'date', 'given_elsewhere' => 'boolean'];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function giver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'given_by');
    }
}
