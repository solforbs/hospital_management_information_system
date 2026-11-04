<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-tenant OAuth2 client credentials for the DHA Health Information
 * Exchange (AfyaLink). Secrets are encrypted at rest and never serialised.
 */
class HieCredential extends Model
{
    use BelongsToTenant;

    public const SIMULATOR = 'simulator';

    public const UAT = 'uat';

    public const PRODUCTION = 'production';

    protected $fillable = [
        'tenant_id',
        'environment',
        'base_url',
        'token_url',
        'client_id',
        'client_secret',
        'enabled',
        'last_tested_at',
        'last_test_result',
        'webhook_secret',
    ];

    protected $hidden = ['client_id', 'client_secret', 'webhook_secret'];

    protected function casts(): array
    {
        return [
            'webhook_secret' => 'encrypted',
            'client_id' => 'encrypted',
            'client_secret' => 'encrypted',
            'enabled' => 'boolean',
            'last_tested_at' => 'datetime',
        ];
    }

    public function isSimulator(): bool
    {
        return $this->environment === self::SIMULATOR;
    }

    public function resolvedBaseUrl(): string
    {
        return rtrim($this->base_url ?: (string) config("hms.hie.environments.{$this->environment}.base_url"), '/');
    }

    public function resolvedTokenUrl(): string
    {
        return $this->token_url ?: (string) config("hms.hie.environments.{$this->environment}.token_url");
    }
}
