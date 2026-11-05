<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HeiEnrolment extends Model
{
    use BelongsToTenant;

    public const TESTS = [
        'pcr_6wk' => ['DNA PCR at 6 weeks', 42],
        'pcr_6mo' => ['DNA PCR at 6 months', 182],
        'pcr_12mo' => ['DNA PCR at 12 months', 365],
        'antibody_18mo' => ['Antibody test at 18 months', 548],
        'confirmatory' => ['Confirmatory test', null],
    ];

    public const OUTCOMES = [
        'negative_discharged' => 'Negative — discharged from HEI',
        'positive_linked' => 'Positive — linked to HIV care',
        'lost' => 'Lost to follow-up',
        'died' => 'Died',
        'transferred_out' => 'Transferred out',
    ];

    protected $fillable = ['tenant_id', 'patient_id', 'mother_patient_id', 'hei_number', 'enrolled_on', 'infant_prophylaxis', 'ctx_started_on', 'feeding', 'status', 'closed_on'];

    protected function casts(): array
    {
        return ['enrolled_on' => 'date', 'ctx_started_on' => 'date', 'closed_on' => 'date'];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function mother(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'mother_patient_id');
    }

    public function tests(): HasMany
    {
        return $this->hasMany(HeiTest::class)->orderBy('sample_date');
    }
}
