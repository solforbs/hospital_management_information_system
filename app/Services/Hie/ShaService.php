<?php

namespace App\Services\Hie;

use App\Models\Encounter;
use App\Models\Patient;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * SHA member verification and visit consent through the HIE:
 * eligibility → benefits/interventions → OTP (or biometric) consent, which
 * yields the consent_token every subsequent claim call is keyed on.
 */
class ShaService
{
    public const SERVICE_TYPES = ['OUTPATIENT' => 'Outpatient', 'INPATIENT' => 'Inpatient', 'EMERGENCY' => 'Emergency', 'CAPITATION' => 'Primary care (capitation)'];

    protected const ELIGIBILITY_ID_TYPES = ['National ID', 'Alien ID', 'Refugee ID'];

    public function __construct(protected HieClient $client, protected AuditLogger $audit) {}

    public function checkEligibility(Encounter $encounter): array
    {
        $patient = $encounter->patient;
        [$type, $number] = $this->identification($patient);

        $body = $this->client->get('/patients/eligibility', ['identification_number' => $number, 'identification_type' => $type]);

        $found = (string) ($body['status'] ?? '') === '10' || (bool) ($body['patient_id'] ?? false);
        $eligible = $found && ($body['eligible'] ?? true) !== false && ! in_array(strtoupper((string) ($body['eligibility_status'] ?? 'ACTIVE')), ['INACTIVE', 'SUSPENDED', 'NOT_ELIGIBLE'], true);

        $snapshot = [
            'found' => $found,
            'eligible' => $eligible,
            'cr_number' => $body['patient_id'] ?? $patient->cr_number,
            'name' => $body['full_name'] ?? null,
            'status' => $body['eligibility_status'] ?? ($found ? 'FOUND' : 'NOT_FOUND'),
            'message' => $body['reason'] ?? $body['message'] ?? null,
            'scheme_code' => $body['scheme_code'] ?? config('hms.hie.scheme_code'),
        ];

        if ($found && $snapshot['cr_number'] && ! $patient->cr_number) {
            $patient->update(['cr_number' => $snapshot['cr_number'], 'registry_synced_at' => now()]);
        }

        $encounter->update(['sha_eligibility' => $snapshot, 'sha_checked_at' => now()]);
        $this->audit->log('sha.eligibility_checked', $encounter, Arr::only($snapshot, ['found', 'eligible', 'status']));

        return $snapshot;
    }

    /**
     * Benefits with their interventions, for choosing what the visit covers.
     */
    public function interventions(Encounter $encounter): array
    {
        $cr = $this->crNumber($encounter);
        $benefits = $this->list($this->client->get('/patients/benefits', ['patient_id' => $cr]));

        return collect($benefits)->map(function ($benefit) use ($cr) {
            $code = $benefit['benefit_code'] ?? $benefit['code'] ?? null;
            $items = $code ? $this->list($this->client->get('/patients/benefits/interventions', ['patient_id' => $cr, 'sub_benefit_code' => $code])) : [];

            return [
                'code' => $code,
                'name' => $benefit['benefit_name'] ?? $benefit['name'] ?? $code,
                'interventions' => collect($items)->map(fn ($i) => [
                    'code' => $i['intervention_code'] ?? $i['code'] ?? null,
                    'name' => $i['intervention_name'] ?? $i['name'] ?? null,
                    'needs_preauth' => (bool) ($i['needs_preauth'] ?? false),
                ])->filter(fn ($i) => $i['code'])->values()->all(),
            ];
        })->filter(fn ($b) => $b['code'])->values()->all();
    }

    /**
     * Ask SHA to send the member an SMS OTP for this visit.
     */
    public function requestVisitOtp(Encounter $encounter, array $interventionCodes, string $serviceType): string
    {
        $interventionCodes = array_values(array_filter($interventionCodes));

        if (! $interventionCodes) {
            throw ValidationException::withMessages(['sha' => __('Select at least one SHA intervention for this visit.')]);
        }

        if (! array_key_exists($serviceType, self::SERVICE_TYPES)) {
            throw ValidationException::withMessages(['sha' => __('Choose a valid SHA service type.')]);
        }

        $body = $this->client->post('/claims/otp', ['patient_id' => $this->crNumber($encounter), 'intervention_codes' => $interventionCodes]);

        $encounter->update(['sha_interventions' => $interventionCodes, 'sha_service_type' => $serviceType]);
        $this->audit->log('sha.otp_requested', $encounter, ['interventions' => $interventionCodes, 'service_type' => $serviceType]);

        return (string) ($body['message'] ?? __('OTP sent to the member\'s registered phone.'));
    }

    /**
     * Exchange the member's OTP for a visit consent token.
     */
    public function confirmVisitOtp(Encounter $encounter, string $otp): void
    {
        if (! $encounter->sha_interventions || ! $encounter->sha_service_type) {
            throw ValidationException::withMessages(['sha' => __('Request an OTP first.')]);
        }

        try {
            $body = $this->client->post('/claims/visit', [
                'patient_id' => $this->crNumber($encounter),
                'service_type' => $encounter->sha_service_type,
                'intervention_codes' => $encounter->sha_interventions,
                'otp' => trim($otp),
            ]);
        } catch (HieException $e) {
            if ($e->status && $e->status < 500) {
                throw ValidationException::withMessages(['otp' => __('SHA rejected the OTP: :msg', ['msg' => $e->body['message'] ?? $e->getMessage()])]);
            }
            throw $e;
        }

        $token = $body['consent_token'] ?? Arr::get($body, 'data.consent_token');

        if (! $token) {
            throw new HieException(__('SHA did not return a consent token.'));
        }

        $encounter->update(['sha_consent_token' => $token, 'sha_consent_method' => 'otp', 'sha_consented_at' => now()]);
        $this->audit->log('sha.visit_consented', $encounter, ['method' => 'otp']);
    }

    /**
     * Biometric consent, step 1: open a pending authorisation that the member
     * completes on the facility's SHA fingerprint device. A pending
     * biometric authorisation blocks the OTP path until completed or rejected.
     */
    public function requestBiometric(Encounter $encounter, array $interventionCodes, string $serviceType): array
    {
        $interventionCodes = array_values(array_filter($interventionCodes));

        if (! $interventionCodes || ! array_key_exists($serviceType, self::SERVICE_TYPES)) {
            throw ValidationException::withMessages(['sha' => __('Select the interventions and service type first.')]);
        }

        $body = $this->client->post('/claims/authorize', [
            'patient_id' => $this->crNumber($encounter),
            'service_type' => $serviceType,
            'interventions' => $interventionCodes,
        ]);

        $guid = $body['guid'] ?? Arr::get($body, 'data.guid');
        $token = $body['token'] ?? Arr::get($body, 'data.token');

        if (! $guid) {
            throw new HieException(__('SHA did not open a biometric authorisation.'));
        }

        $encounter->update([
            'sha_interventions' => $interventionCodes,
            'sha_service_type' => $serviceType,
            'sha_auth_guid' => $guid,
            'sha_eligibility' => array_merge($encounter->sha_eligibility ?? [], ['biometric_token' => $token, 'biometric_code' => $body['auth_code'] ?? null]),
        ]);
        $this->audit->log('sha.biometric_requested', $encounter);

        return ['guid' => $guid, 'auth_code' => $body['auth_code'] ?? null];
    }

    /**
     * Biometric consent, step 2: once the member has been verified on the
     * device, exchange the authorisation for the visit consent token.
     * Returns the authorisation status (PENDING, APPROVED, REJECTED...).
     */
    public function completeBiometric(Encounter $encounter): string
    {
        if (! $encounter->sha_auth_guid) {
            throw ValidationException::withMessages(['sha' => __('Start a fingerprint verification first.')]);
        }

        $token = Arr::get($encounter->sha_eligibility ?? [], 'biometric_token');
        $body = $this->client->get('/claims/authorizations', array_filter(['guid' => $encounter->sha_auth_guid, 'token' => $token]));
        $record = $this->list($body)[0] ?? $body;
        $status = strtoupper((string) ($record['status'] ?? 'PENDING'));

        if (! in_array($status, ['APPROVED', 'VERIFIED', 'AUTHORIZED', 'COMPLETED'], true)) {
            return $status;
        }

        $visit = $this->client->post('/claims/visit', [
            'patient_id' => $this->crNumber($encounter),
            'service_type' => $encounter->sha_service_type,
            'intervention_codes' => $encounter->sha_interventions,
            'auth_guid' => $encounter->sha_auth_guid,
        ]);

        $consent = $visit['consent_token'] ?? Arr::get($visit, 'data.consent_token');
        if (! $consent) {
            throw new HieException(__('SHA did not return a consent token.'));
        }

        $encounter->update(['sha_consent_token' => $consent, 'sha_consent_method' => 'biometric', 'sha_consented_at' => now()]);
        $this->audit->log('sha.visit_consented', $encounter, ['method' => 'biometric']);

        return 'APPROVED';
    }

    /** Clear a pending biometric authorisation (unblocks the OTP path). */
    public function cancelBiometric(Encounter $encounter): void
    {
        $token = Arr::get($encounter->sha_eligibility ?? [], 'biometric_token');

        if ($token) {
            $this->client->post('/claims/authorizations/'.rawurlencode($token).'/reject');
        }

        $encounter->update(['sha_auth_guid' => null]);
        $this->audit->log('sha.biometric_cancelled', $encounter);
    }

    /**
     * Discharge OTP: SHA requires member confirmation before a claim is submitted.
     */
    public function requestDischargeOtp(Encounter $encounter): string
    {
        $this->requireConsent($encounter);
        $body = $this->client->post('/claims/otp/discharge', ['consent_token' => $encounter->sha_consent_token]);
        $this->audit->log('sha.discharge_otp_requested', $encounter);

        return (string) ($body['message'] ?? __('Discharge OTP sent to the member.'));
    }

    public function requireConsent(Encounter $encounter): void
    {
        if (! $encounter->sha_consent_token) {
            throw ValidationException::withMessages(['sha' => __('This visit has no SHA consent. Verify the member and confirm the OTP at reception first.')]);
        }
    }

    public function crNumber(Encounter $encounter): string
    {
        $cr = $encounter->patient->cr_number ?? Arr::get($encounter->sha_eligibility ?? [], 'cr_number');

        if (! $cr) {
            throw ValidationException::withMessages(['sha' => __('Patient has no Client Registry number. Run the SHA eligibility check first.')]);
        }

        return $cr;
    }

    /** @return array{0: string, 1: string} */
    protected function identification(Patient $patient): array
    {
        if ($patient->national_id) {
            $type = in_array($patient->identification_type, self::ELIGIBILITY_ID_TYPES, true) ? $patient->identification_type : 'National ID';

            return [$type, $patient->national_id];
        }

        throw ValidationException::withMessages(['sha' => __('Patient has no national, alien or refugee ID on file. Add it to the patient record first.')]);
    }

    protected function list(array $body): array
    {
        $list = $body['data'] ?? $body['result'] ?? $body;

        return is_array($list) && array_is_list($list) ? $list : [];
    }
}
