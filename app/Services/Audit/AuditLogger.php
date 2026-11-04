<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Append-only audit trail for clinically or financially significant
 * actions (signing notes, releasing results, dispensing, payments,
 * discharges, permission changes, tenant suspension...).
 */
class AuditLogger
{
    public function __construct(protected TenantContext $context) {}

    public function log(string $action, ?Model $subject = null, array $properties = [], ?int $tenantId = null): AuditLog
    {
        $request = app()->runningInConsole() ? null : request();

        return AuditLog::withoutGlobalScope('tenant')->create([
            'tenant_id' => $tenantId ?? $subject?->getAttribute('tenant_id') ?? $this->context->id(),
            'user_id' => Auth::id(),
            'action' => $action,
            'auditable_type' => $subject?->getMorphClass(),
            'auditable_id' => $subject?->getKey(),
            'properties' => $properties ?: null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? substr((string) $request->userAgent(), 0, 250) : null,
            'created_at' => now(),
        ]);
    }
}
