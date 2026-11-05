<?php

namespace Tests\Feature;

use App\Models\LabGateway;
use App\Models\LabInstrument;
use App\Models\LabInstrumentMessage;
use App\Models\LabOrderItem;
use App\Models\LabTest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Clinical\EncounterService;
use App\Services\Diagnostics\LaboratoryService;
use App\Services\Patients\PatientRegistrationService;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantProvisioningService;
use App\Support\Code128;
use App\Support\RoleCatalog;
use Database\Seeders\TenantCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class LabInstrumentTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected User $manager;

    protected string $token;

    protected LabInstrument $haem;

    protected LabInstrument $chem;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->hospital('Nakuru Level 5 Hospital');
        $this->manager = $this->member($this->tenant, RoleCatalog::LABORATORY_MANAGER);
        $this->login($this->tenant, $this->manager);

        // Set up through the admin page, as a lab manager would.
        $page = Livewire::test('pages::lab.instruments')->set('gw.name', 'Main lab PC')->call('addGateway');
        $this->token = $page->get('newToken');

        $page->call('newInstrument')->set('inst.name', 'Mindray BC-30s')->set('inst.code', 'BC30')->set('inst.protocol', 'hl7')->set('inst.bidirectional', true)->call('saveInstrument')->assertHasNoErrors()
            ->set('preset', 'haematology-3')->call('applyPreset');
        $page->call('newInstrument')->set('inst.name', 'Chemistry analyser')->set('inst.code', 'CHEM1')->set('inst.protocol', 'astm')->call('saveInstrument')->assertHasNoErrors()
            ->set('preset', 'chemistry')->call('applyPreset');

        $this->haem = LabInstrument::where('code', 'BC30')->firstOrFail();
        $this->chem = LabInstrument::where('code', 'CHEM1')->firstOrFail();
    }

    protected function hospital(string $name): Tenant
    {
        $tenant = app(TenantProvisioningService::class)->provision(['name' => $name], ['name' => 'Main', 'code' => 'MAIN']);
        app(TenantCatalogSeeder::class)->seedFor($tenant);

        return $tenant;
    }

    protected function member(Tenant $tenant, string $role): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $tenant->users()->attach($user, ['status' => 'active', 'is_default' => true, 'joined_at' => now()]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $user->assignRole($role);

        return $user;
    }

    protected function login(Tenant $tenant, User $user): void
    {
        $c = app(TenantContext::class);
        $c->set($tenant->fresh());
        $c->setFacility($tenant->facilities()->first());
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $this->actingAs($user);
    }

    /** Orders tests for a new patient and collects the samples; returns items keyed by test code. */
    protected function collectedSamples(array $codes): array
    {
        $patient = app(PatientRegistrationService::class)->register($this->tenant, ['first_name' => 'Akinyi', 'last_name' => 'Otieno', 'sex' => 'female', 'date_of_birth' => '1990-05-01'], $this->tenant->facilities()->first());
        $encounter = app(EncounterService::class)->checkIn($patient);
        $lab = app(LaboratoryService::class);
        $order = $lab->order($encounter, LabTest::whereIn('code', $codes)->pluck('id')->all());

        return $order->items()->with('test')->get()->mapWithKeys(fn ($i) => [$i->test->code => $lab->collect($i)->fresh()])->all();
    }

    protected function api(): array
    {
        return ['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json'];
    }

    public function test_gateway_token_is_shown_once_and_authenticates(): void
    {
        $this->assertStringStartsWith('hgw_', $this->token);
        $this->assertNotSame($this->token, LabGateway::first()->token_hash, 'only the hash is stored');

        $this->postJson('/api/lis/v1/heartbeat', ['version' => '1.0.0'], $this->api())->assertOk()
            ->assertJsonPath('gateway.hospital', 'Nakuru Level 5 Hospital')
            ->assertJsonPath('instruments.0.code', 'BC30')
            ->assertJsonFragment(['code' => 'HGB', 'test' => 'FBC', 'component' => 'Haemoglobin']);
        $this->assertSame('1.0.0', LabGateway::first()->version);
        $this->assertNotNull(LabGateway::first()->last_seen_at);

        $this->postJson('/api/lis/v1/heartbeat', [], ['Authorization' => 'Bearer hgw_wrong'])->assertUnauthorized();
        $this->postJson('/api/lis/v1/heartbeat')->assertUnauthorized();

        $this->tenant->update(['status' => 'suspended']);
        $this->postJson('/api/lis/v1/heartbeat', [], $this->api())->assertUnauthorized();
    }

    public function test_bidirectional_analyser_gets_its_worklist(): void
    {
        $samples = $this->collectedSamples(['FBC', 'RBS']);

        $orders = $this->getJson('/api/lis/v1/orders?instrument=BC30', $this->api())->assertOk()->json('orders');
        $this->assertCount(1, $orders, 'only tests mapped to this analyser');
        $this->assertSame($samples['FBC']->specimen_number, $orders[0]['sample_id']);
        $this->assertSame('Otieno', $orders[0]['patient']['last_name']);
        $this->assertSame('F', $orders[0]['patient']['sex']);
        $this->assertContains('HGB', $orders[0]['instrument_codes']);

        // Host query by barcode.
        $this->getJson('/api/lis/v1/orders/'.$samples['FBC']->specimen_number.'?instrument=BC30', $this->api())->assertOk()->assertJsonPath('orders.0.test.code', 'FBC');

        // A one-way analyser gets no worklist; unknown instruments 404.
        $this->getJson('/api/lis/v1/orders?instrument=CHEM1', $this->api())->assertOk()->assertJsonPath('orders', []);
        $this->getJson('/api/lis/v1/orders?instrument=NOPE', $this->api())->assertNotFound();
    }

    public function test_fbc_results_arrive_as_components_and_still_need_verification(): void
    {
        $fbc = $this->collectedSamples(['FBC'])['FBC'];

        $this->postJson('/api/lis/v1/results', [
            'instrument' => 'BC30',
            'sample_id' => $fbc->specimen_number,
            'results' => [
                ['code' => 'WBC', 'value' => '6.2', 'unit' => '10^9/L', 'reference_range' => '4.0-10.0'],
                ['code' => 'HGB', 'value' => '18.9', 'unit' => 'g/dL', 'flag' => 'H', 'reference_range' => '12.0-16.0'],
                ['code' => 'PLT', 'value' => '250', 'unit' => '10^9/L', 'reference_range' => '150-400'],
                ['code' => 'XYZ', 'value' => '1'],
            ],
            'raw' => 'MSH|^~\\&|BC-30s|...',
        ], $this->api())->assertOk()->assertJsonPath('status', 'partial')->assertJsonPath('unmapped', ['XYZ']);

        $item = $fbc->fresh(['components']);
        $this->assertSame('resulted', $item->status, 'never auto-verified');
        $this->assertNull($item->resulted_by);
        $this->assertSame($this->haem->id, $item->resulted_by_instrument_id);
        $this->assertSame('high', $item->flag);
        $this->assertSame(['WBC', 'Haemoglobin', 'Platelets'], $item->components->pluck('name')->all());
        $this->assertSame('high', $item->components->firstWhere('name', 'Haemoglobin')->flag);
        $this->assertSame('normal', $item->components->firstWhere('name', 'WBC')->flag, 'flag computed from range');
        $this->assertSame('partial', LabInstrumentMessage::where('direction', 'in')->latest('id')->first()->status);

        // The worklist shows the analyser; the lab manager may verify (no person entered it).
        Livewire::test('pages::lab.worklist')->set('status', 'resulted')->assertSee('Mindray BC-30s')->assertSee('Haemoglobin')
            ->call('verifyAndRelease', $item->id);
        $this->assertSame('released', $item->fresh()->status);
        $this->assertSame($this->manager->id, $item->fresh()->verified_by);

        $this->get(route('print.lab', $item->lab_order_id))->assertOk()->assertSee('Haemoglobin')->assertSee('18.9')->assertSee('Mindray BC-30s');

        // Once verified, the analyser cannot change it.
        $this->postJson('/api/lis/v1/results', ['instrument' => 'BC30', 'sample_id' => $fbc->specimen_number, 'results' => [['code' => 'HGB', 'value' => '9.0']]], $this->api())
            ->assertStatus(202)->assertJsonPath('status', 'rejected');
        $this->assertSame('18.9', $item->components()->where('name', 'Haemoglobin')->value('value'));
    }

    public function test_single_analyte_uses_the_ordered_test(): void
    {
        $rbs = $this->collectedSamples(['RBS'])['RBS'];

        $this->postJson('/api/lis/v1/results', ['instrument' => 'CHEM1', 'sample_id' => $rbs->specimen_number, 'results' => [['code' => 'glu', 'value' => '12.4', 'unit' => 'mmol/L']]], $this->api())
            ->assertOk()->assertJsonPath('status', 'accepted');

        $item = $rbs->fresh();
        $this->assertSame('12.4', $item->result_value);
        $this->assertSame('high', $item->flag, 'flag from the test reference range');
        $this->assertSame(0, $item->components()->count());

        // A re-run before verification replaces the value.
        $this->postJson('/api/lis/v1/results', ['instrument' => 'CHEM1', 'sample_id' => $rbs->specimen_number, 'results' => [['code' => 'GLU', 'value' => '6.1']]], $this->api())->assertOk();
        $this->assertSame('6.1', $item->fresh()->result_value);
        $this->assertSame('normal', $item->fresh()->flag);

        // A person who overrides it becomes the one who resulted it.
        app(LaboratoryService::class)->enterResult($item->fresh(), '6.3');
        $this->assertNull($item->fresh()->resulted_by_instrument_id);
    }

    public function test_unknown_samples_and_unmapped_codes_are_logged_not_lost(): void
    {
        $this->postJson('/api/lis/v1/results', ['instrument' => 'BC30', 'sample_id' => 'SPM-999999', 'results' => [['code' => 'HGB', 'value' => '12']]], $this->api())
            ->assertStatus(202)->assertJsonPath('status', 'unmatched');

        $rbs = $this->collectedSamples(['RBS'])['RBS'];
        $this->postJson('/api/lis/v1/results', ['instrument' => 'BC30', 'sample_id' => $rbs->specimen_number, 'results' => [['code' => 'HGB', 'value' => '12']]], $this->api())
            ->assertStatus(202)->assertJsonPath('status', 'unmatched');
        $this->assertSame('collected', $rbs->fresh()->status);

        $this->assertSame(2, LabInstrumentMessage::where('status', 'unmatched')->count());
        Livewire::test('pages::lab.instruments')->assertSee('SPM-999999')->assertSee('unmatched');

        $this->postJson('/api/lis/v1/results', ['instrument' => 'BC30', 'sample_id' => 'X', 'results' => []], $this->api())->assertUnprocessable();
    }

    public function test_a_gateway_cannot_reach_another_hospitals_samples(): void
    {
        $fbc = $this->collectedSamples(['FBC'])['FBC'];

        $other = $this->hospital('Another Hospital');
        $this->login($other, $this->member($other, RoleCatalog::LABORATORY_MANAGER));
        $page = Livewire::test('pages::lab.instruments')->set('gw.name', 'Their PC')->call('addGateway');
        $theirToken = $page->get('newToken');
        $page->call('newInstrument')->set('inst.name', 'Their BC-30')->set('inst.code', 'BC30')->set('inst.protocol', 'hl7')->set('inst.bidirectional', true)->call('saveInstrument')->set('preset', 'haematology-3')->call('applyPreset');
        app(TenantContext::class)->clear();

        $h = ['Authorization' => 'Bearer '.$theirToken, 'Accept' => 'application/json'];
        $this->getJson('/api/lis/v1/orders?instrument=BC30', $h)->assertOk()->assertJsonPath('orders', []);
        $this->postJson('/api/lis/v1/results', ['instrument' => 'BC30', 'sample_id' => $fbc->specimen_number, 'results' => [['code' => 'HGB', 'value' => '5']]], $h)
            ->assertStatus(202)->assertJsonPath('status', 'unmatched');

        app(TenantContext::class)->set($this->tenant->fresh());
        $this->assertSame('collected', LabOrderItem::find($fbc->id)->status);
    }

    public function test_specimen_label_has_a_valid_barcode(): void
    {
        $fbc = $this->collectedSamples(['FBC'])['FBC'];

        $this->get(route('print.specimen-label', $fbc))->assertOk()
            ->assertSee($fbc->specimen_number)->assertSee('Otieno, Akinyi')->assertSee('<svg', false);

        // Code 128 table sanity: 106 symbols of 11 modules + 13-module stop; known checksum.
        foreach (Code128::PATTERNS as $i => $p) {
            $this->assertSame($i === 106 ? 13 : 11, array_sum(str_split($p)), "pattern $i");
        }
        $this->assertSame([104, 51, 48, 45, 13, 16, 16, 16, 17, 18, 19, 81, 106], Code128::values('SPM-000123'));
    }

    public function test_only_lab_managers_manage_analysers(): void
    {
        $this->login($this->tenant, $this->member($this->tenant, RoleCatalog::LABORATORY_TECHNICIAN));
        $this->get(route('lab.instruments'))->assertForbidden();

        $this->login($this->tenant, $this->manager);
        $this->get(route('lab.instruments'))->assertOk()->assertSee('Mindray BC-30s');
    }
}
