<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Scopes a model to the currently resolved tenant and stamps new records
 * with the active tenant id. Every tenant-owned model must use this trait
 * so tenant isolation cannot be bypassed by forgetting a where() clause.
 */
trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            $context = app(TenantContext::class);

            if ($context->hasTenant()) {
                $builder->where($builder->getModel()->getTable().'.tenant_id', $context->id());
            }
        });

        static::creating(function ($model) {
            $context = app(TenantContext::class);

            if (! $model->tenant_id && $context->hasTenant()) {
                $model->tenant_id = $context->id();
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function scopeWithoutTenantScope(Builder $query): Builder
    {
        return $query->withoutGlobalScope('tenant');
    }
}
