<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * In-app interdepartmental notification (result released, patient sent to
 * pharmacy, admission requested...). Stored with the tenant id so the
 * notification centre only ever shows the active tenant's alerts.
 */
class ClinicalAlert extends Notification
{
    use Queueable;

    public function __construct(
        public int $tenantId,
        public string $title,
        public string $body,
        public ?string $url = null,
        public string $level = 'info',
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
            'level' => $this->level,
        ];
    }
}
