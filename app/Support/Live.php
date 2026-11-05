<?php

namespace App\Support;

use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;

/**
 * Lightweight live updates. Each tenant has versioned "channels" (queue,
 * lab, pharmacy, wards...). Model changes bump a channel's version; open
 * pages poll /live (a few bytes, every few seconds) and only re-render the
 * Livewire component when one of their channels actually changed.
 *
 * Works on any PHP host (no websocket server needed) and keeps the same
 * page-level contract a websocket broadcaster would use: <div data-live="queue,lab">.
 */
class Live
{
    public const CHANNELS = ['queue', 'triage', 'consultation', 'lab', 'radiology', 'pharmacy', 'billing', 'wards', 'theatre', 'labour', 'critical', 'referrals', 'bloodbank', 'notifications'];

    public static function touch(string ...$channels): void
    {
        $tenant = app(TenantContext::class)->id();
        if (! $tenant) {
            return;
        }

        foreach ($channels as $channel) {
            Cache::forever(self::key($tenant, $channel), (int) (microtime(true) * 1000));
        }
    }

    /** @return array<string, int> */
    public static function versions(int $tenantId, array $channels): array
    {
        $out = [];
        foreach (array_intersect($channels, self::CHANNELS) as $channel) {
            $out[$channel] = (int) Cache::get(self::key($tenantId, $channel), 0);
        }

        return $out;
    }

    protected static function key(int $tenant, string $channel): string
    {
        return "live:{$tenant}:{$channel}";
    }
}
