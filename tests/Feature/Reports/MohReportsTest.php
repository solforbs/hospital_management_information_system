<?php

namespace Tests\Feature\Reports;

use App\Models\Diagnosis;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Clinical\EncounterService;
use App\Services\Maternity\MaternityService;
use App\Services\Patients\PatientRegistrationService;
use App\Services\Reports\MohReportService;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantProvisioningService;
use App\Support\RoleCatalog;
use Database\Seeders\TenantCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class MohReportsTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = app(TenantProvisioningService::class)->provision(['name' => 'MOH Hospital'], ['name' => 'Main', 'code' => 'MAIN', 'level' => 4]);
        app(TenantCatalogSeeder::class)->seedFor($this->tenant);
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $this->tenant->users()->attach($admin, ['status' => 'active', 'is_default' => true, 'joined_at' => now()]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $admin->assignRole(RoleCatalog::HOSPITAL_ADMINISTRATOR);
        $context = app(TenantContext::class);
        $context->set($this->tenant);
        $context->setFacility($this->tenant->facilities()->first());
        $this->actingAs($admin);
    }

    protected function patient(string $dob, string $sex = 'female', string $first = 'A')
    {
        return app(PatientRegistrationService::class)->register($this->tenant, ['first_name' => $first, 'last_name' => 'Test', 'sex' => $sex, 'date_of_birth' => $dob], $this->tenant->facilities()->first());
    }

    protected function diagnose($patient, string $code): void
    {
        $e = app(EncounterService::class)->checkIn($patient);
        Diagnosis::create(['tenant_id' => $this->tenant->id, 'patient_id' => $patient->id, 'encounter_id' => $e->id, 'code' => $code, 'code_system' => 'icd11', 'description' => $code, 'type' => 'primary']);
        app(EncounterService::class)->cancel($e, 'test');
    }

    public function test_705_groups_icd11_codes_by_age_and_day(): void
    {
        $this->diagnose($this->patient(now()->subYears(2)->toDateString(), first: 'Child'), '1F40');
        $this->diagnose($this->patient('1980-01-01', first: 'Adult1'), '1F4Z');
        $this->diagnose($this->patient('1975-01-01', first: 'Adult2'), 'BA00');
        $this->diagnose($this->patient('1970-01-01', first: 'Adult3'), 'QA00');

        $service = app(MohReportService::class);
        $month = now()->format('Y-m');
        $day = (int) now()->format('j');

        $a = $service->build('705A', $month);
        $this->assertSame(1, $a['rows']['malaria']['total']);
        $this->assertSame(1, $a['rows']['malaria']['days'][$day]);

        $b = $service->build('705B', $month);
        $this->assertSame(1, $b['rows']['malaria']['total']);
        $this->assertSame(1, $b['rows']['hypertension']['total']);
        $this->assertSame(1, $b['rows']['other']['total']);
        $this->assertSame(3, $b['footer']['value']);
    }

    public function test_717_405_333_and_dhis2_export(): void
    {
        $mother = $this->patient('1995-01-01', first: 'Mama');
        $m = app(MaternityService::class);
        $p = $m->registerPregnancy($mother, ['lmp' => now()->subWeeks(38)->toDateString(), 'hiv_status' => 'negative']);
        $m->recordAncVisit($p, ['hb' => 10.2, 'iptp_given' => true]);
        $labour = $m->startLabour($p->fresh(), []);
        $m->recordDelivery($labour, ['delivery_mode' => 'svd', 'amtsl' => true, 'blood_loss_ml' => 300], [['sex' => 'male', 'birth_weight_g' => 2400, 'apgar_5' => 9]]);

        $service = app(MohReportService::class);
        $month = now()->format('Y-m');

        $anc = $service->flatten($service->build('405', $month))->keyBy(0);
        $this->assertSame(1, $anc['anc_new'][2]);
        $this->assertSame(1, $anc['iptp'][2]);
        $this->assertSame(1, $anc['anaemia'][2]);
        $this->assertSame(1, $anc['hiv_tested'][2]);

        $mat = $service->flatten($service->build('333', $month))->keyBy(0);
        $this->assertSame(1, $mat['deliveries'][2]);
        $this->assertSame(1, $mat['live_births'][2]);
        $this->assertSame(1, $mat['lbw'][2]);

        $workload = $service->flatten($service->build('717', $month))->keyBy(0);
        $this->assertSame(1, $workload['deliveries'][2]);
        $this->assertSame(1, $workload['anc_visits'][2]);

        config(['moh.data_elements.333' => ['deliveries' => 'UidDeliv001']]);
        $dv = $service->dhis2('333', $service->build('333', $month), 'OrgUnit123');
        $this->assertSame(str_replace('-', '', $month), $dv['period']);
        $this->assertSame('UidDeliv001', collect($dv['dataValues'])->firstWhere('dataElement', 'UidDeliv001')['dataElement']);
        $this->assertSame('OrgUnit123', $dv['dataValues'][0]['orgUnit']);

        Livewire::test('pages::reports.moh')->set('report', '333')->set('month', $month)->assertSee('Live births')
            ->call('exportCsv')->assertFileDownloaded("MOH333-{$month}.csv");
        Livewire::test('pages::reports.moh')->set('report', '705B')->set('month', $month)->assertSee('All other diseases');
    }
}
