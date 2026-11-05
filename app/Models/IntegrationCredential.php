<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Per-tenant credentials for SMS / M-Pesa; config is encrypted JSON. */
class IntegrationCredential extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'provider', 'environment', 'config', 'enabled'];

    protected $hidden = ['config'];

    protected function casts(): array
    {
        return ['config' => 'encrypted:array', 'enabled' => 'boolean'];
    }

    public static function for(string $provider): ?self
    {
        return static::where('provider', $provider)->where('enabled', true)->first();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->config, $key, $default);
    }
}
