<?php

namespace App\Services\Hie;

use App\Models\Facility;
use App\Models\Patient;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\FacilityLevel;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/**
 * DHA HIE registries: Client Registry (patients), Facility Registry and
 * Health Worker Registry. Responses are normalised defensively because the
 * published schemas and live payloads differ in wrapping and key casing.
 */
class RegistryService
{
    /** Client Registry identification types (hie-docs Client Registry enum). */
    public const ID_TYPES = [
        'National ID' => 'National ID',
        'Alien ID' => 'Alien ID',
        'Refugee ID' => 'Refugee ID',
        'Birth Certificate' => 'Birth certificate',
        'Birth Notification' => 'Birth notification',
        'Mandate Number' => 'Mandate number',
        'ClientRegistry ID' => 'Client Registry (CR) ID',
    ];

    public const REGULATORS = ['KMPDC' => 'KMPDC (doctors, dentists, COs)', 'NCK' => 'Nursing Council (NCK)', 'COC' => 'Clinical Officers Council (COC)', 'PPB' => 'Pharmacy & Poisons Board (PPB)'];

    public function __construct(protected HieClient $client, protected AuditLogger $audit) {}

    /**
     * Look up a person in the national Client Registry. Returns normalised
     * demographics, or null when the registry has no match.
     */
    public function findClient(string $idType, string $idNumber): ?array
    {
        try {
            $body = $this->client->get('/patients', ['identification_type' => $idType, 'identification_number' => trim($idNumber)]);
        } catch (HieException $e) {
            if ($e->status === 400 || $e->status === 404) {
                return null;
            }
            throw $e;
        }

        $record = $this->unwrap($body);

        if (! $record || ! ($record['id'] ?? $record['first_name'] ?? null)) {
            return null;
        }

        $sha = collect($record['other_identifications'] ?? [])
            ->first(fn ($i) => str_contains(strtoupper((string) ($i['identification_type'] ?? '')), 'SHA'));

        $gender = strtolower((string) ($record['gender'] ?? ''));

        return [
            'cr_number' => $record['id'] ?? null,
            'sha_number' => $sha['identification_number'] ?? null,
            'identification_type' => $record['identification_type'] ?? $idType,
            'national_id' => $record['identification_number'] ?? $idNumber,
            'first_name' => $record['first_name'] ?? '',
            'middle_name' => $record['middle_name'] ?? '',
            'last_name' => $record['last_name'] ?? '',
            'date_of_birth' => $this->date($record['date_of_birth'] ?? null),
            'sex' => in_array($gender, ['male', 'female'], true) ? $gender : 'unknown',
            'phone' => $record['phone'] ?? '',
            'county' => $record['county'] ?? '',
            'dependants' => count($record['dependants'] ?? []),
        ];
    }

    /**
     * Link a local patient to their Client Registry record (CR/SHA numbers).
     * Demographics are not overwritten — staff review differences.
     */
    public function syncPatient(Patient $patient, string $idType, string $idNumber): ?array
    {
        $found = $this->findClient($idType, $idNumber);

        if ($found) {
            $patient->update([
                'cr_number' => $found['cr_number'],
                'sha_number' => $found['sha_number'] ?? $patient->sha_number,
                'identification_type' => $idType,
                'national_id' => $patient->national_id ?: ($idType === 'National ID' ? $idNumber : null),
                'registry_synced_at' => now(),
            ]);
            $this->audit->log('hie.client_registry_linked', $patient, ['cr_number' => $found['cr_number']]);
        }

        return $found;
    }

    public function verifyFacility(Facility $facility, ?string $frCode = null): array
    {
        $frCode = trim($frCode ?: (string) $facility->fr_code);
        $body = $this->client->get('/facilities/search', ['identifier' => $frCode, 'identifier-type' => 'fr-code'], $facility);
        $record = $this->unwrap($body);

        if (! $record) {
            throw new HieException(__('Facility :code was not found in the Facility Registry.', ['code' => $frCode]));
        }

        $facility->update([
            'fr_code' => $record['frCode'] ?? $frCode,
            'keph_level' => $record['kephLevel'] ?? $facility->keph_level,
            // The national register is authoritative for the KEPH level.
            'level' => FacilityLevel::parse($record['kephLevel'] ?? null) ?? $facility->level,
            'sha_contract_status' => $record['shaContractStatus'] ?? null,
            'registry_verified_at' => now(),
        ]);
        $this->audit->log('hie.facility_verified', $facility, ['fr_code' => $facility->fr_code, 'sha_contract' => $facility->sha_contract_status]);

        return [
            'name' => $record['officialName'] ?? null,
            'keph_level' => $record['kephLevel'] ?? null,
            'license_status' => $record['facilityLicenseStatus'] ?? null,
            'sha_contract_status' => $record['shaContractStatus'] ?? null,
            'operational_status' => Arr::get($record, 'SHAOperationStatus.operationalStatus'),
        ];
    }

    /**
     * Search the Facility Registry by name (referral destinations).
     *
     * @return array<int, array{name: string, code: ?string, level: ?int, sha_contract_status: ?string}>
     */
    public function searchFacilities(string $name): array
    {
        try {
            $body = $this->client->get('/facilities/search', ['name' => trim($name)]);
        } catch (HieException $e) {
            if ($e->status === 400 || $e->status === 404) {
                return [];
            }
            throw $e;
        }

        $list = $body['data'] ?? $body['result'] ?? $body;
        $list = is_array($list) && array_is_list($list) ? $list : (is_array($list) && $list ? [$list] : []);

        return collect($list)->map(fn ($r) => [
            'name' => (string) ($r['officialName'] ?? $r['name'] ?? ''),
            'code' => $r['frCode'] ?? $r['fidCode'] ?? null,
            'level' => FacilityLevel::parse($r['kephLevel'] ?? null),
            'sha_contract_status' => $r['shaContractStatus'] ?? null,
        ])->filter(fn ($r) => $r['name'] !== '')->take(20)->values()->all();
    }

    /**
     * Verify a practitioner's licence in the Health Worker Registry. SHA checks
     * the same registry when a doctor is attached to a claim.
     */
    public function verifyPractitioner(User $user): array
    {
        if (! $user->regulator || ! $user->registration_number) {
            throw new HieException(__('Enter the regulator and registration number first.'));
        }

        try {
            $body = $this->client->get('/professionals', [
                'identification_type' => 'registration_number',
                'identification_number' => $user->registration_number,
                'regulator' => $user->regulator,
            ]);
        } catch (HieException $e) {
            if ($e->status === 400 || $e->status === 404) {
                throw new HieException(__('No practitioner with that registration number was found.'), $e->status);
            }
            throw $e;
        }

        $record = $body['message'] ?? $this->unwrap($body) ?? [];
        $licenseEnd = collect($record['licenses'] ?? [])->pluck('license_end')->filter()->sort()->last();
        $active = (bool) Arr::get($record, 'membership.is_active', false);

        $user->forceFill([
            'license_valid_until' => $this->date($licenseEnd),
            'registry_verified_at' => $active ? now() : null,
        ])->save();
        $this->audit->log('hie.practitioner_verified', $user, ['regulator' => $user->regulator, 'active' => $active, 'license_end' => $licenseEnd]);

        return [
            'name' => Arr::get($record, 'membership.full_name'),
            'active' => $active,
            'license_end' => $licenseEnd,
            'specialty' => Arr::get($record, 'membership.specialty'),
        ];
    }

    /** Accept {data: {...}}, {data: [{...}]}, [{...}] or a flat record. */
    protected function unwrap(array $body): ?array
    {
        $record = $body['data'] ?? $body['result'] ?? $body;

        if (is_array($record) && array_is_list($record)) {
            $record = $record[0] ?? null;
        }

        return is_array($record) && $record ? $record : null;
    }

    protected function date(mixed $value): ?string
    {
        try {
            return $value ? Carbon::parse($value)->toDateString() : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
