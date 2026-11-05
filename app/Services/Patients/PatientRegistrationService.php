<?php

namespace App\Services\Patients;

use App\Models\Facility;
use App\Models\Patient;
use App\Models\PatientNumberSequence;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The only place a Patient should be created. Generates a tenant-specific,
 * concurrency-safe MRN (row-locked sequence per tenant/year) and persists
 * the patient in the same transaction so a failed create never burns a
 * sequence number silently.
 */
class PatientRegistrationService
{
    public function register(Tenant $tenant, array $attributes, ?Facility $facility = null): Patient
    {
        return DB::transaction(function () use ($tenant, $attributes, $facility) {
            $mrn = $this->nextMrn($tenant, $facility);

            return Patient::create(array_merge($attributes, [
                'tenant_id' => $tenant->id,
                'facility_id' => $facility?->id,
                'mrn' => $mrn,
                'registered_at' => now(),
            ]));
        });
    }

    /**
     * Demographic duplicate check used before registration. Never merges
     * automatically — it only returns candidates for the user to review.
     */
    public function findPossibleDuplicates(array $attributes, ?int $ignoreId = null)
    {
        $first = trim($attributes['first_name'] ?? '');
        $last = trim($attributes['last_name'] ?? '');
        $dob = $attributes['date_of_birth'] ?? null;
        $phone = trim($attributes['phone'] ?? '');
        $nationalId = trim($attributes['national_id'] ?? '');

        return Patient::query()
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->where(function ($q) use ($first, $last, $dob, $phone, $nationalId) {
                if ($nationalId !== '') {
                    $q->orWhere('national_id_hash', Patient::identifierHash($nationalId));
                }
                if ($first !== '' && $last !== '' && $dob) {
                    $q->orWhere(fn ($w) => $w->where('first_name', $first)->where('last_name', $last)->whereDate('date_of_birth', $dob));
                }
                if ($phone !== '' && $last !== '') {
                    $q->orWhere(fn ($w) => $w->where('phone', $phone)->where('last_name', $last));
                }
                if ($nationalId === '' && ! $dob && $phone === '') {
                    $q->whereRaw('1 = 0');
                }
            })
            ->limit(5)
            ->get();
    }

    protected function nextMrn(Tenant $tenant, ?Facility $facility): string
    {
        $year = (int) now()->format('y');

        $sequence = PatientNumberSequence::query()
            ->where('tenant_id', $tenant->id)
            ->where('year', $year)
            ->lockForUpdate()
            ->first();

        if (! $sequence) {
            // Race-safe: if two requests reach here at once, the unique
            // (tenant_id, year) constraint makes one insert fail; whichever
            // fails simply re-reads the row the other created.
            try {
                $sequence = PatientNumberSequence::create([
                    'tenant_id' => $tenant->id,
                    'year' => $year,
                    'last_number' => 0,
                ]);
            } catch (QueryException) {
                $sequence = PatientNumberSequence::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('year', $year)
                    ->lockForUpdate()
                    ->firstOrFail();
            }
        }

        $sequence->increment('last_number');

        $prefix = $facility?->patient_number_prefix ?: strtoupper(substr($tenant->slug, 0, 3));

        return sprintf('%s-%d-%06d', $prefix, $year, $sequence->last_number);
    }
}
