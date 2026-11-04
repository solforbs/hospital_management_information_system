<?php

namespace App\Services\Notifications;

use App\Models\User;
use App\Notifications\ClinicalAlert;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;

/**
 * Sends tenant-scoped in-app alerts either to a specific user (e.g. the
 * ordering doctor) or to every active member of the tenant holding a
 * permission (e.g. everyone who can process lab orders).
 */
class DepartmentNotifier
{
    public function __construct(protected TenantContext $context) {}

    public function notifyUser(?User $user, string $title, string $body, ?string $url = null, string $level = 'info'): void
    {
        if (! $user || ! $this->context->hasTenant() || $user->id === Auth::id()) {
            return;
        }

        $user->notify(new ClinicalAlert($this->context->id(), $title, $body, $url, $level));
    }

    public function notifyPermission(string $permission, string $title, string $body, ?string $url = null, string $level = 'info'): void
    {
        $tenant = $this->context->tenant();

        if (! $tenant) {
            return;
        }

        $tenant->users()
            ->wherePivot('status', 'active')
            ->get()
            ->filter(fn (User $user) => $user->id !== Auth::id() && $user->can($permission))
            ->each(fn (User $user) => $user->notify(new ClinicalAlert($tenant->id, $title, $body, $url, $level)));
    }
}
