<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Staff\StaffInvitationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

/**
 * "Set your password" page opened from a staff invitation email. The link is
 * signed and time-limited, and only works while the invited account still has
 * the password it was created with.
 */
class InvitationController extends Controller
{
    public function show(Request $request, User $user)
    {
        if ($problem = $this->problem($request, $user)) {
            return response()->view('pages::auth.accept-invitation', ['user' => $user, 'problem' => $problem], 410);
        }

        return view('pages::auth.accept-invitation', ['user' => $user, 'problem' => null]);
    }

    public function store(Request $request, User $user, AuditLogger $audit)
    {
        if ($problem = $this->problem($request, $user)) {
            return response()->view('pages::auth.accept-invitation', ['user' => $user, 'problem' => $problem], 410);
        }

        $request->validate(['password' => ['required', 'string', Password::default(), 'confirmed']]);

        $user->forceFill([
            'password' => $request->string('password')->value(),
            'password_set_at' => now(),
            'email_verified_at' => $user->email_verified_at ?? now(), // the link proves they own the address
        ])->save();

        Auth::login($user);
        $request->session()->regenerate();
        $audit->log('auth.invitation_accepted', $user);

        return redirect()->route('landing');
    }

    /** Why the link can't be used, or null when it's good. */
    protected function problem(Request $request, User $user): ?string
    {
        if ($user->password_set_at !== null) {
            return __('This invitation has already been used. Log in with the password you chose, or use "Forgot your password?".');
        }

        if (! $request->hasValidSignature() || ! hash_equals(StaffInvitationService::fingerprint($user), (string) $request->query('h'))) {
            return __('This invitation link has expired or is no longer valid. Ask your hospital administrator to resend the invitation, or use "Forgot your password?" on the login page.');
        }

        return null;
    }
}
