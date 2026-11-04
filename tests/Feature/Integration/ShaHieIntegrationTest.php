<?php

namespace Tests\Feature\Integration;

use App\Models\Diagnosis;
use App\Models\Encounter;
use App\Models\HieCredential;
use App\Models\HieRequestLog;
use App\Models\InsuranceProvider;
use App\Models\Patient;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Billing\InsuranceService;
use App\Services\Clinical\EncounterService;
use App\Services\Hie\HieSimulator;
use App\Services\Hie\ShaService;
use App\Services\Patients\PatientRegistrationService;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantProvisioningService;
use App\Support\RoleCatalog;
use Database\Seeders\TenantCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * SHA / DHA HIE integration: registry lookups, eligibility, OTP consent
 * and electronic claim submission, against the built-in simulator and
 * against a faked UAT endpoint (asserting the real HTTP contract).
 */
class ShaHieIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected array $staff = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = app(TenantProvisioningService::class)->provision(['name' => 'SHA Hospital'], ['name' => 'Main', 'code' => 'MAIN']);
        app(TenantCatalogSeeder::class)->seedFor($this->tenant);

        foreach (['admin' => RoleCatalog::HOSPITAL_ADMINISTRATOR, 'reception' => RoleCatalog::RECEPTIONIST, 'doctor' => RoleCatalog::DOCTOR, 'insurance' => RoleCatalog::INSURANCE_OFFICER] as $key => $role) {
            $user = User::factory()->create(['email_verified_at' => now()]);
            $this->tenant->users()->attach($user, ['status' => 'active', 'is_default' => true, 'joined_at' => now()]);
            app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
            $user->assignRole($role);
            $this->staff[$key] = $user;
        }

        $this->staff['doctor']->forceFill(['regulator' => 'KMPDC', 'registration_number' => 'A1234'])->save();
        $this->tenant->facilities()->first()->update(['fr_code' => 'FID-47-115307-8']);
    }

    protected function as(string $key): User
    {
        $context = app(TenantContext::class);
        $context->set($this->tenant);
        $context->setFacility($this->tenant->facilities()->first()->fresh());
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $user = $this->staff[$key]->fresh();
        $this->actingAs($user);

        return $user;
    }

    protected function enable(string $environment = 'simulator', array $extra = []): HieCredential
    {
        return HieCredential::create(array_merge([
            'tenant_id' => $this->tenant->id, 'environment' => $environment, 'enabled' => true,
        ], $extra));
    }

    /** An insured outpatient visit with a coded diagnosis and some charges. */
    protected function shaVisit(string $nationalId = '24567890'): Encounter
    {
        $this->as('reception');
        $patient = app(PatientRegistrationService::class)->register($this->tenant, [
            'first_name' => 'Wanjiru', 'last_name' => 'Mwangi', 'sex' => 'female', 'date_of_birth' => '1990-01-01', 'national_id' => $nationalId,
        ], $this->tenant->facilities()->first());

        $provider = InsuranceProvider::where('code', 'SHA')->firstOrFail();
        $patient->insurances()->create(['tenant_id' => $this->tenant->id, 'insurance_provider_id' => $provider->id, 'member_number' => 'SHA-1', 'active' => true]);

        return app(EncounterService::class)->checkIn($patient, ['payer_type' => 'insurance', 'attending_doctor_id' => $this->staff['doctor']->id]);
    }

    public function test_admin_configures_integration_and_verifies_facility_and_practitioner_in_simulator(): void
    {
        $this->as('admin');

        Livewire::test('pages::admin.integrations')
            ->set('environment', 'simulator')->set('enabled', true)
            ->call('saveConnection')->assertHasNoErrors()
            ->call('testConnection');

        $credential = HieCredential::firstOrFail();
        $this->assertTrue($credential->enabled);
        $this->assertNotNull($credential->last_tested_at);

        $facility = $this->tenant->facilities()->first();
        Livewire::test('pages::admin.integrations')
            ->set("facilityCodes.{$facility->id}.fr_code", 'FID-47-115307-8')
            ->call('saveFacility', $facility->id, true)->assertHasNoErrors();
        $this->assertNotNull($facility->fresh()->registry_verified_at);
        $this->assertSame('ACTIVE', $facility->fresh()->sha_contract_status);

        $doctor = $this->staff['doctor'];
        Livewire::test('pages::admin.integrations')
            ->set("practitioners.{$doctor->id}.regulator", 'KMPDC')
            ->set("practitioners.{$doctor->id}.registration_number", 'A1234')
            ->call('savePractitioner', $doctor->id, true)->assertHasNoErrors();
        $this->assertNotNull($doctor->fresh()->registry_verified_at);
        $this->assertTrue($doctor->fresh()->license_valid_until->isFuture());

        $this->assertGreaterThan(0, HieRequestLog::count());
    }

    public function test_production_requires_credentials_and_keeps_secret_encrypted(): void
    {
        $this->as('admin');

        Livewire::test('pages::admin.integrations')
            ->set('environment', 'production')->set('client_id', '')
            ->call('saveConnection')->assertHasErrors(['client_id']);

        Livewire::test('pages::admin.integrations')
            ->set('environment', 'uat')->set('client_id', 'my-client')->set('client_secret', 'top-secret')->set('enabled', true)
            ->call('saveConnection')->assertHasNoErrors();

        $raw = \DB::table('hie_credentials')->first();
        $this->assertNotSame('top-secret', $raw->client_secret);
        $this->assertSame('top-secret', HieCredential::first()->client_secret);
        $this->assertArrayNotHasKey('client_secret', HieCredential::first()->toArray());

        // Saving again without typing the secret keeps the stored one.
        Livewire::test('pages::admin.integrations')->call('saveConnection')->assertHasNoErrors();
        $this->assertSame('top-secret', HieCredential::first()->client_secret);
    }

    public function test_registration_prefills_from_client_registry(): void
    {
        $this->enable();
        $this->as('reception');

        Livewire::test('pages::patients.create')
            ->set('lookup_type', 'National ID')->set('lookup_number', '24567890')
            ->call('lookupRegistry')
            ->assertSet('national_id', '24567890')
            ->assertSet('first_name', 'Simulated')
            ->assertNotSet('cr_number', null)
            ->set('category', 'general')
            ->call('register')->assertHasNoErrors();

        $patient = Patient::where('national_id', '24567890')->firstOrFail();
        $this->assertStringStartsWith('CR', $patient->cr_number);
        $this->assertNotNull($patient->sha_number);

        Livewire::test('pages::patients.create')
            ->set('lookup_number', '00000000')->call('lookupRegistry')
            ->assertSet('cr_number', null)
            ->assertSee('No Client Registry record found');
    }

    public function test_full_sha_journey_eligibility_consent_and_claim_submission(): void
    {
        $this->enable();
        $encounter = $this->shaVisit();

        // Reception: eligibility → benefits → OTP → consent.
        $this->as('reception');
        $page = Livewire::test('pages::sha.visit', ['encounter' => $encounter])
            ->call('checkEligibility')
            ->assertSee('Eligible');
        $this->assertTrue($encounter->fresh()->sha_eligibility['eligible']);
        $this->assertNotNull($encounter->fresh()->patient->cr_number);

        $page->set('selected', ['SHA-12-001', 'SHA-12-002'])->set('service_type', 'OUTPATIENT')
            ->call('requestOtp')->assertSet('otpSent', true)
            ->set('otp', '000000')->call('confirmOtp')->assertHasErrors(['otp'])
            ->set('otp', HieSimulator::OTP)->call('confirmOtp')->assertHasNoErrors();

        $encounter->refresh();
        $this->assertNotNull($encounter->sha_consent_token);
        $this->assertSame('otp', $encounter->sha_consent_method);
        $this->assertNotSame($encounter->sha_consent_token, \DB::table('encounters')->where('id', $encounter->id)->value('sha_consent_token'), 'consent token is encrypted at rest');

        // Clinical + billing.
        $this->as('doctor');
        Diagnosis::create(['tenant_id' => $this->tenant->id, 'patient_id' => $encounter->patient_id, 'encounter_id' => $encounter->id, 'code' => 'CA40.0', 'description' => 'Pneumonia', 'recorded_by' => $this->staff['doctor']->id]);
        app(BillingService::class)->chargeService($encounter, 'CONSULT');
        $invoice = app(BillingService::class)->invoiceFor($encounter);
        $this->assertNotNull($invoice->patient_insurance_id);

        // Insurance officer: create claim, submitting asks for discharge OTP.
        $this->as('insurance');
        $claim = app(InsuranceService::class)->createClaim($invoice->fresh());

        $page = Livewire::test('pages::insurance.index')
            ->call('submit', $claim->id)->assertSet('shaClaimId', $claim->id)
            ->call('sendDischargeOtp')
            ->set('discharge_otp', '999999')->call('submitSha')->assertHasErrors(['otp']);
        $this->assertSame('draft', $claim->fresh()->status);

        $page->set('discharge_otp', HieSimulator::OTP)->set('discharge_reason', 'RECOVERED')->call('submitSha')->assertHasNoErrors();

        $claim->refresh();
        $this->assertSame('submitted', $claim->status);
        $this->assertSame('sha', $claim->gateway);
        $this->assertSame('SUBMITTED', $claim->external_status);
        $this->assertNotNull($claim->external_reference);
        $this->assertNotEmpty($claim->gateway_response['progress']['lines']);

        Livewire::test('pages::insurance.index')->call('syncStatus', $claim->id);
        $this->assertSame('IN_REVIEW', $claim->fresh()->external_status);
        $this->assertSame('submitted', $claim->fresh()->status);

        // No OTP or consent token is ever written to the request log.
        $logs = HieRequestLog::pluck('request')->toJson();
        $this->assertStringNotContainsString(HieSimulator::OTP, $logs);
        $this->assertStringNotContainsString('SIMCONSENT', $logs);
    }

    public function test_claim_is_blocked_without_consent_coded_diagnosis_or_registered_doctor(): void
    {
        $this->enable();
        $encounter = $this->shaVisit();
        app(BillingService::class)->chargeService($encounter, 'CONSULT');
        $invoice = app(BillingService::class)->invoiceFor($encounter);

        $this->as('insurance');
        $claim = app(InsuranceService::class)->createClaim($invoice->fresh());

        // No consent yet.
        Livewire::test('pages::insurance.index')->call('submit', $claim->id)
            ->set('discharge_otp', HieSimulator::OTP)->call('submitSha')->assertHasErrors(['sha']);

        $encounter->update(['sha_consent_token' => 'SIMCONSENT-x', 'sha_interventions' => ['SHA-12-001'], 'sha_service_type' => 'OUTPATIENT']);

        // No ICD-coded diagnosis.
        Livewire::test('pages::insurance.index')->call('submit', $claim->id)
            ->set('discharge_otp', HieSimulator::OTP)->call('submitSha')->assertHasErrors(['claim']);

        Diagnosis::create(['tenant_id' => $this->tenant->id, 'patient_id' => $encounter->patient_id, 'encounter_id' => $encounter->id, 'code' => 'CA40.0', 'description' => 'Pneumonia']);
        $this->staff['doctor']->forceFill(['registration_number' => null])->save();

        // Doctor without registration number.
        Livewire::test('pages::insurance.index')->call('submit', $claim->id)
            ->set('discharge_otp', HieSimulator::OTP)->call('submitSha')->assertHasErrors(['claim']);

        $this->assertSame('draft', $claim->fresh()->status);
    }

    public function test_not_eligible_member_cannot_proceed_to_consent(): void
    {
        $this->enable();
        $encounter = $this->shaVisit('24567899'); // simulator: ending in 9 = not eligible

        $this->as('reception');
        Livewire::test('pages::sha.visit', ['encounter' => $encounter])
            ->call('checkEligibility')
            ->assertSee('Not eligible')
            ->assertSee('Confirm eligibility first');
        $this->assertFalse($encounter->fresh()->sha_eligibility['eligible']);
    }

    public function test_uat_calls_use_oauth_token_facility_headers_and_refresh_on_401(): void
    {
        $this->enable('uat', ['client_id' => 'cid', 'client_secret' => 'secret', 'token_url' => 'https://auth.example.test/token', 'base_url' => 'https://hie.example.test/api/v1']);
        $tokenCalls = 0;
        $eligibilityCalls = 0;

        Http::fake(function (Request $request) use (&$tokenCalls, &$eligibilityCalls) {
            if ($request->url() === 'https://auth.example.test/token') {
                $tokenCalls++;

                return Http::response(['access_token' => 'tok-'.$tokenCalls, 'expires_in' => 3600]);
            }

            if (str_starts_with($request->url(), 'https://hie.example.test/api/v1/patients/eligibility')) {
                $eligibilityCalls++;

                // First call: expired token.
                if ($eligibilityCalls === 1) {
                    return Http::response(['message' => 'expired'], 401);
                }

                return Http::response(['status' => '10', 'patient_id' => 'CR1234567890123-4', 'eligible' => true]);
            }

            return Http::response([], 404);
        });

        $encounter = $this->shaVisit();
        $this->as('reception');
        $result = app(ShaService::class)->checkEligibility($encounter);

        $this->assertTrue($result['eligible']);
        $this->assertSame(2, $tokenCalls, 'token refreshed once after 401');

        Http::assertSent(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_contains($request->url(), '/patients/eligibility')
                && $request->hasHeader('Authorization', 'Bearer tok-2')
                && $request->hasHeader('X-Facility-Id', 'FID-47-115307-8')
                && $request->hasHeader('X-Facility-Id-Type', 'fr-code')
                && ($query['identification_type'] ?? null) === 'National ID'
                && ($query['identification_number'] ?? null) === '24567890';
        });
        Http::assertSent(fn (Request $r) => $r->url() === 'https://auth.example.test/token' && $r['grant_type'] === 'client_credentials' && $r['client_id'] === 'cid');
    }

    public function test_unconfirmed_submit_is_not_retried_and_can_be_reconciled(): void
    {
        $this->enable('uat', ['client_id' => 'cid', 'client_secret' => 'secret', 'token_url' => 'https://auth.example.test/token', 'base_url' => 'https://hie.example.test/api/v1']);
        $submits = 0;

        Http::fake(function (Request $request) use (&$submits) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                $request->url() === 'https://auth.example.test/token' => Http::response(['access_token' => 'tok']),
                $path === '/api/v1/claims/submit' => (function () use (&$submits) {
                    $submits++;

                    return Http::failedConnection();
                })(),
                $path === '/api/v1/claims/preview' => Http::response(['invoice_number' => 'SHA-INV-1', 'workflow_state' => $submits ? 'SUBMITTED' : 'DRAFT', 'blockers' => []]),
                default => Http::response(['guid' => 'g', 'status' => 'OK']),
            };
        });

        $encounter = $this->shaVisit();
        $encounter->patient->update(['cr_number' => 'CR1234567890123-4']);
        $encounter->update(['sha_consent_token' => 'consent-1', 'sha_interventions' => ['SHA-12-001'], 'sha_service_type' => 'OUTPATIENT']);
        Diagnosis::create(['tenant_id' => $this->tenant->id, 'patient_id' => $encounter->patient_id, 'encounter_id' => $encounter->id, 'code' => 'CA40.0', 'description' => 'Pneumonia']);
        app(BillingService::class)->chargeService($encounter, 'CONSULT');

        $this->as('insurance');
        $claim = app(InsuranceService::class)->createClaim(app(BillingService::class)->invoiceFor($encounter)->fresh());

        Livewire::test('pages::insurance.index')->call('submit', $claim->id)
            ->set('discharge_otp', '123456')->call('submitSha');

        $this->assertSame(1, $submits, 'submit is sent exactly once');
        $this->assertSame('SUBMIT_UNCONFIRMED', $claim->fresh()->external_status);
        $this->assertSame('draft', $claim->fresh()->status);

        // A second attempt is refused until reconciled.
        Livewire::test('pages::insurance.index')->call('submit', $claim->id)
            ->set('discharge_otp', '123456')->call('submitSha')->assertHasErrors(['claim']);
        $this->assertSame(1, $submits);

        app(InsuranceService::class)->syncStatus($claim->fresh());
        $this->assertSame('submitted', $claim->fresh()->status);
    }

    public function test_hie_data_is_tenant_isolated_and_pages_are_permission_gated(): void
    {
        $this->enable();
        $other = app(TenantProvisioningService::class)->provision(['name' => 'Other'], ['name' => 'Main', 'code' => 'MAIN']);
        HieCredential::withoutGlobalScope('tenant')->create(['tenant_id' => $other->id, 'environment' => 'uat', 'client_id' => 'other', 'client_secret' => 'other-secret', 'enabled' => true]);

        $this->as('admin');
        $this->assertSame('simulator', HieCredential::first()->environment);
        $this->assertSame(1, HieCredential::count());

        $this->as('reception');
        $this->get(route('admin.integrations'))->assertForbidden();

        $encounter = $this->shaVisit();
        $this->as('doctor');
        $this->get(route('sha.visit', $encounter))->assertForbidden();
    }
}
