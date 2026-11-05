<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Time-boxed, audited platform-admin access to one tenant (break-glass). Not tenant-scoped. */
class SupportAccessGrant extends Model
{
    protected $fillable = ['tenant_id', 'user_id', 'reason', 'ticket_reference', 'expires_at', 'revoked_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return ! $this->revoked_at && $this->expires_at->isFuture();
    }

    public static function activeFor(int $userId, int $tenantId): ?self
    {
        return static::where('user_id', $userId)->where('tenant_id', $tenantId)->whereNull('revoked_at')->where('expires_at', '>', now())->latest('id')->first();
    }
}
