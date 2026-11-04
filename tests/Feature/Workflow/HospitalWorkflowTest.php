<?php

namespace Tests\Feature\Workflow;

use App\Models\Admission;
use App\Models\Appointment;
use App\Models\Bed;
use App\Models\Encounter;
use App\Models\InsuranceClaim;
use App\Models\InsuranceProvider;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\LabOrderItem;
use App\Models\LabTest;
use App\Models\Medicine;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Prescription;
use App\Models\RadiologyExam;
use App\Models\RadiologyOrder;
use App\Models\Surgery;
use App\Models\Tenant;
use App\Models\TheatreRoom;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Clinical\EncounterService;
use App\Services\Patients\PatientRegistrationService;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantProvisioningService;
use App\Services\Theatre\TheatreService;
use App\Support\EncounterStage;
use App\Support\RoleCatalog;
use Database\Seeders\TenantCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * End-to-end checks of the integrated patient journey across departments,
 * driven through the real Livewire pages (not just the services).
 */
class HospitalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected array $staff = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->makeTenant('Workflow Hospital');

        foreach ([
            'admin' => RoleCatalog::HOSPITAL_ADMINISTRATOR,
            'doctor' => RoleCatalog::DOCTOR,
            'reception' => RoleCatalog::RECEPTIONIST,
            'triage' => RoleCatalog::TRIAGE_NURSE,
            'labtech' => RoleCatalog::LABORATORY_TECHNICIAN,
            'labmanager' => RoleCatalog::LABORATORY_MANAGER,
            'radiologist' => RoleCatalog::RADIOLOGIST,
            'pharmacist' => RoleCatalog::PHARMACIST,
            'cashier' => RoleCatalog::CASHIER,
            'noic' => RoleCatalog::NURSING_OFFICER_IN_CHARGE,
        ] as $key => $role) {
            $this->staff[$key] = $this->makeStaff($this->tenant, $role);
        }
    }

    protected function makeTenant(string $name): Tenant
    {
        $tenant = app(TenantProvisioningService::class)->provision(['name' => $name], ['name' => 'Main', 'code' => 'MAIN']);
        app(TenantCatalogSeeder::class)->seedFor($tenant);

        return $tenant;
    }

    protected function makeStaff(Tenant $tenant, string $role): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $tenant->users()->attach($user, ['status' => 'active', 'is_default' => true, 'joined_at' => now()]);

        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($tenant->id);
        $user->assignRole($role);

        return $user;
    }

    /** Act as a user inside the tenant (what IdentifyTenant does per request). */
    protected function as(string $key): User
    {
        $user = $this->staff[$key];
        $context = app(TenantContext::class);
        $context->set($this->tenant);
        $context->setFacility($this->tenant->facilities()->first());
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $user->unsetRelation('roles')->unsetRelation('permissions');
        $this->actingAs($user);

        return $user;
    }

    protected function registerPatient(array $overrides = []): Patient
    {
        $this->as('reception');

        return app(PatientRegistrationService::class)->register($this->tenant, array_merge([
            'first_name' => 'Amani', 'last_name' => 'Wekesa', 'sex' => 'female', 'date_of_birth' => '1992-05-01',
        ], $overrides), $this->tenant->facilities()->first());
    }

    public function test_complete_outpatient_journey_across_departments(): void
    {
        $patient = $this->registerPatient();

        // Reception: walk-in check-in.
        $this->as('reception');
        Livewire::test('pages::reception.index')
            ->call('selectPatient', $patient->id)
            ->set('chief_complaint', 'Fever and headache for 3 days')
            ->call('checkIn')
            ->assertHasNoErrors();

        $encounter = Encounter::where('patient_id', $patient->id)->firstOrFail();
        $this->assertSame(EncounterStage::WAITING_TRIAGE, $encounter->stage);
        $this->assertNotNull($encounter->queue_number);

        // A second open visit for the same patient is refused.
        Livewire::test('pages::reception.index')->call('selectPatient', $patient->id)->call('checkIn')->assertHasErrors('patient_id');

        // Triage.
        $this->as('triage');
        Livewire::test('pages::triage.index')
            ->call('select', $encounter->id)
            ->set('vitals.temperature', '38.9')
            ->set('vitals.pulse', '104')
            ->set('vitals.systolic_bp', '118')
            ->set('vitals.diastolic_bp', '76')
            ->set('vitals.spo2', '97')
            ->set('vitals.weight_kg', '64')
            ->set('vitals.height_cm', '165')
            ->call('save')
            ->assertHasNoErrors();

        $encounter->refresh();
        $this->assertSame(EncounterStage::WAITING_DOCTOR, $encounter->stage);
        $this->assertNotNull($encounter->triage_category);
        $this->assertEquals(23.5, (float) $encounter->vitalSigns()->first()->bmi);

        // Doctor consultation: start, note, diagnosis, lab + imaging orders, prescription.
        $doctor = $this->as('doctor');
        $malaria = LabTest::where('code', 'BS-MPS')->firstOrFail();
        $hb = LabTest::where('code', 'HB')->firstOrFail();
        $paracetamol = Medicine::where('code', 'PCM500')->firstOrFail();
        $stockBefore = $paracetamol->stockOnHand();

        $page = Livewire::test('pages::consultation.show', ['encounter' => $encounter])
            ->call('startConsultation')
            ->set('note.presenting_complaint', 'Fever, headache')
            ->set('note.assessment', 'Suspected malaria')
            ->set('note.plan', 'BS for MPS, Hb, antipyretics')
            ->call('saveNote', true)
            ->assertHasNoErrors()
            ->set('dx_code', 'B54')
            ->set('dx_description', 'Malaria, unspecified')
            ->call('addDiagnosis')
            ->set('labTestIds', [$malaria->id, $hb->id])
            ->set('labPriority', 'urgent')
            ->call('orderLab')
            ->assertHasNoErrors()
            ->set('radiologyExamId', RadiologyExam::where('code', 'XR-CHEST')->value('id'))
            ->set('radIndication', 'Rule out pneumonia')
            ->call('orderRadiology')
            ->assertHasNoErrors()
            ->call('addMedicine', $paracetamol->id)
            ->set('rxItems.0.frequency', 'TDS')
            ->set('rxItems.0.duration_days', 3)
            ->call('prescribe')
            ->assertHasNoErrors();

        $encounter->refresh();
        $this->assertSame(EncounterStage::IN_CONSULTATION, $encounter->stage);
        $this->assertSame($doctor->id, $encounter->attending_doctor_id);
        $this->assertSame('signed', $encounter->notes()->first()->status);
        $prescription = Prescription::where('encounter_id', $encounter->id)->firstOrFail();
        $this->assertSame(9, $prescription->items()->first()->quantity, 'TDS x 3 days x 1 tab = 9');

        // Lab: technician collects and enters results; the lab manager verifies (independent verifier) and releases.
        $this->as('labtech');
        $items = LabOrderItem::all();
        $lab = Livewire::test('pages::lab.worklist');
        foreach ($items as $item) {
            $lab->call('collect', $item->id)->assertHasNoErrors();
        }
        $hbItem = $items->firstWhere('lab_test_id', $hb->id);
        $mpsItem = $items->firstWhere('lab_test_id', $malaria->id);
        $lab->set('status', 'collected')
            ->set("results.{$hbItem->id}", '5.1')
            ->call('saveResult', $hbItem->id)
            ->set("results.{$mpsItem->id}", 'P. falciparum seen ++')
            ->call('saveResult', $mpsItem->id)
            ->assertHasNoErrors();

        $this->assertSame('critical', $hbItem->fresh()->flag, 'Hb 5.1 is below half the lower reference limit');

        $this->as('labmanager');
        foreach ([$hbItem, $mpsItem] as $item) {
            Livewire::test('pages::lab.worklist')->call('verifyAndRelease', $item->id)->assertHasNoErrors();
        }
        $this->assertSame('released', $hbItem->fresh()->status);
        $this->assertSame('completed', $hbItem->order->fresh()->status);
        $this->assertTrue($doctor->notifications()->where('data->title', 'CRITICAL lab result')->exists(), 'Ordering doctor is alerted of a critical result');

        // Radiology: perform, report, verify.
        $this->as('radiologist');
        $rad = RadiologyOrder::firstOrFail();
        Livewire::test('pages::radiology.worklist')
            ->call('perform', $rad->id)
            ->call('startReport', $rad->id)
            ->set('findings', 'Clear lung fields.')
            ->set('impression', 'Normal chest X-ray.')
            ->call('saveReport')
            ->call('verify', $rad->id)
            ->assertHasNoErrors();
        $this->assertSame('verified', $rad->fresh()->status);

        // Doctor sends patient to pharmacy.
        $this->as('doctor');
        Livewire::test('pages::consultation.show', ['encounter' => $encounter->fresh()])->call('moveTo', EncounterStage::WAITING_PHARMACY);
        $this->assertSame(EncounterStage::WAITING_PHARMACY, $encounter->fresh()->stage);

        // Pharmacy dispenses — stock drops, patient moves to billing.
        $this->as('pharmacist');
        Livewire::test('pages::pharmacy.queue')->call('select', $prescription->id)->call('dispense')->assertHasNoErrors();
        $this->assertSame('dispensed', $prescription->fresh()->status);
        $this->assertSame($stockBefore - 9, $paracetamol->fresh()->stockOnHand());
        $this->assertSame(EncounterStage::WAITING_BILLING, $encounter->fresh()->stage);

        // Billing: every service is on one invoice, priced from the catalog.
        $invoice = Invoice::where('encounter_id', $encounter->id)->firstOrFail();
        $expected = 200 /* REG-FEE */ + 1000 /* CONSULT */ + (float) $malaria->price + (float) $hb->price + 1500 /* XR-CHEST */ + 9 * (float) $paracetamol->unit_price;
        $this->assertEquals($expected, (float) $invoice->total);

        $this->as('cashier');
        Livewire::test('pages::billing.invoice', ['invoice' => $invoice])
            ->set('amount', (string) ($expected + 100))
            ->call('receive')
            ->assertHasErrors('amount'); // overpayment rejected

        Livewire::test('pages::billing.invoice', ['invoice' => $invoice->fresh()])
            ->set('amount', '1000')
            ->set('method', 'mpesa')
            ->call('receive')
            ->assertHasErrors('reference'); // M-Pesa needs a reference

        Livewire::test('pages::billing.invoice', ['invoice' => $invoice->fresh()])
            ->set('amount', '1000')
            ->set('method', 'mpesa')
            ->set('reference', 'QK12AB34CD')
            ->call('receive')
            ->assertHasNoErrors();
        $this->assertSame('partially_paid', $invoice->fresh()->status);

        Livewire::test('pages::billing.invoice', ['invoice' => $invoice->fresh()])
            ->set('method', 'cash')
            ->call('receive')
            ->assertHasNoErrors();

        $invoice->refresh();
        $this->assertSame('paid', $invoice->status);
        $this->assertEquals(0, (float) $invoice->balance);
        $this->assertSame(2, Payment::count());

        // Settling the bill closes the visit.
        $encounter->refresh();
        $this->assertSame(EncounterStage::COMPLETED, $encounter->stage);
        $this->assertSame('completed', $encounter->status);
        $this->assertGreaterThanOrEqual(6, $encounter->transitions()->count());

        // Printable receipt renders.
        $this->get(route('billing.receipt', Payment::first()))->assertOk()->assertSee('OFFICIAL RECEIPT');
    }

    public function test_inpatient_admission_transfer_and_discharge(): void
    {
        $patient = $this->registerPatient(['first_name' => 'Juma']);

        $this->as('reception');
        Livewire::test('pages::reception.index')->call('selectPatient', $patient->id)->set('skipTriage', true)->call('checkIn')->assertHasNoErrors();
        $encounter = Encounter::where('patient_id', $patient->id)->firstOrFail();

        $this->as('doctor');
        Livewire::test('pages::consultation.show', ['encounter' => $encounter])
            ->call('startConsultation')
            ->set('admit_reason', 'Severe anaemia for transfusion')
            ->set('admit_type', 'emergency')
            ->call('requestAdmission')
            ->assertHasNoErrors();

        $admission = Admission::firstOrFail();
        $this->assertSame('requested', $admission->status);

        $this->as('noic');
        $bed = Bed::where('status', 'available')->firstOrFail();
        Livewire::test('pages::wards.admissions')->set("bedFor.{$admission->id}", $bed->id)->call('allocate', $admission->id)->assertHasNoErrors();

        $admission->refresh();
        $this->assertSame('admitted', $admission->status);
        $this->assertSame('occupied', $bed->fresh()->status);
        $this->assertSame(EncounterStage::ADMITTED, $encounter->fresh()->stage);

        // The same bed cannot be allocated twice.
        $other = $this->registerPatient(['first_name' => 'Other', 'phone' => '0700']);
        $this->as('reception');
        Livewire::test('pages::reception.index')->call('selectPatient', $other->id)->call('checkIn');
        $this->as('doctor');
        Livewire::test('pages::consultation.show', ['encounter' => Encounter::where('patient_id', $other->id)->first()])->set('admit_reason', 'x')->call('requestAdmission');
        $second = Admission::where('patient_id', $other->id)->first();
        $this->as('noic');
        Livewire::test('pages::wards.admissions')->set("bedFor.{$second->id}", $bed->id)->call('allocate', $second->id)->assertHasErrors("bedFor.{$second->id}");
        $this->assertSame('requested', $second->fresh()->status);

        // Nursing note + transfer.
        $newBed = Bed::where('status', 'available')->where('id', '!=', $bed->id)->firstOrFail();
        Livewire::test('pages::wards.admission', ['admission' => $admission])
            ->set('nursing_note', 'Patient comfortable, transfusion started.')
            ->call('addNote')
            ->set('transfer_bed_id', $newBed->id)
            ->set('transfer_reason', 'Moved closer to nursing station')
            ->call('transfer')
            ->assertHasNoErrors();

        $this->assertSame('cleaning', $bed->fresh()->status);
        $this->assertSame('occupied', $newBed->fresh()->status);
        $this->assertSame(2, $admission->bedAssignments()->count());

        // Discharge (NOIC has wards.discharge) → bed charges posted, bed to cleaning.
        Livewire::test('pages::wards.admission', ['admission' => $admission->fresh()])
            ->set('discharge_summary', 'Transfused 2 units. Hb improved. Discharged on haematinics.')
            ->call('discharge')
            ->assertHasNoErrors();

        $this->assertSame('discharged', $admission->fresh()->status);
        $this->assertSame('cleaning', $newBed->fresh()->status);
        $this->assertSame(EncounterStage::WAITING_BILLING, $encounter->fresh()->stage);
        $this->assertTrue(Invoice::where('encounter_id', $encounter->id)->first()->items()->where('category', 'bed')->exists());

        // Bed released after cleaning.
        Livewire::test('pages::wards.board')->call('setBedStatus', $newBed->id, 'available');
        $this->assertSame('available', $newBed->fresh()->status);
    }

    public function test_secondary_module_actions_work(): void
    {
        $patient = $this->registerPatient(['national_id' => '12345678', 'phone' => '0711']);

        // Duplicate detection warns before a second registration.
        $this->as('reception');
        Livewire::test('pages::patients.create')
            ->set('first_name', 'Amani')->set('last_name', 'Wekesa')->set('sex', 'female')->set('national_id', '12345678')
            ->call('register')
            ->assertSet('possibleDuplicates.0.id', $patient->id);
        $this->assertSame(1, Patient::count());

        // Appointment booking with double-booking prevention, then check-in.
        $doctor = $this->staff['doctor'];
        $day = now()->addDay()->toDateString();
        Livewire::test('pages::appointments.index')
            ->set('patient_id', $patient->id)->set('form_doctor_id', $doctor->id)->set('scheduled_date', $day)->set('scheduled_time', '10:00')
            ->call('book')->assertHasNoErrors();
        Livewire::test('pages::appointments.index')
            ->set('patient_id', $patient->id)->set('form_doctor_id', $doctor->id)->set('scheduled_date', $day)->set('scheduled_time', '10:05')
            ->call('book')->assertHasErrors('scheduled_time');
        $appointment = Appointment::firstOrFail();
        Livewire::test('pages::appointments.index')->call('checkIn', $appointment->id);
        $this->assertSame('checked_in', $appointment->fresh()->status);

        // Emergency: unidentified patient gets a record and an emergency visit straight to the doctor.
        Livewire::test('pages::emergency.index')
            ->set('mode', 'new')->set('sex', 'male')->set('estimated_age', 40)
            ->set('triage_category', 'red')->set('chief_complaint', 'RTA, unconscious')->set('arrival_mode', 'ambulance')
            ->call('admit')->assertHasNoErrors();
        $er = Encounter::where('type', 'emergency')->firstOrFail();
        $this->assertSame(EncounterStage::WAITING_DOCTOR, $er->stage);
        $this->assertSame('unidentified', $er->patient->category);

        // Theatre: booking, room clash, checklist gating, completion bills the fee.
        $this->as('admin');
        $room = TheatreRoom::firstOrFail();
        $when = now()->addDay()->setTime(9, 0)->format('Y-m-d\TH:i');
        Livewire::test('pages::theatre.index')
            ->set('encounter_id', $er->id)->set('form.procedure_name', 'Exploratory laparotomy')->set('form.scheduled_at', $when)
            ->set('form.theatre_room_id', $room->id)->set('form.fee', '50000')->set('form.urgency', 'emergency')
            ->call('book')->assertHasNoErrors();
        Livewire::test('pages::theatre.index')
            ->set('encounter_id', $er->id)->set('form.procedure_name', 'Clash')->set('form.scheduled_at', $when)
            ->set('form.theatre_room_id', $room->id)
            ->call('book')->assertHasErrors('scheduled_at');

        $surgery = Surgery::firstOrFail();
        Livewire::test('pages::theatre.show', ['surgery' => $surgery])->call('start')->assertHasErrors('status');
        Livewire::test('pages::theatre.show', ['surgery' => $surgery])
            ->set('consent', true)->set('checked', array_keys(TheatreService::CHECKLIST))
            ->call('start')->assertHasNoErrors()
            ->set('operative_notes', 'Findings: ruptured spleen. Splenectomy done.')
            ->call('complete')->assertHasNoErrors();
        $this->assertSame('completed', $surgery->fresh()->status);
        $this->assertTrue(InvoiceItem::where('category', 'theatre')->where('total', 50000)->exists());

        // Pharmacy stock receipt.
        $medicine = Medicine::where('code', 'AMX500')->firstOrFail();
        $before = $medicine->stockOnHand();
        Livewire::test('pages::pharmacy.medicines')->call('openStock', $medicine->id)
            ->set('receipt.batch_number', 'B-2026-01')->set('receipt.expiry_date', now()->addYear()->toDateString())->set('receipt.quantity', 100)
            ->call('receive')->assertHasNoErrors();
        $this->assertSame($before + 100, $medicine->fresh()->stockOnHand());

        // Insurance: covered visit → invoice linked to cover → claim → submit → paid posts a payment.
        $provider = InsuranceProvider::where('code', 'SHA')->firstOrFail();
        Livewire::test('pages::patients.show', ['patient' => $patient])
            ->set('tab', 'insurance')->set('insurance_provider_id', $provider->id)->set('member_number', 'SHA-0099')
            ->call('addInsurance')->assertHasNoErrors();

        $encounter = Encounter::where('patient_id', $patient->id)->firstOrFail();
        app(EncounterService::class)->complete($encounter);
        $this->as('reception');
        Livewire::test('pages::reception.index')->call('selectPatient', $patient->id)->assertSet('payer_type', 'insurance')->call('checkIn')->assertHasNoErrors();
        $insuredInvoice = Invoice::whereNotNull('patient_insurance_id')->firstOrFail();

        $this->as('admin');
        Livewire::test('pages::insurance.index')->set('tab', 'unclaimed')->call('createClaim', $insuredInvoice->id);
        $claim = InsuranceClaim::firstOrFail();
        Livewire::test('pages::insurance.index')->call('submit', $claim->id)
            ->set('respondingId', $claim->id)->set('response_status', 'paid')->set('approved_amount', (string) $insuredInvoice->balance)
            ->call('respond')->assertHasNoErrors();
        $this->assertSame('paid', $claim->fresh()->status);
        $this->assertSame('paid', $insuredInvoice->fresh()->status);

        // Platform: provisioning a tenant seeds roles, catalogs and its first administrator.
        $platform = User::factory()->create(['email_verified_at' => now(), 'is_platform_admin' => true]);
        app(TenantContext::class)->clear();
        $this->actingAs($platform);
        Livewire::test('pages::platform.tenants')
            ->set('form.name', 'Kisumu Medical Centre')->set('form.admin_name', 'KMC Admin')
            ->set('form.admin_email', 'admin@kmc.test')->set('form.admin_password', 'secret-pass')
            ->call('create')->assertHasNoErrors();
        $kmc = Tenant::where('slug', 'kisumu-medical-centre')->firstOrFail();
        $this->assertTrue(LabTest::withoutGlobalScope('tenant')->where('tenant_id', $kmc->id)->exists());
        $this->assertTrue($kmc->users()->where('email', 'admin@kmc.test')->exists());
    }

    public function test_every_module_page_renders_for_an_administrator(): void
    {
        $patient = $this->registerPatient();
        $this->as('reception');
        Livewire::test('pages::reception.index')->call('selectPatient', $patient->id)->call('checkIn');
        $encounter = Encounter::firstOrFail();
        $invoice = Invoice::firstOrFail();

        $this->as('admin');

        foreach ([
            'dashboard', 'notifications', 'patients.index', 'patients.create', 'appointments.index', 'reception.index',
            'triage.index', 'emergency.index', 'consultation.index', 'lab.worklist', 'lab.catalog', 'radiology.worklist',
            'radiology.catalog', 'pharmacy.queue', 'pharmacy.medicines', 'wards.board', 'wards.setup', 'wards.admissions',
            'theatre.index', 'billing.index', 'billing.services', 'insurance.index', 'reports.index', 'admin.audit',
            'admin.facilities', 'admin.departments', 'admin.staff',
        ] as $route) {
            $this->get(route($route))->assertOk();
        }

        $this->get(route('patients.show', $patient))->assertOk()->assertSee($patient->mrn);
        $this->get(route('patients.edit', $patient))->assertOk();
        $this->get(route('consultation.show', $encounter))->assertOk()->assertSee($encounter->encounter_number);
        $this->get(route('billing.invoice', $invoice))->assertOk()->assertSee($invoice->invoice_number);
        $this->get(route('landing'))->assertRedirect(route('dashboard'));
    }

    public function test_modules_are_restricted_by_role(): void
    {
        $this->as('cashier');
        $this->get(route('billing.index'))->assertOk();
        $this->get(route('consultation.index'))->assertForbidden();
        $this->get(route('lab.worklist'))->assertForbidden();
        $this->get(route('landing'))->assertRedirect(route('billing.index'));

        $this->as('labtech');
        $this->get(route('lab.worklist'))->assertOk();
        $this->get(route('billing.index'))->assertForbidden();
        $this->get(route('pharmacy.queue'))->assertForbidden();

        $this->as('triage');
        $this->get(route('landing'))->assertRedirect(route('triage.index'));
        $this->get(route('reports.index'))->assertForbidden();
    }

    public function test_another_tenants_clinical_and_financial_records_are_not_reachable(): void
    {
        $patient = $this->registerPatient();
        $this->as('reception');
        Livewire::test('pages::reception.index')->call('selectPatient', $patient->id)->call('checkIn');
        $encounter = Encounter::firstOrFail();
        $invoice = Invoice::firstOrFail();

        $this->as('cashier');
        $payment = app(BillingService::class)->receivePayment($invoice, 100, 'cash');

        // A Hospital Administrator of a different tenant.
        $otherTenant = $this->makeTenant('Rival Clinic');
        $intruder = $this->makeStaff($otherTenant, RoleCatalog::HOSPITAL_ADMINISTRATOR);
        app(TenantContext::class)->clear();
        $this->actingAs($intruder);

        $this->get(route('patients.show', $patient->id))->assertNotFound();
        $this->get(route('consultation.show', $encounter->id))->assertNotFound();
        $this->get(route('billing.invoice', $invoice->id))->assertNotFound();
        $this->get(route('billing.receipt', $payment->id))->assertNotFound();
        $this->get(route('patients.index'))->assertOk()->assertDontSee($patient->mrn);
    }

    public function test_suspended_tenant_is_locked_out_and_platform_admin_can_reactivate(): void
    {
        $this->tenant->update(['status' => 'suspended', 'suspension_reason' => 'Unpaid subscription']);
        $this->as('doctor');
        app(TenantContext::class)->clear();
        $this->get(route('consultation.index'))->assertForbidden();

        $platform = User::factory()->create(['email_verified_at' => now(), 'is_platform_admin' => true]);
        $this->actingAs($platform);
        $this->get(route('platform.tenants'))->assertOk()->assertSee('Workflow Hospital');

        Livewire::test('pages::platform.tenants')->call('activate', $this->tenant->id);
        $this->assertSame('active', $this->tenant->fresh()->status);

        $this->actingAs($this->staff['doctor']);
        $this->get(route('landing'))->assertRedirect();
    }
}
