<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Patient extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    /** Merged duplicates are tombstones: hidden from every search and list. */
    protected static function booted(): void
    {
        static::addGlobalScope('not_merged', fn ($q) => $q->whereNull($q->getModel()->getTable().'.merged_into_id'));

        // Keep the blind index in step with the encrypted national ID.
        static::saving(function (Patient $p) {
            if ($p->isDirty('national_id')) {
                $p->national_id_hash = filled($p->national_id) ? self::identifierHash($p->national_id) : null;
            }
        });
    }

    /** Keyed hash for exact-match lookup of an encrypted identifier. */
    public static function identifierHash(string $value): string
    {
        return hash_hmac('sha256', strtoupper(preg_replace('/\s+/', '', $value)), (string) config('app.key'));
    }

    public static function looksEncrypted(string $value): bool
    {
        $decoded = base64_decode($value, true);

        return $decoded !== false && str_contains($decoded, '"iv"') && str_contains($decoded, '"mac"');
    }

    /** Exact national ID match (the column itself is encrypted). */
    public function scopeWhereNationalId($query, string $value)
    {
        return $query->where('national_id_hash', self::identifierHash($value));
    }

    /**
     * Free-text patient search: name/MRN/phone by prefix or contains,
     * national ID by exact match.
     */
    public function scopeSearch($query, string $term)
    {
        $term = trim($term);
        $like = '%'.$term.'%';

        return $query->where(fn ($q) => $q->where('first_name', 'like', $like)
            ->orWhere('middle_name', 'like', $like)
            ->orWhere('last_name', 'like', $like)
            ->orWhere('mrn', 'like', $like)
            ->orWhere('phone', 'like', $like)
            ->orWhere('national_id_hash', self::identifierHash($term)));
    }

    protected $fillable = [
        'tenant_id',
        'mrn',
        'first_name',
        'middle_name',
        'last_name',
        'date_of_birth',
        'date_of_birth_estimated',
        'sex',
        'national_id',
        'passport_number',
        'phone',
        'alternative_phone',
        'email',
        'address',
        'county',
        'next_of_kin_name',
        'next_of_kin_phone',
        'next_of_kin_relationship',
        'preferred_language',
        'category',
        'photo_path',
        'registered_at',
        'registered_by',
        'facility_id',
        'cr_number',
        'sha_number',
        'identification_type',
        'registry_synced_at',
        'deceased_at',
        'merged_into_id',
        'merged_at',
    ];

    protected function casts(): array
    {
        return [
            'registry_synced_at' => 'datetime',
            'deceased_at' => 'datetime',
            // Field-level encryption of government identifiers (Data Protection Act).
            'national_id' => 'encrypted',
            'cr_number' => 'encrypted',
            'sha_number' => 'encrypted',
            'merged_at' => 'datetime',
            'date_of_birth' => 'date',
            'date_of_birth_estimated' => 'boolean',
            'registered_at' => 'datetime',
        ];
    }

    protected function fullName(): Attribute
    {
        return Attribute::get(fn () => trim("{$this->first_name} {$this->middle_name} {$this->last_name}"));
    }

    protected function age(): Attribute
    {
        return Attribute::get(fn () => $this->date_of_birth?->age);
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    public function encounters(): HasMany
    {
        return $this->hasMany(Encounter::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function allergies(): HasMany
    {
        return $this->hasMany(PatientAllergy::class);
    }

    public function insurances(): HasMany
    {
        return $this->hasMany(PatientInsurance::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function admissions(): HasMany
    {
        return $this->hasMany(Admission::class);
    }

    public function pregnancies(): HasMany
    {
        return $this->hasMany(Pregnancy::class);
    }

    public function vitalSigns(): HasMany
    {
        return $this->hasMany(VitalSign::class);
    }

    public function diagnoses(): HasMany
    {
        return $this->hasMany(Diagnosis::class);
    }

    public function activeEncounter(): ?Encounter
    {
        return $this->encounters()->where('status', 'open')->latest('started_at')->first();
    }
}
