<?php

namespace Tests\Feature\Workflow;

use App\Models\Diagnosis;
use App\Models\Encounter;
use App\Models\Immunization;
use App\Models\InventoryItem;
use App\Models\Patient;
use App\Models\Refund;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Clinical\EncounterService;
use App\Services\Patients\PatientMergeService;
use App\Services\Patients\PatientRegistrationService;
use App\Services\Stores\StoresService;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantProvisioningService;
use App\Support\RoleCatalog;
use Database\Seeders\TenantCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class OperationsTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected array $users = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = app(TenantProvisioningService::class)->provision(['name' => 'Ops Hospital'], ['name' => 'Main', 'code' => 'MAIN']);
        app(TenantCatalogSeeder::class)->seedFor($this->tenant);

        foreach (['admin' => RoleCatalog::HOSPITAL_ADMINISTRATOR, 'buyer' => RoleCatalog::PROCUREMENT_OFFICER, 'buyer2' => RoleCatalog::PROCUREMENT_OFFICER, 'cashier' => RoleCatalog::CASHIER] as $k => $role) {
            $u = User::factory()->create(['email_verified_at' => now()]);
            $this->tenant->users()->attach($u, ['status' => 'active', 'is_default' => true, 'joined_at' => now()]);
            app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
            $u->assignRole($role);
            $this->users[$k] = $u;
        }
        $this->as('admin');
    }

    protected function as(string $k): void
    {
        $c = app(TenantContext::class);
        $c->set($this->tenant);
        $c->setFacility($this->tenant->facilities()->first());
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($this->users[$k]->fresh());
    }

    protected function patient(array $o = []): Patient
    {
        return app(PatientRegistrationService::class)->register($this->tenant, $o + ['first_name' => 'Kamau', 'last_name' => 'Njoroge', 'sex' => 'male', 'date_of_birth' => '1988-08-08'], $this->tenant->facilities()->first());
    }

    public function test_merge_moves_all_records_and_hides_the_duplicate(): void
    {
        $keep = $this->patient(['national_id' => '11111111']);
        $dup = $this->patient(['phone' => '0722000000', 'county' => 'Nyeri']);
        $enc = app(EncounterService::class)->checkIn($dup);
        Diagnosis::create(['tenant_id' => $this->tenant->id, 'patient_id' => $dup->id, 'encounter_id' => $enc->id, 'code' => 'BA00', 'description' => 'HTN']);
        Immunization::create(['tenant_id' => $this->tenant->id, 'patient_id' => $dup->id, 'vaccine' => 'bcg', 'given_on' => '1988-08-08']);
        Immunization::create(['tenant_id' => $this->tenant->id, 'patient_id' => $keep->id, 'vaccine' => 'bcg', 'given_on' => '1988-08-08']);

        Livewire::test('pages::patients.merge', ['keep' => $keep->id, 'duplicate' => $dup->id])
            ->set('keep', $keep->id)->set('duplicate', $dup->id)
            ->set('reason', 'Same person registered twice')
            ->call('merge')->assertHasNoErrors();

        $this->assertSame($keep->id, Encounter::find($enc->id)->patient_id);
        $this->assertSame(1, Diagnosis::where('patient_id', $keep->id)->count());
        $this->assertSame(1, Immunization::where('patient_id', $keep->id)->count());
        $this->assertSame('0722000000', $keep->fresh()->phone);
        $this->assertSame('Nyeri', $keep->fresh()->county);
        $this->assertNull(Patient::find($dup->id));
        $this->assertSame($keep->id, Patient::withoutGlobalScope('not_merged')->find($dup->id)->merged_into_id);

        // Different sexes are refused.
        $woman = $this->patient(['sex' => 'female', 'first_name' => 'Wanjiru']);
        $this->expectException(ValidationException::class);
        app(PatientMergeService::class)->merge($keep->fresh(), $woman, 'test');
    }

    public function test_role_editor_changes_permissions_and_prevents_lockout(): void
    {
        $cashierRole = Role::where('tenant_id', $this->tenant->id)->where('name', RoleCatalog::CASHIER)->firstOrFail();
        $adminRole = Role::where('tenant_id', $this->tenant->id)->where('name', RoleCatalog::HOSPITAL_ADMINISTRATOR)->firstOrFail();

        Livewire::test('pages::admin.roles')->call('select', $cashierRole->id)
            ->set('granted', ['billing.payments.receive', 'billing.invoices.view', 'reports.view'])
            ->call('save')->assertHasNoErrors();
        $this->assertTrue($cashierRole->fresh()->hasPermissionTo('reports.view'));
        $this->assertFalse($cashierRole->fresh()->hasPermissionTo('billing.invoices.create'));

        Livewire::test('pages::admin.roles')->call('select', $adminRole->id)
            ->set('granted', ['patients.view'])->call('save')->assertHasErrors(['granted']);
        $this->assertTrue($adminRole->fresh()->hasPermissionTo('roles.manage'));

        Livewire::test('pages::admin.roles')->set('newRole', 'Physiotherapist')->set('copyFrom', '')->call('createRole')->assertHasNoErrors()
            ->set('granted', ['clinics.manage', 'patients.view', 'tenants.manage'])->call('save');
        $physio = Role::where('tenant_id', $this->tenant->id)->where('name', 'Physiotherapist')->firstOrFail();
        $this->assertTrue($physio->hasPermissionTo('clinics.manage'));
        $this->assertFalse($physio->hasPermissionTo('tenants.manage'));

        $this->as('cashier');
        $this->get(route('admin.roles'))->assertForbidden();
    }

    public function test_refund_reopens_balance_and_is_capped(): void
    {
        $p = $this->patient();
        $enc = app(EncounterService::class)->checkIn($p);
        $billing = app(BillingService::class);
        $billing->chargeService($enc, 'CONSULT');
        $invoice = $billing->invoiceFor($enc)->fresh();
        $payment = $billing->receivePayment($invoice, (float) $invoice->balance, 'cash');
        $this->assertSame('paid', $invoice->fresh()->status);

        Livewire::test('pages::billing.invoice', ['invoice' => $invoice->fresh()])
            ->set('refundingId', $payment->id)->set('refund_amount', '99999')->set('refund_reason', 'Service not given')
            ->call('refund')->assertHasErrors(['refund_amount'])
            ->set('refund_amount', '500')->call('refund')->assertHasNoErrors();

        $invoice->refresh();
        $this->assertSame(500.0, (float) $invoice->balance);
        $this->assertSame('partially_paid', $invoice->status);
        $refund = Refund::firstOrFail();
        $this->get(route('billing.refund', $refund))->assertOk()->assertSee('REFUND VOUCHER');

        $this->as('cashier');
        Livewire::test('pages::billing.invoice', ['invoice' => $invoice])->set('refundingId', $payment->id)->set('refund_amount', '100')->set('refund_reason', 'x reason')->call('refund')->assertForbidden();
    }

    public function test_purchasing_needs_second_approver_and_receipts_update_stock(): void
    {
        $item = InventoryItem::create(['tenant_id' => $this->tenant->id, 'code' => 'GLV', 'name' => 'Examination gloves', 'category' => 'consumables', 'unit' => 'box', 'reorder_level' => 10]);
        $supplier = Supplier::create(['tenant_id' => $this->tenant->id, 'name' => 'Medisupplies Ltd']);
        $stores = app(StoresService::class);

        $this->as('buyer');
        $po = $stores->createPurchaseOrder($supplier->id, [['inventory_item_id' => $item->id, 'quantity' => 50, 'unit_cost' => 450]]);
        $this->assertSame(22500.0, (float) $po->total);

        try {
            $stores->approve($po);
            $this->fail('Creator must not approve own order.');
        } catch (ValidationException) {
            $this->assertSame('draft', $po->fresh()->status);
        }

        $this->as('buyer2');
        $stores->approve($po->fresh());

        $line = $po->lines()->first();
        $stores->receive($po->fresh('lines.item'), [$line->id => 30], 'DN-1');
        $this->assertSame('partially_received', $po->fresh()->status);
        $this->assertSame(30.0, (float) $item->fresh()->stock_on_hand);

        try {
            $stores->receive($po->fresh('lines.item'), [$line->id => 25], 'DN-2');
            $this->fail('Over-receipt must be refused.');
        } catch (ValidationException) {
        }

        $stores->receive($po->fresh('lines.item'), [$line->id => 20], 'DN-2');
        $this->assertSame('received', $po->fresh()->status);

        $stores->issue($item->fresh(), 45, $this->tenant->departments()->first()->id, 'Ward requisition');
        $this->assertTrue($item->fresh()->isLow());

        $this->expectException(ValidationException::class);
        $stores->issue($item->fresh(), 10, null, null);
    }

    public function test_pages_render(): void
    {
        foreach (['stores.index', 'admin.roles', 'patients.merge'] as $route) {
            $this->get(route($route))->assertOk();
        }
    }
}
