<?php

namespace Tests\Feature\Workflow;

use App\Models\Bed;
use App\Models\DeathNotification;
use App\Models\DialysisSession;
use App\Models\Facility;
use App\Models\HeiEnrolment;
use App\Models\Immunization;
use App\Models\Invoice;
use App\Models\MedicalCertificate;
use App\Models\Newborn;
use App\Models\Patient;
use App\Models\SpecialtyVisit;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Clinical\DepartmentService;
use App\Services\Clinical\EncounterService;
use App\Services\Inpatient\AdmissionService;
use App\Services\Maternity\MaternityService;
use App\Services\Patients\PatientRegistrationService;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantProvisioningService;
use App\Support\RoleCatalog;
use Database\Seeders\TenantCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DocumentsAndDepartmentsTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected Facility $facility;

    protected User $doctor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = app(TenantProvisioningService::class)->provision(['name' => 'Referral Hospital'], ['name' => 'Main', 'code' => 'MAIN', 'level' => 5]);
        app(TenantCatalogSeeder::class)->seedFor($this->tenant);
        $this->facility = $this->tenant->facilities()->first();
        $this->doctor = User::factory()->create(['email_verified_at' => now()]);
        $this->tenant->users()->attach($this->doctor, ['status' => 'active', 'is_default' => true, 'joined_at' => now()]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->doctor->assignRole(RoleCatalog::HOSPITAL_ADMINISTRATOR);
        $this->login();
    }

    protected function login(): void
    {
        $context = app(TenantContext::class);
        $context->set($this->tenant);
        $context->setFacility($this->facility->fresh());
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->actingAs($this->doctor->fresh());
    }

    protected function patient(array $o = []): Patient
    {
        return app(PatientRegistrationService::class)->register($this->tenant, $o + ['first_name' => 'Juma', 'last_name' => 'Omondi', 'sex' => 'male', 'date_of_birth' => '1970-05-05'], $this->facility);
    }

    public function test_discharge_summary_certificate_prints_with_letterhead(): void
    {
        $p = $this->patient();
        $enc = app(EncounterService::class)->checkIn($p);
        $svc = app(AdmissionService::class);
        $adm = $svc->request($enc, ['reason' => 'Pneumonia']);
        $svc->admit($adm, Bed::where('status', 'available')->firstOrFail()->id);
        $svc->discharge($adm->fresh(), ['discharge_type' => 'routine', 'discharge_summary' => 'Treated with antibiotics; improved.', 'follow_up_date' => now()->addWeek()->toDateString()]);

        $this->get(route('print.discharge', $adm))->assertOk()->assertSee('Discharge summary')->assertSee('Referral Hospital')->assertSee('Treated with antibiotics');

        Livewire::test('pages::consultation.show', ['encounter' => $enc->fresh()])
            ->set('tab', 'disposition')->set('cert.type', 'sick_leave')->set('cert.from_date', now()->toDateString())->set('cert.days', '5')
            ->call('issueCertificate')->assertHasNoErrors();
        $cert = MedicalCertificate::firstOrFail();
        $this->assertSame(5, $cert->days);
        $this->assertSame(now()->addDays(4)->toDateString(), $cert->to_date->toDateString());
        $this->get(route('print.certificate', $cert))->assertOk()->assertSee('unfit for duty');
    }

    public function test_birth_gives_birth_doses_and_enrols_hiv_exposed_infant(): void
    {
        $mother = $this->patient(['first_name' => 'Atieno', 'sex' => 'female', 'date_of_birth' => '1995-01-01']);
        $m = app(MaternityService::class);
        $preg = $m->registerPregnancy($mother, ['lmp' => now()->subWeeks(39)->toDateString(), 'hiv_status' => 'positive']);
        $labour = $m->startLabour($preg, []);
        $m->recordDelivery($labour, ['delivery_mode' => 'svd'], [['sex' => 'female', 'birth_weight_g' => 3100, 'bcg_given' => true, 'opv0_given' => true]]);

        $baby = Newborn::firstOrFail()->baby;
        $this->assertEqualsCanonicalizing(['bcg', 'opv_0'], Immunization::where('patient_id', $baby->id)->pluck('vaccine')->all());
        $hei = HeiEnrolment::where('patient_id', $baby->id)->firstOrFail();
        $this->assertSame('active', $hei->status);

        $this->get(route('print.birth', Newborn::firstOrFail()))->assertOk()->assertSee('Birth notification');

        Livewire::test('pages::cwc.child', ['patient' => $baby])
            ->assertSee('Given')
            ->set('vaccine', 'penta_1')->set('dose.given_on', now()->toDateString())->call('giveVaccine')->assertHasNoErrors()
            ->set('vaccine', 'penta_1')->call('giveVaccine')->assertHasErrors(['vaccine'])
            ->set('hei_test', 'pcr_6wk')->call('addHeiTest');
        $test = $hei->tests()->firstOrFail();
        Livewire::test('pages::cwc.child', ['patient' => $baby])->call('heiResult', $test->id, 'positive');
        $this->assertSame('positive_linked', $hei->fresh()->status);

        $this->get(route('cwc.index'))->assertOk();
    }

    public function test_mortuary_records_death_admits_and_releases_with_charges(): void
    {
        $p = $this->patient();
        $enc = app(EncounterService::class)->checkIn($p);

        Livewire::test('pages::departments.mortuary')
            ->set('patientId', $p->id)->set('death.immediate_cause', 'Septic shock')->set('death.immediate_icd', 'NOT-ICD')
            ->call('recordDeath')->assertHasErrors(['death.immediate_icd'])
            ->set('death.immediate_icd', '')->call('recordDeath')->assertHasNoErrors();

        $d = DeathNotification::firstOrFail();
        $this->assertNotNull($p->fresh()->deceased_at);

        $this->travelTo(now()->subDays(2));
        app(DepartmentService::class)->admitToMortuary($d, 'C3');
        $this->travelBack();
        $this->expectsOccupied($p);

        Livewire::test('pages::departments.mortuary')->set('releasingId', $d->id)
            ->set('release.released_to', 'Mary Omondi')->set('release.released_to_id_number', '12345678')->set('release.released_to_relationship', 'Wife')
            ->call('releaseBody')->assertHasNoErrors();

        $this->assertSame('released', $d->fresh()->body_status);
        $item = Invoice::where('encounter_id', $enc->id)->first()->items()->where('description', 'Mortuary storage (per day)')->first();
        $this->assertSame(2.0, (float) $item->quantity);
        $this->get(route('print.death', $d))->assertOk()->assertSee('Septic shock')->assertSee('Mary Omondi');
    }

    protected function expectsOccupied(Patient $p): void
    {
        $other = $this->patient(['first_name' => 'Other']);
        $d2 = app(DepartmentService::class)->recordDeath($other, ['died_at' => now()->toDateTimeString(), 'place' => 'casualty', 'immediate_cause' => 'Trauma', 'immediate_icd' => '', 'underlying_cause' => '', 'underlying_icd' => '', 'other_conditions' => '']);
        try {
            app(DepartmentService::class)->admitToMortuary($d2, 'C3');
            $this->fail('Occupied storage unit should be refused.');
        } catch (ValidationException) {
            $this->assertSame('in_ward', $d2->fresh()->body_status);
        }
    }

    public function test_blood_bank_issues_only_compatible_units(): void
    {
        $p = $this->patient();
        app(EncounterService::class)->checkIn($p);
        $dept = app(DepartmentService::class);
        $t = ['tenant_id' => $this->tenant->id, 'component' => 'prbc', 'expires_on' => now()->addDays(20)->toDateString(), 'source' => 'KNBTS'];
        $aPos = $dept->receiveUnit($t + ['unit_number' => 'U1', 'abo' => 'A', 'rh' => 'pos']);
        $oNeg = $dept->receiveUnit($t + ['unit_number' => 'U2', 'abo' => 'O', 'rh' => 'neg']);
        $bPos = $dept->receiveUnit($t + ['unit_number' => 'U3', 'abo' => 'B', 'rh' => 'pos']);

        $req = $dept->requestBlood($p, ['patient_abo' => 'A', 'patient_rh' => 'neg', 'component' => 'prbc', 'units_requested' => 1, 'urgency' => 'urgent', 'indication' => 'Hb 6']);

        // A− recipient: only Rh-negative A or O red cells.
        $this->assertSame([$oNeg->id], $dept->compatibleUnits($req)->pluck('id')->all());

        $this->expectException(ValidationException::class);
        try {
            $dept->issueUnit($req, $aPos, 'compatible');
        } finally {
            $dept->issueUnit($req, $oNeg, 'compatible');
            $this->assertSame('issued', $oNeg->fresh()->status);
            $this->assertSame('issued', $req->fresh()->status);
            $this->assertSame('available', $bPos->fresh()->status);
        }
    }

    public function test_dialysis_and_specialist_clinic_bill_and_respect_levels(): void
    {
        $p = $this->patient();
        Livewire::test('pages::departments.dialysis')
            ->set('patientId', $p->id)->set('start.pre_weight_kg', '72.5')->set('start.uf_goal_ml', '2500')
            ->call('startSession')->assertHasNoErrors();
        $session = DialysisSession::firstOrFail();

        Livewire::test('pages::departments.dialysis')->set('endingId', $session->id)->set('end.post_weight_kg', '70.1')->call('endSession')->assertHasNoErrors();
        $session->refresh();
        $this->assertSame('completed', $session->status);
        $this->assertSame(2.4, $session->weightLossKg());
        $this->assertTrue(Invoice::where('encounter_id', $session->encounter_id)->first()->items()->where('description', 'Haemodialysis session')->exists());

        $this->get(route('clinics.eye'))->assertOk()->assertSee('Visual acuity');
        $dept = app(DepartmentService::class);
        $dept->recordClinicVisit('eye', $p->fresh(), ['va_right' => '6/9', 'va_left' => '6/12'], 'Refer for refraction', null);
        $this->assertSame('6/9', SpecialtyVisit::firstOrFail()->data['va_right']);

        // Level 4 hospital: dialysis and oncology hidden/blocked; eye clinic available.
        $this->facility->update(['level' => 4]);
        $this->login();
        $this->get(route('dialysis.index'))->assertForbidden();
        $this->get(route('clinics.oncology'))->assertForbidden();
        $this->get(route('clinics.eye'))->assertOk();
        $this->get(route('mortuary.index'))->assertOk();
        $this->get(route('bloodbank.index'))->assertOk();
    }
}
