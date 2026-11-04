<?php

namespace Tests\Feature\Integration;

use App\Models\Diagnosis;
use App\Models\Encounter;
use App\Models\HieCredential;
use App\Models\HieRequestLog;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VitalSign;
use App\Services\Clinical\EncounterService;
use App\Services\Hie\FhirMapper;
use App\Services\Patients\PatientRegistrationService;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantProvisioningService;
use App\Support\EncounterStage;
use App\Support\RoleCatalog;
use Database\Seeders\TenantCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FhirSharedHealthRecordTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected User $doctor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = app(TenantProvisioningService::class)->provision(['name' => 'FHIR Hospital'], ['name' => 'Main', 'code' => 'MAIN', 'fr_code' => 'FID-47-1-1']);
        app(TenantCatalogSeeder::class)->seedFor($this->tenant);
        $this->doctor = User::factory()->create(['email_verified_at' => now()]);
        $this->tenant->users()->attach($this->doctor, ['status' => 'active', 'is_default' => true, 'joined_at' => now()]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->doctor->assignRole(RoleCatalog::DOCTOR);

        $context = app(TenantContext::class);
        $context->set($this->tenant);
        $context->setFacility($this->tenant->facilities()->first());
        $this->actingAs($this->doctor);
    }

    protected function visit(): Encounter
    {
        $patient = app(PatientRegistrationService::class)->register($this->tenant, [
            'first_name' => 'Amina', 'middle_name' => 'W', 'last_name' => 'Juma', 'sex' => 'female', 'date_of_birth' => '1990-02-02', 'national_id' => '23456789', 'phone' => '0711000000',
        ], $this->tenant->facilities()->first());
        $patient->update(['cr_number' => 'CR123-4']);

        $encounter = app(EncounterService::class)->checkIn($patient, ['attending_doctor_id' => $this->doctor->id, 'chief_complaint' => 'Cough']);
        VitalSign::create(['tenant_id' => $this->tenant->id, 'patient_id' => $patient->id, 'encounter_id' => $encounter->id, 'pulse' => 88, 'systolic_bp' => 120, 'diastolic_bp' => 80, 'temperature' => 37.9, 'recorded_at' => now()]);
        Diagnosis::create(['tenant_id' => $this->tenant->id, 'patient_id' => $patient->id, 'encounter_id' => $encounter->id, 'code' => 'CA40.0', 'code_system' => 'icd11', 'description' => 'Bacterial pneumonia', 'certainty' => 'confirmed']);

        return $encounter;
    }

    public function test_bundle_contains_coded_resources_with_stable_ids(): void
    {
        $encounter = $this->visit();
        $bundle = app(FhirMapper::class)->encounterBundle($encounter);

        $this->assertSame('Bundle', $bundle['resourceType']);
        $types = collect($bundle['entry'])->pluck('resource.resourceType');
        foreach (['Organization', 'Patient', 'Encounter', 'Condition', 'Observation'] as $t) {
            $this->assertContains($t, $types);
        }

        $condition = collect($bundle['entry'])->firstWhere('resource.resourceType', 'Condition')['resource'];
        $this->assertSame(FhirMapper::ICD11, $condition['code']['coding'][0]['system']);
        $this->assertSame('CA40.0', $condition['code']['coding'][0]['code']);

        $patient = collect($bundle['entry'])->firstWhere('resource.resourceType', 'Patient')['resource'];
        $this->assertContains('CR123-4', collect($patient['identifier'])->pluck('value'));
        $this->assertSame('female', $patient['gender']);

        $bp = collect($bundle['entry'])->first(fn ($e) => ($e['resource']['code']['coding'][0]['code'] ?? null) === '85354-9');
        $this->assertCount(2, $bp['resource']['component']);

        // Deterministic ids: a second export updates the same resources.
        $again = app(FhirMapper::class)->encounterBundle($encounter);
        $this->assertSame(collect($bundle['entry'])->pluck('fullUrl')->all(), collect($again['entry'])->pluck('fullUrl')->all());

        $this->get(route('fhir.encounter', $encounter))->assertOk()->assertHeader('Content-Type', 'application/fhir+json')->assertJsonPath('resourceType', 'Bundle');
    }

    public function test_completed_visit_is_pushed_to_shr_when_hie_enabled(): void
    {
        HieCredential::create(['tenant_id' => $this->tenant->id, 'environment' => 'simulator', 'enabled' => true]);
        $encounter = $this->visit();
        app(EncounterService::class)->transition($encounter, EncounterStage::IN_CONSULTATION);

        app(EncounterService::class)->complete($encounter->fresh());

        $encounter->refresh();
        $this->assertSame('sent', $encounter->shr_status);
        $this->assertNotNull($encounter->shr_sent_at);
        $this->assertTrue(HieRequestLog::where('path', config('hms.hie.shr_path'))->where('status', 200)->exists());
    }
}
