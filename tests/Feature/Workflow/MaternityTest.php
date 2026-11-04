<?php

namespace Tests\Feature\Workflow;

use App\Models\AncVisit;
use App\Models\Encounter;
use App\Models\Facility;
use App\Models\Invoice;
use App\Models\Labour;
use App\Models\Newborn;
use App\Models\Patient;
use App\Models\PncVisit;
use App\Models\Pregnancy;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Maternity\MaternityService;
use App\Services\Patients\PatientRegistrationService;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantProvisioningService;
use App\Support\EncounterStage;
use App\Support\ModuleRegistry;
use App\Support\RoleCatalog;
use Database\Seeders\TenantCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Maternity pathway: ANC booking and risk screening, ANC visits, labour on
 * the partograph (alert/action lines), delivery with newborn registration,
 * postnatal care, and facility-level gating of the labour ward.
 */
class MaternityTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected Facility $facility;

    protected array $staff = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = app(TenantProvisioningService::class)->provision(['name' => 'Mama Hospital'], ['name' => 'Main', 'code' => 'MAIN', 'level' => 4]);
        app(TenantCatalogSeeder::class)->seedFor($this->tenant);
        $this->facility = $this->tenant->facilities()->first();

        foreach (['midwife' => RoleCatalog::MIDWIFE, 'doctor' => RoleCatalog::DOCTOR, 'reception' => RoleCatalog::RECEPTIONIST, 'cashier' => RoleCatalog::CASHIER] as $key => $role) {
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

    protected function mother(array $overrides = []): Patient
    {
        $this->as('reception');

        return app(PatientRegistrationService::class)->register($this->tenant, array_merge([
            'first_name' => 'Akinyi', 'last_name' => 'Odhiambo', 'sex' => 'female', 'date_of_birth' => now()->subYears(26)->toDateString(), 'phone' => '+254711000111',
        ], $overrides), $this->facility);
    }

    protected function registered(array $form = []): Pregnancy
    {
        $mother = $this->mother();
        $this->as('midwife');

        $page = Livewire::test('pages::maternity.register')->call('selectPatient', $mother->id)->set('form.lmp', now()->subWeeks(20)->toDateString());
        foreach ($form as $k => $v) {
            $page->set("form.$k", $v);
        }
        $page->call('save')->assertHasNoErrors();

        return Pregnancy::latest('id')->firstOrFail();
    }

    public function test_midwife_lands_on_labour_ward_and_modules_follow_levels(): void
    {
        $this->as('midwife');
        $this->assertSame(route('labour.index'), ModuleRegistry::landingRoute($this->staff['midwife']->fresh()));

        $anc = ModuleRegistry::find('maternity');
        $labour = ModuleRegistry::find('labour-ward');
        $this->assertTrue(ModuleRegistry::defaultAtLevel($anc, 2));
        $this->assertFalse(ModuleRegistry::defaultAtLevel($labour, 2));
        $this->assertTrue(ModuleRegistry::defaultAtLevel($labour, 3));

        // Level 2 dispensary: ANC yes, labour ward blocked.
        $this->facility->update(['level' => 2]);
        $this->as('midwife');
        $this->get(route('maternity.index'))->assertOk();
        $this->get(route('labour.index'))->assertForbidden();
    }

    public function test_booking_computes_edd_and_screens_risk(): void
    {
        $p = $this->registered();
        $this->assertSame(now()->subWeeks(20)->addDays(280)->toDateString(), $p->edd->toDateString());
        $this->assertSame(20, $p->gestationWeeksOn());
        $this->assertSame('low', $p->risk_level);
        $this->assertStringStartsWith('ANC-', $p->anc_number);

        // High-risk booking: previous C/S, HIV+, Rh negative.
        $mother = $this->mother(['first_name' => 'Wairimu', 'phone' => '+254711000222']);
        $this->as('midwife');
        Livewire::test('pages::maternity.register')->call('selectPatient', $mother->id)
            ->set('form.lmp', now()->subWeeks(12)->toDateString())->set('form.gravida', '3')->set('form.parity', '2')
            ->set('form.previous_caesareans', '1')->set('form.hiv_status', 'positive')->set('form.rhesus', 'negative')
            ->call('save')->assertHasNoErrors();
        $high = Pregnancy::where('patient_id', $mother->id)->firstOrFail();
        $this->assertSame('high', $high->risk_level);
        $this->assertCount(3, $high->risk_factors);

        // Gravida must exceed parity + abortions; one active pregnancy at a time; female only.
        Livewire::test('pages::maternity.register')->call('selectPatient', $mother->id)->set('form.lmp', now()->subWeeks(5)->toDateString())->call('save')->assertHasErrors(['patient']);
        $other = $this->mother(['first_name' => 'Nyokabi', 'phone' => '+254711000333']);
        $this->as('midwife');
        Livewire::test('pages::maternity.register')->call('selectPatient', $other->id)->set('form.lmp', now()->subWeeks(5)->toDateString())->set('form.gravida', '2')->set('form.parity', '2')->call('save')->assertHasErrors(['gravida']);
        $man = $this->mother(['first_name' => 'Otieno', 'sex' => 'male', 'phone' => '+254711000444']);
        $this->as('midwife');
        Livewire::test('pages::maternity.register')->call('selectPatient', $man->id)->assertStatus(404);
        $this->assertSame(0, Pregnancy::where('patient_id', $man->id)->count());
    }

    public function test_anc_visit_flags_pre_eclampsia_and_anaemia(): void
    {
        $p = $this->registered();

        Livewire::test('pages::maternity.pregnancy', ['pregnancy' => $p])
            ->set('anc.weight_kg', '68')->set('anc.systolic', '150')->set('anc.diastolic', '100')->set('anc.urine_protein', '++')
            ->set('anc.hb', '9.5')->set('anc.fetal_heart_rate', '144')->set('anc.iptp_given', true)->set('anc.ifas_given', true)
            ->set('anc.danger_signs', ['severe_headache'])
            ->call('saveAnc')->assertHasNoErrors();

        $visit = AncVisit::firstOrFail();
        $this->assertSame(1, $visit->visit_number);
        $this->assertSame(20, $visit->gestation_weeks);
        $p->refresh();
        $this->assertSame('high', $p->risk_level);
        $this->assertTrue(collect($p->risk_factors)->contains(fn ($f) => str_contains($f, 'Pre-eclampsia')));
        $this->assertTrue(collect($p->risk_factors)->contains(fn ($f) => str_contains($f, 'Anaemia')));

        Livewire::test('pages::maternity.pregnancy', ['pregnancy' => $p])->set('anc.systolic', '110')->set('anc.diastolic', '70')->call('saveAnc');
        $this->assertSame(2, AncVisit::max('visit_number'));
    }

    public function test_partograph_alert_and_action_lines(): void
    {
        $p = $this->registered();
        Carbon::setTestNow(now()->startOfMinute());
        $labour = app(MaternityService::class)->startLabour($p, []);
        $service = app(MaternityService::class);

        $service->recordPartograph($labour, ['recorded_at' => now(), 'cervix_cm' => 4, 'fetal_heart_rate' => 140]);
        Carbon::setTestNow(now()->addHours(2));
        $service->recordPartograph($labour, ['recorded_at' => now(), 'cervix_cm' => 6]);
        $a = $service->partographAlerts($labour->fresh());
        $this->assertTrue($a['active_phase']);
        $this->assertFalse($a['alert']);

        Carbon::setTestNow(now()->addHours(3)); // 5 h since 4 cm: alert line expects 9 cm
        $service->recordPartograph($labour, ['recorded_at' => now(), 'cervix_cm' => 6, 'fetal_heart_rate' => 100, 'liquor' => 'M']);
        $a = $service->partographAlerts($labour->fresh());
        $this->assertTrue($a['alert']);
        $this->assertFalse($a['action']); // action line expects 5 cm at 5 h
        $this->assertCount(2, $a['critical']);
        $this->assertSame('ruptured', $labour->fresh()->membranes);

        Carbon::setTestNow(now()->addHours(2)); // 7 h: action line expects 7 cm
        $service->recordPartograph($labour, ['recorded_at' => now(), 'cervix_cm' => 6, 'fetal_heart_rate' => 140, 'liquor' => 'C']);
        $this->assertTrue($service->partographAlerts($labour->fresh())['action']);

        // Dilatation cannot go backwards.
        $this->expectException(ValidationException::class);
        $service->recordPartograph($labour->fresh(), ['recorded_at' => now(), 'cervix_cm' => 5]);
    }

    public function test_full_labour_delivery_twins_newborn_registration_billing_and_pnc(): void
    {
        $p = $this->registered();

        Livewire::test('pages::maternity.pregnancy', ['pregnancy' => $p])
            ->set('tab', 'delivery')->set('labourForm.membranes', 'ruptured')
            ->call('startLabour')->assertHasNoErrors();

        $labour = Labour::firstOrFail();
        $this->assertSame('in_labour', $labour->status);
        $this->assertSame(EncounterStage::ADMITTED, $labour->encounter->stage);
        $this->assertNotNull($labour->membranes_ruptured_at);

        // Cannot start a second labour.
        $this->expectsNoDoubleLabour($p);

        $page = Livewire::test('pages::maternity.labour', ['labour' => $labour])
            ->set('entry.cervix_cm', '5')->set('entry.fetal_heart_rate', '138')->set('entry.contractions_per_10', '3')
            ->call('record')->assertHasNoErrors();
        $page->call('record')->assertHasErrors(['entry']); // empty entry

        $page->call('addBaby')
            ->set('delivery.delivery_mode', 'svd')->set('delivery.blood_loss_ml', '650')
            ->set('babies.0.sex', 'female')->set('babies.0.birth_weight_g', '2300')->set('babies.0.apgar_1', '7')->set('babies.0.apgar_5', '9')
            ->set('babies.1.sex', 'male')->set('babies.1.birth_weight_g', '2600')->set('babies.1.apgar_1', '8')->set('babies.1.apgar_5', '9')
            ->call('deliver')->assertHasNoErrors();

        $labour->refresh();
        $this->assertSame('delivered', $labour->status);
        $this->assertSame('pph', $labour->mother_condition); // ≥500 ml auto-flagged
        $this->assertSame('delivered', $p->fresh()->status);

        $babies = Newborn::orderBy('birth_order')->get();
        $this->assertCount(2, $babies);
        $this->assertTrue($babies[0]->isLowBirthWeight());
        $baby = $babies[0]->baby;
        $this->assertNotNull($baby);
        $this->assertSame('newborn', $baby->category);
        $this->assertSame('Odhiambo', $baby->last_name);
        $this->assertSame(now()->toDateString(), $baby->date_of_birth->toDateString());

        $invoice = Invoice::where('encounter_id', $labour->encounter_id)->firstOrFail();
        $this->assertTrue($invoice->items()->where('description', 'Normal delivery (SVD)')->exists());

        // Discharge mother → billing.
        Livewire::test('pages::maternity.labour', ['labour' => $labour])->call('dischargeMother');
        $this->assertSame(EncounterStage::WAITING_BILLING, Encounter::find($labour->encounter_id)->stage);

        // PNC within 48 h for mother and baby 1.
        Livewire::test('pages::maternity.pregnancy', ['pregnancy' => $p->fresh()])
            ->set('tab', 'pnc')->set('pnc.timing', '48h')->set('pnc.newborn_id', (string) $babies[0]->id)
            ->set('pnc.uterus', 'involuting')->set('pnc.lochia', 'normal')->set('pnc.feeding', 'exclusive_breastfeeding')
            ->set('pnc.baby_weight_kg', '2.25')->set('pnc.family_planning_counselled', true)
            ->call('savePnc')->assertHasNoErrors();
        $this->assertSame(1, PncVisit::count());
    }

    protected function expectsNoDoubleLabour(Pregnancy $p): void
    {
        try {
            app(MaternityService::class)->startLabour($p->fresh(), []);
            $this->fail('A second labour should be refused.');
        } catch (ValidationException) {
            $this->assertSame(1, Labour::count());
        }
    }

    public function test_stillbirth_is_recorded_without_registering_a_patient_and_cs_needs_theatre(): void
    {
        $p = $this->registered();
        $labour = app(MaternityService::class)->startLabour($p, []);

        // Level 3 health centre: no theatre → caesarean refused.
        $this->facility->update(['level' => 3]);
        $this->as('midwife');
        Livewire::test('pages::maternity.labour', ['labour' => $labour])
            ->set('delivery.delivery_mode', 'caesarean')->call('deliver')->assertHasErrors(['delivery.delivery_mode']);

        $patients = Patient::count();
        Livewire::test('pages::maternity.labour', ['labour' => $labour])
            ->set('delivery.delivery_mode', 'svd')->set('babies.0.outcome', 'fresh_stillbirth')->set('babies.0.birth_weight_g', '3000')
            ->call('deliver')->assertHasNoErrors();
        $this->assertSame($patients, Patient::count());
        $this->assertNull(Newborn::firstOrFail()->baby_patient_id);
    }

    public function test_labour_can_be_referred_and_pregnancy_closed(): void
    {
        $p = $this->registered();
        $labour = app(MaternityService::class)->startLabour($p, []);

        Livewire::test('pages::maternity.labour', ['labour' => $labour])
            ->set('refer_reason', 'Obstructed labour, needs C/S')->call('refer')
            ->assertRedirect(route('referrals.create', ['direction' => 'outgoing', 'encounter' => $labour->encounter_id]));
        $this->assertSame('referred', $labour->fresh()->status);

        $mother = $this->mother(['first_name' => 'Chebet', 'phone' => '+254711000555']);
        $this->as('midwife');
        $p2 = app(MaternityService::class)->registerPregnancy($mother, ['lmp' => now()->subWeeks(9)->toDateString()]);
        Livewire::test('pages::maternity.pregnancy', ['pregnancy' => $p2])->set('close_outcome', 'miscarriage')->call('closePregnancy');
        $this->assertSame('closed', $p2->fresh()->status);
        $this->assertSame('miscarriage', $p2->fresh()->outcome);
    }

    public function test_pages_render_and_are_permission_gated(): void
    {
        $p = $this->registered();
        $labour = app(MaternityService::class)->startLabour($p, []);

        foreach ([route('maternity.index'), route('maternity.register'), route('maternity.pregnancy', $p), route('labour.index'), route('labour.show', $labour)] as $url) {
            $this->get($url)->assertOk();
        }

        $this->as('cashier');
        $this->get(route('maternity.index'))->assertForbidden();
        $this->get(route('labour.show', $labour))->assertForbidden();
    }
}
