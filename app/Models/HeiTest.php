<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HeiTest extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'hei_enrolment_id', 'test', 'sample_date', 'result', 'result_date', 'recorded_by'];

    protected function casts(): array
    {
        return ['sample_date' => 'date', 'result_date' => 'date'];
    }

    public function enrolment(): BelongsTo
    {
        return $this->belongsTo(HeiEnrolment::class, 'hei_enrolment_id');
    }
}
