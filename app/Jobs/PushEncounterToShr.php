<?php

namespace App\Jobs;

use App\Models\Encounter;
use App\Models\Tenant;
use App\Services\Hie\HieClient;
use App\Services\Hie\SharedHealthRecordService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Queued push of a completed visit to the Shared Health Record. Runs in
 * the visit's tenant context (queue workers have no logged-in user).
 */
class PushEncounterToShr implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [60, 300, 900, 3600];

    public function __construct(public int $tenantId, public int $encounterId) {}

    public function handle(TenantContext $context, HieClient $hie, SharedHealthRecordService $shr): void
    {
        $tenant = Tenant::find($this->tenantId);
        if (! $tenant) {
            return;
        }

        $context->set($tenant);

        $encounter = Encounter::find($this->encounterId);
        if (! $encounter || ! $hie->isEnabled()) {
            return;
        }

        if (! $shr->push($encounter)) {
            $this->release($this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)]);
        }
    }
}
