<?php

namespace App\Notifications;

use App\Support\MailBrand;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Welcome email for staff added to a hospital. With a $setPasswordUrl the
 * person chooses their password; without one they already have an account
 * and are simply told they can now log in to this hospital.
 */
class StaffInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $hospital,
        public string $role,
        public ?string $facility,
        public ?string $invitedBy,
        public ?string $setPasswordUrl,
        public int $validHours,
        public array $brand = [],
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $where = $this->facility ? "{$this->hospital} ({$this->facility})" : $this->hospital;

        $mail = MailBrand::fromArray($this->brand)->apply(new MailMessage)
            ->subject("You've been added to {$this->hospital}")
            ->greeting("Hello {$notifiable->name},")
            ->line(($this->invitedBy ? "{$this->invitedBy} has added you" : 'You have been added')." to {$where} as **{$this->role}**.");

        if ($this->setPasswordUrl) {
            return $mail
                ->line('To start, choose your own password. Your login email is **'.$notifiable->email.'**.')
                ->action('Set your password', $this->setPasswordUrl)
                ->line("This link works once and expires in {$this->validHours} hours. If it expires, ask your administrator to resend the invitation, or use \"Forgot your password?\" on the login page.")
                ->line('If you were not expecting this email, you can ignore it.');
        }

        return $mail
            ->line('Log in with your existing account ('.$notifiable->email.') to start working.')
            ->action('Log in', route('login'))
            ->line('If you work at more than one hospital, switch between them on your dashboard after logging in.');
    }
}
