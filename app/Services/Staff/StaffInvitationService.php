<?php

namespace App\Services\Staff;

use App\Models\Tenant;
use App\Models\User;
use App\Notifications\StaffInvitation;
use App\Support\MailBrand;
use Illuminate\Support\Facades\URL;

/**
 * Emails staff when a hospital admin adds them.
 *
 * People without a password of their own get a signed "set your password"
 * link. The link carries a fingerprint of the current password hash, so it
 * stops working the moment a password is set (by the link or by "forgot
 * password"), and it expires after config('hms.invitation_hours').
 * People who already have an account just get an "access granted" email.
 */
class StaffInvitationService
{
    public function needsPassword(User $user): bool
    {
        return $user->invited_at !== null && $user->password_set_at === null;
    }

    public function invite(User $user, Tenant $tenant, string $role, ?string $facility, ?User $invitedBy): void
    {
        if ($user->wasRecentlyCreated || $this->needsPassword($user)) {
            $user->forceFill(['invited_at' => now()])->save();
            $user->notify(new StaffInvitation($tenant->name, $role, $facility, $invitedBy?->name, $this->link($user), $this->hours(), MailBrand::forTenant($tenant)->toArray()));

            return;
        }

        $user->notify(new StaffInvitation($tenant->name, $role, $facility, $invitedBy?->name, null, $this->hours(), MailBrand::forTenant($tenant)->toArray()));
    }

    public function link(User $user): string
    {
        return URL::temporarySignedRoute('invitation.show', now()->addHours($this->hours()), [
            'user' => $user->id,
            'h' => self::fingerprint($user),
        ]);
    }

    public static function fingerprint(User $user): string
    {
        return substr(hash_hmac('sha256', $user->getAuthPassword().'|'.$user->email, (string) config('app.key')), 0, 24);
    }

    protected function hours(): int
    {
        return (int) config('hms.invitation_hours', 72);
    }
}
