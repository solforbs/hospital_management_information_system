<?php

namespace Tests\Feature\Workflow;

use App\Models\Admission;
use App\Models\Bed;
use App\Models\BedAssignment;
use App\Models\CriticalCareObservation;
use App\Models\Encounter;
use App\Models\Facility;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Referral;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Ward;
use App\Services\Clinical\EncounterService;
use App\Services\Inpatient\AdmissionService;
use App\Services\Inpatient\CriticalCareService;
use App\Services\Patients\PatientRegistrationService;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantProvisioningService;
use App\Support\EncounterStage;
use App\Support\FacilityLevel;
use App\Support\ModuleRegistry;
use App\Support\RoleCatalog;
use Database\Seeders\TenantCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * KEPH levels 1–6 (module availability), inter-facility referrals and
 * critical-care charting / per-segment bed-day billing.
 */
class LevelsReferralsCriticalCareTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected Facility $facility;

    protected array $staff = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = app(TenantProvisioningService::class)->provision(['name' => 'County Hospital'], ['name' => 'Main', 'code' => 'MAIN', 'level' => 5]);
        app(TenantCatalogSeeder::class)->seedFor($this->tenant);
        $this->facility = $this->tenant->facilities()->first();

        foreach (['admin' => RoleCatalog::HOSPITAL_ADMINISTRATOR, 'doctor' => RoleCatalog::DOCTOR, 'reception' => RoleCatalog::RECEPTIONIST, 'nurse' => RoleCatalog::NURSE, 'noic' => RoleCatalog::NURSING_OFFICER_IN_CHARGE] as $key => $role) {
            $user = User::factory()->create(['email_verified_at' => now()]);
            $this->tenant->users()->attach($user, ['status' => 'active', 'is_default' => true, 'joined_at' => now()]);
            app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
            $user->assignRole($role);
            $this->staff[$key] = $user;
        }
    }

    protected function as(string $key): User
    {
        $context = app(TenantContext::class);
        $context->set($this->tenant);
        $context->setFacility($this->facility->fresh());
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $user = $this->staff[$key]->fresh();
        $this->actingAs($user);

        return $user;
    }

    protected function patient(string $first = 'Achieng'): Patient
    {
        $this->as('reception');

        return app(PatientRegistrationService::class)->register($this->tenant, [
            'first_name' => $first, 'last_name' => 'Otieno', 'sex' => 'female', 'date_of_birth' => '1985-03-03',
        ], $this->facility);
    }

    protected function admitTo(string $wardType, Patient $patient): Admission
    {
        $this->as('doctor');
        $encounter = app(EncounterService::class)->checkIn($patient, ['attending_doctor_id' => $this->staff['doctor']->id]);
        $service = app(AdmissionService::class);
        $admission = $service->request($encounter, ['reason' => 'Severe sepsis', 'type' => 'emergency']);
        $bed = Bed::whereHas('ward', fn ($q) => $q->where('type', $wardType))->where('status', 'available')->firstOrFail();
        $this->as('noic');

        return $service->admit($admission, $bed->id);
    }

    public function test_levels_parse_and_label(): void
    {
        $this->assertSame(4, FacilityLevel::parse('Level 4'));
        $this->assertSame(6, FacilityLevel::parse('LEVEL_6'));
        $this->assertNull(FacilityLevel::parse('Unknown'));
        $this->assertNull(FacilityLevel::parse(9));
        $this->assertStringContainsString('National referral', FacilityLevel::label(6));
    }

    public function test_module_availability_follows_keph_level_and_route_access_is_blocked(): void
    {
        $modules = fn (int $level) => collect(ModuleRegistry::toggleable())->filter(fn ($m) => ModuleRegistry::defaultAtLevel($m, $level))->pluck('key');

        $this->assertNotContains('consultation', $modules(1));
        $this->assertContains('referrals', $modules(1));
        $this->assertContains('pharmacy', $modules(2));
        $this->assertNotContains('wards', $modules(2));
        $this->assertContains('wards', $modules(3));
        $this->assertNotContains('theatre', $modules(3));
        $this->assertContains('theatre', $modules(4));
        $this->assertContains('critical-care', $modules(4));
        $this->assertSame($modules(5)->all(), $modules(6)->all());
        $this->assertCount(count(ModuleRegistry::toggleable()), $modules(6));

        // A Level 2 dispensary: theatre and wards are hidden and blocked by URL.
        $this->facility->update(['level' => 2]);
        $this->as('admin');
        $keys = collect(ModuleRegistry::forUser($this->staff['admin']->fresh()))->pluck('key');
        $this->assertNotContains('theatre', $keys);
        $this->assertContains('pharmacy', $keys);
        $this->get(route('theatre.index'))->assertForbidden();
        $this->get(route('wards.board'))->assertForbidden();
        $this->get(route('pharmacy.queue'))->assertOk();
        $this->get(route('admin.facilities'))->assertOk(); // core pages always reachable

        // Administrator override: enable wards at the dispensary, disable pharmacy.
        Livewire::test('pages::admin.facilities')
            ->call('configure', $this->facility->id)
            ->assertSet('configLevel', '2')
            ->set('modules.wards', true)
            ->set('modules.pharmacy', false)
            ->call('saveConfiguration')->assertHasNoErrors();

        $this->facility->refresh();
        $this->assertEqualsCanonicalizing(['wards' => true, 'pharmacy' => false], $this->facility->enabled_modules);
        $this->as('admin');
        $this->get(route('wards.board'))->assertOk();
        $this->get(route('pharmacy.queue'))->assertForbidden();

        // Raising the level to 6 resets toggles to that level's defaults.
        Livewire::test('pages::admin.facilities')
            ->call('configure', $this->facility->id)
            ->set('configLevel', '6')
            ->assertSet('modules.theatre', true)
            ->assertSet('modules.pharmacy', true)
            ->call('saveConfiguration');
        $this->assertSame(6, $this->facility->fresh()->level);
        $this->assertNull($this->facility->fresh()->enabled_modules);
    }

    public function test_outgoing_referral_from_visit_moves_patient_to_billing_and_records_feedback(): void
    {
        $patient = $this->patient();
        $this->as('doctor');
        $encounter = app(EncounterService::class)->checkIn($patient, ['attending_doctor_id' => $this->staff['doctor']->id]);
        app(EncounterService::class)->transition($encounter, EncounterStage::IN_CONSULTATION);

        Livewire::test('pages::referrals.create', ['direction' => 'outgoing', 'encounter' => $encounter->id])
            ->assertSet('other_facility_level', '6')
            ->set('other_facility_name', 'Kenyatta National Hospital')
            ->set('other_facility_code', 'FID-47-100001-5')
            ->set('department', 'Neurosurgery')
            ->set('urgency', 'urgent')
            ->set('reason', 'Suspected subdural haematoma; needs CT and neurosurgical review.')
            ->set('transport', 'ambulance')
            ->call('save', true)->assertHasNoErrors();

        $referral = Referral::firstOrFail();
        $this->assertSame('outgoing', $referral->direction);
        $this->assertSame('sent', $referral->status);
        $this->assertSame(6, $referral->other_facility_level);
        $this->assertStringStartsWith('REF-', $referral->referral_number);
        $this->assertSame(EncounterStage::WAITING_BILLING, $encounter->fresh()->stage);

        $page = Livewire::test('pages::referrals.show', ['referral' => $referral])
            ->assertSee('Kenyatta National Hospital')
            ->set('response', 'rejected')->set('feedback', '')->call('respond')->assertHasErrors(['feedback']);

        $page->set('response', 'accepted')->call('respond')->assertHasNoErrors();
        $this->assertSame('accepted', $referral->fresh()->status);

        Livewire::test('pages::referrals.show', ['referral' => $referral->fresh()])
            ->set('response', 'completed')->set('feedback', 'Burr hole done; back-referred for rehab.')->call('respond');
        $this->assertSame('completed', $referral->fresh()->status);
        $this->assertNotNull($referral->fresh()->completed_at);
    }

    public function test_referring_to_same_or_lower_level_warns(): void
    {
        $patient = $this->patient();
        $this->as('doctor');
        $encounter = app(EncounterService::class)->checkIn($patient);

        Livewire::test('pages::referrals.create', ['direction' => 'outgoing', 'encounter' => $encounter->id])
            ->set('other_facility_level', '3')
            ->assertSee('same or a lower level');
    }

    public function test_incoming_referral_is_received_and_back_referred(): void
    {
        $patient = $this->patient('Wanjiku');
        $this->as('reception');

        Livewire::test('pages::referrals.create', ['direction' => 'incoming'])
            ->call('selectPatient', $patient->id)
            ->set('other_facility_name', 'Kibera Health Centre')
            ->set('other_facility_level', '3')
            ->set('urgency', 'emergency')
            ->set('reason', 'Obstructed labour')
            ->call('save')->assertHasNoErrors();

        $referral = Referral::where('direction', 'incoming')->firstOrFail();
        $this->assertSame('sent', $referral->status);

        // Back-referral is not allowed before the patient arrives.
        Livewire::test('pages::referrals.show', ['referral' => $referral])
            ->set('feedback', 'Delivered by C-section.')->call('backRefer')->assertHasErrors(['status']);

        Livewire::test('pages::referrals.show', ['referral' => $referral])->call('receive')->assertHasNoErrors();
        $referral->refresh();
        $this->assertSame('arrived', $referral->status);
        $encounter = Encounter::findOrFail($referral->received_encounter_id);
        $this->assertSame('emergency', $encounter->priority);
        $this->assertStringContainsString('Kibera Health Centre', $encounter->chief_complaint);

        Livewire::test('pages::referrals.show', ['referral' => $referral])
            ->set('feedback', 'Emergency C-section, live baby. Review at your facility in 2 weeks.')
            ->call('backRefer')->assertHasNoErrors();
        $this->assertSame('completed', $referral->fresh()->status);
        $this->assertSame('back_referral', $referral->fresh()->kind);

        $this->as('reception');
        Livewire::test('pages::referrals.index')->set('direction', 'incoming')->set('status', 'closed')->assertSee('Kibera Health Centre');
    }

    public function test_critical_care_chart_records_obs_computes_map_balance_and_alerts(): void
    {
        $admission = $this->admitTo('icu', $this->patient());

        $this->as('nurse');
        $this->get(route('critical-care.index'))->assertOk()->assertSee($admission->patient->full_name);

        $page = Livewire::test('pages::critical-care.chart', ['admission' => $admission])
            ->set('obs.heart_rate', '88')->set('obs.systolic', '120')->set('obs.diastolic', '60')
            ->set('obs.spo2', '97')->set('obs.intake_ml', '500')->set('obs.output_ml', '300')->set('obs.urine_ml', '250')
            ->set('obs.ventilation', 'oxygen')
            ->call('record')->assertHasNoErrors();

        $obs = CriticalCareObservation::firstOrFail();
        $this->assertSame(80, $obs->map); // (120 + 2*60) / 3
        $this->assertSame([], $obs->alerts());

        // Urine cannot exceed total output.
        $page->set('obs.output_ml', '100')->set('obs.urine_ml', '200')->call('record')->assertHasErrors(['obs.urine_ml']);

        // Dangerous values alert the admitting doctor.
        $page->set('obs.urine_ml', '')->set('obs.output_ml', '')->set('obs.systolic', '70')->set('obs.diastolic', '40')->set('obs.spo2', '85')->set('obs.intake_ml', '250')
            ->call('record')->assertHasNoErrors();
        $this->assertContains('spo2', CriticalCareObservation::latest('id')->first()->alerts());
        $this->assertSame(1, $this->staff['doctor']->fresh()->notifications()->count());

        $balance = app(CriticalCareService::class)->fluidBalance($admission);
        $this->assertSame(['intake' => 750, 'output' => 300, 'urine' => 250, 'balance' => 450, 'entries' => 2], $balance);

        // Empty submissions are rejected.
        Livewire::test('pages::critical-care.chart', ['admission' => $admission])->call('record')->assertHasErrors(['obs']);
    }

    public function test_critical_care_chart_is_only_for_icu_hdu_nicu_beds(): void
    {
        $admission = $this->admitTo('general', $this->patient());
        $this->as('nurse');
        $this->get(route('critical-care.chart', $admission))->assertNotFound();
    }

    public function test_icu_then_ward_stay_is_billed_per_segment_at_each_unit_rate(): void
    {
        $this->travelTo(Carbon::parse('2026-11-01 08:00:00'));
        $admission = $this->admitTo('icu', $this->patient());
        $icu = Ward::where('type', 'icu')->firstOrFail();
        $general = Ward::where('code', 'FMW')->firstOrFail();

        // 3 days in ICU, then transfer to the general ward for 2 days.
        $this->travelTo(Carbon::parse('2026-11-04 08:00:00'));
        $this->as('noic');
        $generalBed = Bed::where('ward_id', $general->id)->where('status', 'available')->firstOrFail();
        app(AdmissionService::class)->transfer($admission->fresh(), $generalBed->id, 'Stepped down');

        $this->travelTo(Carbon::parse('2026-11-06 08:00:00'));
        app(AdmissionService::class)->discharge($admission->fresh(), ['discharge_type' => 'routine', 'discharge_summary' => 'Recovered.', 'follow_up_date' => null]);
        $this->travelBack();

        $items = Invoice::where('encounter_id', $admission->encounter_id)->firstOrFail()->items()->where('category', 'bed')->get();
        $this->assertCount(2, $items);
        $this->assertSame(5.0, (float) $items->sum('quantity'));
        $icuLine = $items->firstWhere('unit_price', $icu->daily_rate);
        $this->assertNotNull($icuLine);
        $this->assertSame(3.0, (float) $icuLine->quantity);
        $this->assertSame((new BedAssignment)->getMorphClass(), $icuLine->source_type);
        $this->assertSame(2.0, (float) $items->firstWhere('unit_price', $general->daily_rate)->quantity);
    }

    public function test_ward_setup_rejects_icu_below_level_five_and_new_tenant_gets_level(): void
    {
        $this->facility->update(['level' => 3]);
        $this->as('admin');

        Livewire::test('pages::wards.setup')
            ->set('form.name', 'New ICU')->set('form.code', 'ICU2')->set('form.type', 'icu')->set('form.daily_rate', '20000')
            ->set('form.facility_id', (string) $this->facility->id)
            ->call('save')->assertHasErrors(['form.type']);

        $this->facility->update(['level' => 5]);
        $this->as('admin');
        Livewire::test('pages::wards.setup')
            ->set('form.name', 'New ICU')->set('form.code', 'ICU2')->set('form.type', 'icu')->set('form.daily_rate', '20000')
            ->set('form.facility_id', (string) $this->facility->id)->set('form.sha_intervention_code', 'sha-03-001')
            ->call('save')->assertHasNoErrors();
        $this->assertSame('SHA-03-001', Ward::where('code', 'ICU2')->value('sha_intervention_code'));
    }
}
