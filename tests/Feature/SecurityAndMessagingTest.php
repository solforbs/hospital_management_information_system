<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\IntegrationCredential;
use App\Models\MpesaRequest;
use App\Models\Patient;
use App\Models\SmsMessage;
use App\Models\SupportAccessGrant;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Clinical\EncounterService;
use App\Services\Messaging\SmsService;
use App\Services\Patients\PatientRegistrationService;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantProvisioningService;
use App\Support\RoleCatalog;
use Database\Seeders\TenantCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SecurityAndMessagingTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = app(TenantProvisioningService::class)->provision(['name' => 'Secure Hospital'], ['name' => 'Main', 'code' => 'MAIN']);
        app(TenantCatalogSeeder::class)->seedFor($this->tenant);
        $this->admin = User::factory()->create(['email_verified_at' => now()]);
        $this->tenant->users()->attach($this->admin, ['status' => 'active', 'is_default' => true, 'joined_at' => now()]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->admin->assignRole(RoleCatalog::HOSPITAL_ADMINISTRATOR);
        $this->login();
    }

    protected function login(): void
    {
        $c = app(TenantContext::class);
        $c->set($this->tenant->fresh());
        $c->setFacility($this->tenant->facilities()->first());
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->actingAs($this->admin);
    }

    protected function patient(array $o = []): Patient
    {
        return app(PatientRegistrationService::class)->register($this->tenant, $o + ['first_name' => 'Halima', 'last_name' => 'Abdi', 'sex' => 'female', 'date_of_birth' => '1991-03-03', 'phone' => '0712345678'], $this->tenant->facilities()->first());
    }

    public function test_identifiers_are_encrypted_at_rest_and_still_searchable(): void
    {
        $p = $this->patient(['national_id' => '30111222']);
        $p->update(['sha_number' => 'SHA123']);

        $raw = DB::table('patients')->where('id', $p->id)->first();
        $this->assertNotSame('30111222', $raw->national_id);
        $this->assertNotSame('SHA123', $raw->sha_number);
        $this->assertSame('30111222', $p->fresh()->national_id);

        $this->assertSame($p->id, Patient::whereNationalId('30 111 222')->value('id'));
        $this->assertSame($p->id, Patient::search('30111222')->value('id'));

        Livewire::test('pages::patients.index')->set('search', '30111222')->assertSee('Halima');

        // Duplicate detection still works on the encrypted ID.
        $dups = app(PatientRegistrationService::class)->findPossibleDuplicates(['national_id' => '30111222', 'first_name' => 'X', 'last_name' => 'Y']);
        $this->assertTrue($dups->contains('id', $p->id));
    }

    public function test_live_endpoint_reports_channel_changes(): void
    {
        $before = $this->getJson(route('live', ['c' => 'queue,lab']))->assertOk()->json();
        $this->travel(5)->seconds();
        app(EncounterService::class)->checkIn($this->patient());
        $after = $this->getJson(route('live', ['c' => 'queue,lab,bogus']))->json();

        $this->assertGreaterThan($before['queue'], $after['queue']);
        $this->assertSame($before['lab'], $after['lab']);
        $this->assertArrayNotHasKey('bogus', $after);
    }

    public function test_sms_reminders_and_number_normalisation(): void
    {
        $this->assertSame('+254712345678', SmsService::normalise('0712 345 678'));
        $this->assertSame('+254112345678', SmsService::normalise('+254112345678'));
        $this->assertNull(SmsService::normalise('12345'));

        IntegrationCredential::create(['tenant_id' => $this->tenant->id, 'provider' => 'sms', 'environment' => 'simulator', 'config' => [], 'enabled' => true]);
        $p = $this->patient();
        Appointment::create(['tenant_id' => $this->tenant->id, 'patient_id' => $p->id, 'appointment_number' => 'APT-1', 'scheduled_at' => now()->addDay()->setTime(10, 0), 'status' => 'booked', 'reason' => 'Review']);

        $this->artisan('hms:send-reminders')->assertSuccessful();
        $this->artisan('hms:send-reminders')->assertSuccessful(); // idempotent per day

        $this->login();
        $sms = SmsMessage::where('purpose', 'appointment_reminder')->get();
        $this->assertCount(1, $sms);
        $this->assertSame('sent', $sms->first()->status);
        $this->assertSame('+254712345678', $sms->first()->to);
    }

    public function test_mpesa_stk_push_posts_payment_once(): void
    {
        IntegrationCredential::create(['tenant_id' => $this->tenant->id, 'provider' => 'mpesa', 'environment' => 'simulator', 'config' => ['callback_token' => 'tok123'], 'enabled' => true]);
        $p = $this->patient();
        $enc = app(EncounterService::class)->checkIn($p);
        $billing = app(BillingService::class);
        $billing->chargeService($enc, 'CONSULT');
        $invoice = $billing->invoiceFor($enc)->fresh();

        Livewire::test('pages::billing.invoice', ['invoice' => $invoice])->set('mpesa_phone', '0712345678')->call('mpesaPrompt')->assertHasNoErrors();

        $req = MpesaRequest::firstOrFail();
        $this->assertSame('success', $req->status);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('mpesa', $invoice->payments()->first()->method);

        // A replayed callback does not double-post.
        $payload = ['Body' => ['stkCallback' => ['CheckoutRequestID' => $req->checkout_request_id, 'ResultCode' => 0, 'CallbackMetadata' => ['Item' => [['Name' => 'Amount', 'Value' => 1000], ['Name' => 'MpesaReceiptNumber', 'Value' => 'X1']]]]]];
        $this->postJson(route('hooks.mpesa', [$this->tenant->slug, 'tok123']), $payload)->assertOk();
        $this->postJson(route('hooks.mpesa', [$this->tenant->slug, 'wrong']), $payload)->assertNotFound();
        $this->assertSame(1, $invoice->payments()->count());
    }

    public function test_ip_allow_list_and_idle_timeout(): void
    {
        $this->tenant->update(['settings' => ['security' => ['allowed_ips' => ['10.0.0.0/8'], 'idle_minutes' => null]]]);
        $this->login();
        $this->get(route('dashboard'))->assertForbidden();
        $this->withServerVariables(['REMOTE_ADDR' => '10.1.2.3'])->get(route('dashboard'))->assertOk();

        $this->tenant->update(['settings' => ['security' => ['allowed_ips' => [], 'idle_minutes' => 15]]]);
        $this->login();
        $this->withSession(['last_activity_at' => time() - 20 * 60])->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();

        // An admin cannot save a list that excludes their own address.
        $this->login();
        Livewire::test('pages::admin.institution')->set('tab', 'security')->set('sec_ips', '192.168.50.0/24')->call('saveSecurity')->assertHasErrors(['sec_ips']);
        Livewire::test('pages::admin.institution')->set('sec_ips', 'not-an-ip')->call('saveSecurity')->assertHasErrors(['sec_ips']);
    }

    public function test_break_glass_support_access_is_read_only_audited_and_expires(): void
    {
        $platform = User::factory()->create(['email_verified_at' => now(), 'is_platform_admin' => true]);
        app(TenantContext::class)->clear();
        $this->actingAs($platform);

        // No grant: no access to the hospital.
        $this->get(route('patients.index'))->assertForbidden();

        Livewire::test('pages::platform.tenants')->set('supportTenantId', $this->tenant->id)
            ->set('support_reason', 'Investigating a reported billing error')->set('support_minutes', '30')
            ->call('startSupport')->assertHasNoErrors();

        $grant = SupportAccessGrant::firstOrFail();
        $this->assertTrue($grant->isActive());
        $this->assertTrue(AuditLog::withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->where('action', 'platform.support_access_granted')->exists());

        app(TenantContext::class)->clear();
        $this->withSession(['support_tenant_id' => $this->tenant->id])->get(route('patients.index'))->assertOk();
        app(TenantContext::class)->clear();
        $this->withSession(['support_tenant_id' => $this->tenant->id])->get(route('patients.create'))->assertForbidden();
        app(TenantContext::class)->clear();
        $this->withSession(['support_tenant_id' => $this->tenant->id])->get(route('admin.roles'))->assertForbidden();

        $this->travel(31)->minutes();
        app(TenantContext::class)->clear();
        $this->withSession(['support_tenant_id' => $this->tenant->id])->get(route('patients.index'))->assertForbidden();
    }
}
