<?php

namespace App\Services\Tenancy;

use App\Models\Facility;
use App\Models\Tenant;

/**
 * The single source of truth for "which tenant/facility is active" during a
 * request. Never trust a tenant id from the browser: this class only ever
 * sets its state from verified data (an authenticated user's memberships,
 * or a verified tenant domain), and every tenant-owned model reads from
 * here rather than from request input.
 */
class TenantContext
{
    protected ?Tenant $tenant = null;

    protected ?Facility $facility = null;

    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;
        $this->facility = null;
    }

    public function setFacility(?Facility $facility): void
    {
        $this->facility = $facility;
    }

    public function hasTenant(): bool
    {
        return $this->tenant !== null;
    }

    public function tenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->tenant?->id;
    }

    public function facility(): ?Facility
    {
        return $this->facility;
    }

    public function facilityId(): ?int
    {
        return $this->facility?->id;
    }

    public function clear(): void
    {
        $this->tenant = null;
        $this->facility = null;
    }
}
