<?php

namespace App\Providers;

use App\Contracts\ClaimGateway;
use App\Http\Middleware\EnforceSecurityPolicy;
use App\Http\Middleware\EnsureModuleEnabled;
use App\Http\Middleware\EnsureTenantIsActive;
use App\Models\Admission;
use App\Models\Bed;
use App\Models\BloodRequest;
use App\Models\CriticalCareObservation;
use App\Models\Encounter;
use App\Models\Invoice;
use App\Models\LabOrderItem;
use App\Models\Labour;
use App\Models\PartographEntry;
use App\Models\Payment;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\RadiologyOrder;
use App\Models\Referral;
use App\Models\Surgery;
use App\Models\VitalSign;
use App\Services\Billing\ManualClaimGateway;
use App\Services\Tenancy\TenantContext;
use App\Support\Live;
use App\Support\MailBrand;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
        $this->app->bind(ClaimGateway::class, ManualClaimGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // Re-check tenant status and facility module access on every
        // Livewire action, not only on the initial page load.
        Livewire::addPersistentMiddleware([
            EnsureTenantIsActive::class,
            EnsureModuleEnabled::class,
            EnforceSecurityPolicy::class,
        ]);

        $this->registerLiveChannels();

        // An invited person who uses "Forgot your password?" instead of the
        // invitation link has still set their own password.
        Event::listen(PasswordReset::class, function ($event) {
            if ($event->user->invited_at && ! $event->user->password_set_at) {
                $event->user->forceFill(['password_set_at' => now()])->save();
            }
        });

        $this->brandAccountEmails();

        // Break-glass support sessions are strictly read-only: only "view"
        // style abilities are allowed; everything else falls through to
        // the (empty) role permissions and is denied.
        Gate::before(function ($user, string $ability) {
            if ($user->isPlatformAdmin() && request()?->attributes->get('support_access')) {
                return str_ends_with($ability, '.view') || in_array($ability, ['reports.view', 'audit.view', 'encounters.view'], true) ? true : null;
            }

            return null;
        });
    }

    /** Model changes bump the tenant's live channels so open worklists refresh within seconds. */
    protected function registerLiveChannels(): void
    {
        $map = [
            Encounter::class => ['queue', 'triage', 'consultation', 'billing'],
            VitalSign::class => ['triage', 'consultation'],
            LabOrderItem::class => ['lab', 'consultation'],
            RadiologyOrder::class => ['radiology', 'consultation'],
            Prescription::class => ['pharmacy', 'consultation'],
            PrescriptionItem::class => ['pharmacy'],
            Invoice::class => ['billing'],
            Payment::class => ['billing'],
            Admission::class => ['wards', 'consultation'],
            Bed::class => ['wards'],
            Surgery::class => ['theatre'],
            Labour::class => ['labour'],
            PartographEntry::class => ['labour'],
            CriticalCareObservation::class => ['critical'],
            Referral::class => ['referrals'],
            BloodRequest::class => ['bloodbank'],
            DatabaseNotification::class => ['notifications'],
        ];

        foreach ($map as $model => $channels) {
            $touch = fn () => Live::touch(...$channels);
            $model::saved($touch);
            $model::deleted($touch);
        }
    }

    /** Password reset and email verification carry the person's hospital branding. */
    protected function brandAccountEmails(): void
    {
        ResetPassword::toMailUsing(function ($notifiable, string $token) {
            $url = url(route('password.reset', ['token' => $token, 'email' => $notifiable->getEmailForPasswordReset()], false));
            $minutes = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

            return MailBrand::forUser($notifiable)->apply(new MailMessage)
                ->subject(__('Reset your password'))
                ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
                ->line(__('We received a request to reset the password for your account (:email).', ['email' => $notifiable->email]))
                ->action(__('Reset password'), $url)
                ->line(__('This link expires in :count minutes.', ['count' => $minutes]))
                ->line(__('If you did not ask for this, you can ignore this email; your password will not change.'));
        });

        VerifyEmail::toMailUsing(function ($notifiable, string $url) {
            return MailBrand::forUser($notifiable)->apply(new MailMessage)
                ->subject(__('Confirm your email address'))
                ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
                ->line(__('Please confirm that :email is your email address.', ['email' => $notifiable->email]))
                ->action(__('Confirm email address'), $url)
                ->line(__('If you did not create an account, no further action is needed.'));
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
