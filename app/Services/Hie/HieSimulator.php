<?php

namespace App\Services\Hie;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Str;

/**
 * Local stand-in for the DHA HIE, shaped after the documented UAT
 * responses. Lets staff rehearse registry lookups, SHA eligibility, OTP
 * consent and claim submission before DHA issues credentials.
 *
 * Test fixtures:
 * - identification number 00000000 → not found in the Client Registry
 * - identification number ending in 9 → registered but not SHA-eligible
 * - OTP 123456 is always accepted; anything else is rejected
 */
class HieSimulator
{
    public const OTP = '123456';

    public function handle(Request $request): PromiseInterface
    {
        $url = parse_url($request->url());
        $path = '/'.ltrim(Str::after($url['path'] ?? '', '/api/v1'), '/');
        parse_str($url['query'] ?? '', $query);
        $data = $request->isMultipart() ? collect($request->data())->pluck('contents', 'name')->all() : ($request->data() ?: []);

        if (str_ends_with($url['path'] ?? '', '/token')) {
            return $this->ok(['access_token' => 'sim-'.Str::random(24), 'token_type' => 'Bearer', 'expires_in' => 3600]);
        }

        return match (true) {
            $request->method() === 'GET' && $path === '/patients' => $this->patient($query),
            $path === '/patients/eligibility' => $this->eligibility($query),
            $path === '/patients/contacts' => $this->ok(['data' => [['phone' => '+2547******01', 'is_confirmed' => true, 'is_active' => true]]]),
            $path === '/patients/benefits' => $this->ok(['data' => [
                ['benefit_code' => 'SHA-12', 'benefit_name' => 'Primary health care (outpatient)', 'balance' => null],
                ['benefit_code' => 'SHA-04', 'benefit_name' => 'Inpatient services', 'balance' => 300000],
            ]]),
            $path === '/patients/benefits/interventions' => $this->ok(['data' => [
                ['intervention_code' => 'SHA-12-001', 'intervention_name' => 'Consultation', 'needs_preauth' => false],
                ['intervention_code' => 'SHA-12-002', 'intervention_name' => 'Laboratory investigations', 'needs_preauth' => false],
                ['intervention_code' => 'SHA-12-003', 'intervention_name' => 'Imaging', 'needs_preauth' => false],
                ['intervention_code' => 'SHA-12-004', 'intervention_name' => 'Pharmaceuticals', 'needs_preauth' => false],
                ['intervention_code' => 'SHA-04-001', 'intervention_name' => 'General inpatient admission', 'needs_preauth' => true],
            ]]),
            $path === '/facilities/search' => $this->facility($query),
            $path === '/professionals' => $this->professional($query),
            $path === '/claims/otp', $path === '/claims/otp/discharge' => $this->ok(['message' => 'OTP sent. Simulator OTP is '.self::OTP, 'otp' => self::OTP]),
            $path === '/claims/authorize' => $this->ok(['guid' => (string) Str::uuid(), 'token' => Str::random(16), 'auth_code' => strtoupper(Str::random(6)), 'status' => 'PENDING']),
            // Biometric verification completes immediately in the simulator.
            $request->method() === 'GET' && $path === '/claims/authorizations' => $this->ok(['data' => [['guid' => $query['guid'] ?? null, 'token' => $query['token'] ?? null, 'status' => 'APPROVED']]]),
            str_starts_with($path, '/claims/authorizations/') && str_ends_with($path, '/reject') => $this->ok(['status' => 'REJECTED']),
            $request->method() === 'POST' && $path === '/preauths' => $this->ok(['preauth_id' => 'PA-'.strtoupper(Str::random(8)), 'status' => 'PENDING_DOCTOR_APPROVAL']),
            $request->method() === 'GET' && $path === '/preauths' => $this->ok(['data' => [['preauth_id' => $query['preauth_id'] ?? null, 'status' => 'ACTIVE', 'approved_amount' => $query['simulate_amount'] ?? null]]]),
            $path === '/preauths/cancel' => $this->ok(['status' => 'CANCELLED']),
            $path === '/claims/visit' => $this->visit($data),
            in_array($path, ['/claims/interventions', '/claims/diagnoses', '/claims/doctors', '/claims/attachments', '/claims/lines'], true) => $this->ok(['guid' => (string) Str::uuid(), 'status' => 'OK']),
            $path === '/claims/preview' => $this->ok(['invoice_number' => 'SHA-SIM-'.substr(md5((string) ($data['consent_token'] ?? '')), 0, 8), 'workflow_state' => 'DRAFT', 'claim_auth_status' => 'AUTHORIZED', 'blockers' => []]),
            $path === '/claims/submit' => $this->submit($data),
            $path === '/claims/preview/payer' => $this->ok(['guid' => $query['guid'] ?? null, 'status' => 'IN_REVIEW', 'approved_amount' => null]),
            $path === '/claims/close' => $this->ok(['status' => 'CLOSED']),
            default => Factory::response(['message' => "Simulator has no route for {$request->method()} {$path}"], 404),
        };
    }

    protected function ok(array $body): PromiseInterface
    {
        return Factory::response($body, 200);
    }

    protected function patient(array $q): PromiseInterface
    {
        $number = (string) ($q['identification_number'] ?? '');

        if ($number === '' || $number === '00000000') {
            return Factory::response(['message' => 'zero results found in client registry'], 400);
        }

        return $this->ok([
            'id' => $this->crNumber($number),
            'resourceType' => 'Patient',
            'identification_type' => $q['identification_type'] ?? 'National ID',
            'identification_number' => $number,
            'first_name' => 'Simulated',
            'middle_name' => 'Registry',
            'last_name' => 'Client'.substr($number, -3),
            'gender' => (int) substr($number, -1) % 2 ? 'male' : 'female',
            'date_of_birth' => '1988-06-15',
            'phone' => '+254712'.str_pad(substr($number, -6), 6, '0', STR_PAD_LEFT),
            'county' => 'Nairobi',
            'sub_county' => 'Westlands',
            'other_identifications' => [['identification_type' => 'SHA Number', 'identification_number' => 'SHA'.str_pad($number, 13, '0', STR_PAD_LEFT)]],
            'dependants' => [],
        ]);
    }

    protected function eligibility(array $q): PromiseInterface
    {
        $number = (string) ($q['identification_number'] ?? '');

        if ($number === '00000000') {
            return $this->ok(['status' => '00', 'message' => 'Member Not found!']);
        }

        $eligible = ! str_ends_with($number, '9');

        return $this->ok([
            'status' => '10',
            'message' => 'Member found',
            'patient_id' => $this->crNumber($number),
            'full_name' => 'Simulated Registry Client'.substr($number, -3),
            'eligible' => $eligible,
            'eligibility_status' => $eligible ? 'ACTIVE' : 'INACTIVE',
            'reason' => $eligible ? null : 'Contributions not up to date',
            'scheme_code' => 'UHC',
        ]);
    }

    protected function facility(array $q): PromiseInterface
    {
        $code = (string) ($q['identifier'] ?? '');
        $name = trim((string) ($q['name'] ?? ''));

        if ($code === '' && $name !== '') {
            return $this->ok(['data' => [
                ['officialName' => ucwords($name).' County Referral Hospital', 'frCode' => 'FID-47-200001-1', 'kephLevel' => 'Level 5', 'shaContractStatus' => 'ACTIVE'],
                ['officialName' => 'Kenyatta National Hospital', 'frCode' => 'FID-47-100001-5', 'kephLevel' => 'Level 6', 'shaContractStatus' => 'ACTIVE'],
            ]]);
        }

        if ($code === '' || str_contains($code, 'UNKNOWN')) {
            return Factory::response(['message' => 'Facility not found'], 400);
        }

        return $this->ok(['data' => [[
            'officialName' => 'Simulated Facility '.$code,
            'frCode' => $code,
            'fidCode' => $code,
            'kephLevel' => 'Level 4',
            'facilityLicenseStatus' => 'Licensed',
            'shaContractStatus' => 'ACTIVE',
            'shaContractedServices' => ['SHA-12', 'SHA-04'],
            'SHAOperationStatus' => ['operationalStatus' => 'ACTIVE'],
        ]]]);
    }

    protected function professional(array $q): PromiseInterface
    {
        $number = (string) ($q['identification_number'] ?? '');

        if ($number === '' || $number === '000') {
            return Factory::response(['message' => 'Practitioner not found'], 400);
        }

        return $this->ok(['message' => [
            'membership' => ['full_name' => 'Simulated Practitioner', 'registration_id' => $number, 'licensing_body' => $q['regulator'] ?? 'KMPDC', 'is_active' => 1, 'specialty' => 'General practice'],
            'licenses' => [['license_type' => 'Annual practising licence', 'license_start' => now()->startOfYear()->toDateString(), 'license_end' => now()->endOfYear()->toDateString()]],
        ]]);
    }

    protected function visit(array $data): PromiseInterface
    {
        if (empty($data['auth_guid']) && ($data['otp'] ?? null) !== self::OTP) {
            return Factory::response(['message' => 'Invalid or expired OTP'], 400);
        }

        return $this->ok(['consent_token' => 'SIMCONSENT-'.Str::random(20), 'status' => 'ACTIVE']);
    }

    protected function submit(array $data): PromiseInterface
    {
        if (($data['otp'] ?? null) !== self::OTP) {
            return Factory::response(['message' => 'Invalid discharge OTP'], 400);
        }

        return $this->ok([
            'invoice_number' => $data['invoice_number'] ?? null,
            'guid' => (string) Str::uuid(),
            'provider_claim_no' => 'PCN-'.strtoupper(Str::random(8)),
            'workflow_state' => 'SUBMITTED',
        ]);
    }

    protected function crNumber(string $number): string
    {
        return 'CR'.str_pad(substr(preg_replace('/\D/', '', $number) ?: '0', 0, 13), 13, '7', STR_PAD_LEFT).'-'.(strlen($number) % 10);
    }
}
