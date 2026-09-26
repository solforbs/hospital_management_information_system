<?php

namespace App\Services\Tenancy;

use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Contracts\PermissionsTeamResolver;

/**
 * Ties Spatie Permission's "teams" feature to our tenant context, so a
 * user's roles/permissions are always scoped to the currently active
 * tenant rather than a globally shared role set.
 */
class TenantTeamResolver implements PermissionsTeamResolver
{
    protected int|string|null $override = null;

    public function setPermissionsTeamId(Model|int|string|null $id): void
    {
        $this->override = $id instanceof Model ? $id->getKey() : $id;
    }

    public function getPermissionsTeamId(): int|string|null
    {
        if ($this->override !== null) {
            return $this->override;
        }

        return app(TenantContext::class)->id();
    }
}
