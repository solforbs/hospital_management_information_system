<?php

namespace App\Support;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Branding for outgoing email (and error pages): the institution's logo,
 * name, colours and contact details, falling back to the HMIS identity when
 * there is no institution or it has not uploaded a logo yet.
 *
 * Emails are often sent from the queue worker, where no tenant is active, so
 * the brand is resolved when the message is built and travels with it as a
 * plain array.
 */
final class MailBrand
{
    public const SYSTEM_NAME = 'HMIS';

    public const SYSTEM_TAGLINE = 'Hospital Management Information System';

    public function __construct(
        public string $name,
        public ?string $tagline,
        public string $logoUrl,
        public string $color,
        public string $color2,
        public array $contact = [],
        public bool $institution = false,
    ) {}

    public static function system(): self
    {
        return new self(self::SYSTEM_NAME, self::SYSTEM_TAGLINE, self::systemLogoUrl(), '#4E7CFF', '#7033FF');
    }

    public static function forTenant(?Tenant $tenant): self
    {
        if (! $tenant) {
            return self::system();
        }

        return new self(
            name: $tenant->name,
            tagline: $tenant->motto ?: ($tenant->postalAddress() ?: null),
            logoUrl: $tenant->logoUrl() ?? self::systemLogoUrl(),
            color: self::safeColor($tenant->brandColor('primary'), '#4E7CFF'),
            color2: self::safeColor($tenant->brandColor('secondary'), '#7033FF'),
            contact: array_values(array_filter([
                $tenant->postalAddress() ?: null,
                $tenant->phone ? 'Tel: '.$tenant->phone : null,
                $tenant->email,
                $tenant->website,
            ])),
            institution: true,
        );
    }

    /** The hospital a person belongs to: their default one, else their only one. */
    public static function forUser(?object $user): self
    {
        if (! $user instanceof User) {
            return self::system();
        }

        $tenants = $user->tenants()->wherePivot('status', 'active')->get();
        $tenant = $tenants->firstWhere('pivot.is_default', true) ?? ($tenants->count() === 1 ? $tenants->first() : null);

        return self::forTenant($tenant);
    }

    /** Attach this brand to a notification email. */
    public function apply(MailMessage $message): MailMessage
    {
        $message->viewData['brand'] = $this->toArray();
        $message->salutation("Regards,  \n{$this->name}");

        return $message;
    }

    /** Rebuild from toArray() output (e.g. inside a queued notification). */
    public static function fromArray(?array $data): self
    {
        if (! $data || empty($data['name'])) {
            return self::system();
        }

        return new self($data['name'], $data['tagline'] ?? null, $data['logo'] ?? self::systemLogoUrl(), $data['color'] ?? '#4E7CFF', $data['color2'] ?? '#7033FF', $data['contact'] ?? [], (bool) ($data['institution'] ?? false));
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'tagline' => $this->tagline,
            'logo' => $this->logoUrl,
            'color' => $this->color,
            'color2' => $this->color2,
            'contact' => $this->contact,
            'institution' => $this->institution,
        ];
    }

    public static function systemLogoUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/images/hmis-logo.png';
    }

    /** Only #rgb/#rrggbb reach inline styles. */
    protected static function safeColor(?string $value, string $fallback): string
    {
        return is_string($value) && preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $value) ? $value : $fallback;
    }
}
