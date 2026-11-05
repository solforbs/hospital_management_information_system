<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BloodIssue extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'blood_request_id', 'blood_unit_id', 'crossmatch', 'issued_at', 'issued_by', 'reaction', 'reaction_notes'];

    protected function casts(): array
    {
        return ['issued_at' => 'datetime'];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(BloodUnit::class, 'blood_unit_id');
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(BloodRequest::class, 'blood_request_id');
    }
}
