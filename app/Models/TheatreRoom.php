<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TheatreRoom extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'facility_id',
        'name',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'facility_id');
    }

    public function surgeries(): HasMany
    {
        return $this->hasMany(Surgery::class, 'theatre_room_id');
    }
}
