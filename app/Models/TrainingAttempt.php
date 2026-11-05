<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One completed quiz in the training centre. Not tenant-scoped by a global
 * scope: platform admins train without a tenant, and the records page filters
 * by tenant explicitly.
 */
class TrainingAttempt extends Model
{
    protected $fillable = ['user_id', 'tenant_id', 'module', 'score', 'total', 'percent', 'passed', 'answers'];

    protected function casts(): array
    {
        return ['passed' => 'boolean', 'answers' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
