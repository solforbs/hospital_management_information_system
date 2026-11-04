<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tenant extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'legal_name',
        'registration_number',
        'type',
        'email',
        'phone',
        'address',
        'county',
        'country',
        'timezone',
        'locale',
        'currency',
        'branding',
        'settings',
        'subscription_plan',
        'trial_ends_at',
        'status',
        'suspended_at',
        'suspension_reason',
        'short_name',
        'motto',
        'ownership',
        'license_number',
        'kra_pin',
        'nhif_sha_code',
        'phone_alt',
        'emergency_phone',
        'website',
        'po_box',
        'town',
        'sub_county',
        'logo_path',
        'stamp_path',
    ];

    /** Documents the system prints, which signatories can be assigned to. */
    public const DOCUMENTS = [
        'receipt' => 'Payment receipts',
        'invoice' => 'Invoices',
        'referral' => 'Referral letters',
        'discharge' => 'Discharge summaries',
        'lab_report' => 'Laboratory reports',
        'radiology_report' => 'Radiology reports',
        'medical_certificate' => 'Medical certificates / sick notes',
        'birth_notification' => 'Birth notifications',
        'death_notification' => 'Death notifications',
        'claim' => 'Insurance / SHA claims',
    ];

    public function signatories(): HasMany
    {
        return $this->hasMany(Signatory::class)->orderBy('sort_order')->orderBy('id');
    }

    /** Active signatories for a document type, in display order. */
    public function signatoriesFor(string $document)
    {
        return $this->signatories()->where('active', true)->get()
            ->filter(fn (Signatory $s) => in_array($document, $s->documents ?? [], true))
            ->values();
    }

    public function displayName(): string
    {
        return $this->short_name ?: $this->name;
    }

    public function logoUrl(): ?string
    {
        return $this->logo_path ? route('branding.logo', ['tenant' => $this->slug, 'v' => substr(md5($this->logo_path), 0, 8)]) : null;
    }

    public function stampUrl(): ?string
    {
        return $this->stamp_path ? route('branding.stamp', ['v' => substr(md5($this->stamp_path), 0, 8)]) : null;
    }

    public function brandColor(string $key = 'primary'): string
    {
        return $this->branding[$key.'_color'] ?? ($key === 'primary' ? '#4E7CFF' : '#7033FF');
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default);
    }

    /** One-line postal address for letterheads. */
    public function postalAddress(): string
    {
        return collect([
            $this->address,
            $this->po_box ? 'P.O. Box '.$this->po_box : null,
            $this->town,
            $this->county ? $this->county.' County' : null,
            $this->country,
        ])->filter()->implode(', ');
    }

    protected function casts(): array
    {
        return [
            'branding' => 'array',
            'settings' => 'array',
            'trial_ends_at' => 'datetime',
            'suspended_at' => 'datetime',
        ];
    }

    public function domains(): HasMany
    {
        return $this->hasMany(TenantDomain::class);
    }

    public function facilities(): HasMany
    {
        return $this->hasMany(Facility::class);
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tenant_user')
            ->withPivot(['staff_number', 'status', 'is_default', 'joined_at'])
            ->withTimestamps();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
