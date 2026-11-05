<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A device gateway installed on a hospital's network. It authenticates to
 * the LIS API with a token whose SHA-256 hash is stored here (the token is
 * shown once when created or rotated).
 */
class LabGateway extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'facility_id', 'name', 'token_hash', 'token_hint', 'last_seen_at', 'last_ip', 'version', 'active'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime', 'active' => 'boolean'];
    }

    /** Generates a new token, stores its hash and returns the plain token (show once). */
    public function issueToken(): string
    {
        $token = 'hgw_'.Str::random(40);
        $this->forceFill(['token_hash' => hash('sha256', $token), 'token_hint' => substr($token, -4)])->save();

        return $token;
    }

    public static function findByToken(?string $token): ?self
    {
        if (! $token || ! str_starts_with($token, 'hgw_')) {
            return null;
        }

        return static::withoutGlobalScope('tenant')->where('token_hash', hash('sha256', $token))->where('active', true)->first();
    }

    public function isOnline(): bool
    {
        return $this->last_seen_at !== null && $this->last_seen_at->gt(now()->subMinutes(3));
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function instruments(): HasMany
    {
        return $this->hasMany(LabInstrument::class);
    }
}
