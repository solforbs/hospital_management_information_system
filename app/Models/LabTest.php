<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class LabTest extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'category',
        'specimen_type',
        'unit',
        'reference_low',
        'reference_high',
        'reference_text',
        'result_type',
        'price',
        'turnaround_minutes',
        'active',
        'sha_intervention_code',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'price' => 'decimal:2',
            'reference_low' => 'float',
            'reference_high' => 'float',
        ];
    }

    public function referenceRange(): ?string
    {
        if ($this->reference_low !== null && $this->reference_high !== null) {
            return $this->reference_low.' - '.$this->reference_high.($this->unit ? ' '.$this->unit : '');
        }

        return $this->reference_text;
    }
}
