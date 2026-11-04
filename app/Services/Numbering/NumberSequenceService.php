<?php

namespace App\Services\Numbering;

use App\Models\NumberSequence;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Tenant-scoped, concurrency-safe document numbering (encounters, orders,
 * invoices, receipts, queue tickets). Each (tenant, key, period) row is
 * locked for update while incremented, so two simultaneous requests can
 * never receive the same number.
 */
class NumberSequenceService
{
    public function __construct(protected TenantContext $context) {}

    public function nextInteger(string $key, ?string $period = null, ?int $tenantId = null): int
    {
        $tenantId ??= $this->context->id();
        $period ??= now()->format('y');

        if (! $tenantId) {
            throw new RuntimeException('Cannot generate a number without an active tenant.');
        }

        return DB::transaction(function () use ($key, $period, $tenantId) {
            $query = fn () => NumberSequence::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->where('key', $key)
                ->where('period', $period)
                ->lockForUpdate()
                ->first();

            $sequence = $query();

            if (! $sequence) {
                try {
                    $sequence = NumberSequence::withoutGlobalScope('tenant')->create([
                        'tenant_id' => $tenantId,
                        'key' => $key,
                        'period' => $period,
                        'last_number' => 0,
                    ]);
                } catch (QueryException) {
                    $sequence = $query();
                }
            }

            $sequence->increment('last_number');

            return (int) $sequence->last_number;
        });
    }

    /**
     * e.g. next('ENC') => "ENC-26-000042".
     */
    public function next(string $prefix, ?int $tenantId = null, int $pad = 6): string
    {
        $period = now()->format('y');
        $number = $this->nextInteger($prefix, $period, $tenantId);

        return sprintf('%s-%s-%0'.$pad.'d', $prefix, $period, $number);
    }

    /**
     * Daily queue ticket number for a department/queue key.
     */
    public function nextQueueNumber(string $queue = 'OPD', ?int $tenantId = null): int
    {
        return $this->nextInteger('Q-'.$queue, now()->format('ymd'), $tenantId);
    }
}
