<?php

namespace Tests\Feature;

use App\Models\Signatory;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantProvisioningService;
use App\Support\RoleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Institution self-service profile: identity, contacts, logo/stamp,
 * document & payment settings, and signatories — tenant-isolated and
 * applied to printed documents.
 */
class InstitutionProfileTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected array $staff = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->tenant = app(TenantProvisioningService::class)->provision(['name' => 'Mwangaza Hospital'], ['name' => 'Main', 'code' => 'MAIN']);

        foreach (['admin' => RoleCatalog::HOSPITAL_ADMINISTRATOR, 'doctor' => RoleCatalog::DOCTOR] as $key => $role) {
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
        $context->set($this->tenant->fresh());
        $context->setFacility($this->tenant->facilities()->first());
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $user = $this->staff[$key]->fresh();
        $this->actingAs($user);

        return $user;
    }

    public function test_admin_updates_identity_contacts_and_documents(): void
    {
        $this->as('admin');

        Livewire::test('pages::admin.institution')
            ->set('profile.short_name', 'MH')
            ->set('profile.motto', 'Care with compassion')
            ->set('profile.license_number', 'KMPDC/F/1234')
            ->set('profile.kra_pin', 'p051234567x')
            ->set('profile.ownership', 'faith_based')
            ->call('saveProfile')->assertHasNoErrors()
            ->set('profile.kra_pin', 'BAD')->call('saveProfile')->assertHasErrors(['profile.kra_pin']);

        $t = $this->tenant->fresh();
        $this->assertSame('MH', $t->displayName());
        $this->assertSame('P051234567X', $t->kra_pin);

        Livewire::test('pages::admin.institution')->set('tab', 'contacts')
            ->set('contacts.phone', '+254 700 123 456')->set('contacts.po_box', '123-00100')->set('contacts.town', 'Nairobi')
            ->set('contacts.website', 'https://mwangaza.test')->set('contacts.emergency_phone', '0711 999 999')
            ->call('saveContacts')->assertHasNoErrors()
            ->set('contacts.website', 'not a url')->call('saveContacts')->assertHasErrors(['contacts.website']);
        $this->assertStringContainsString('P.O. Box 123-00100', $this->tenant->fresh()->postalAddress());

        Livewire::test('pages::admin.institution')->set('tab', 'documents')
            ->set('docs.mpesa_paybill', '247247')->set('docs.receipt_footer', 'Asante, pona haraka')
            ->set('docs.show_stamp_on_documents', true)
            ->call('saveDocuments')->assertHasNoErrors()
            ->set('docs.mpesa_paybill', '12ab')->call('saveDocuments')->assertHasErrors(['docs.mpesa_paybill']);
        $t = $this->tenant->fresh();
        $this->assertSame('247247', $t->setting('payments.mpesa_paybill'));
        $this->assertSame('Asante, pona haraka', $t->setting('documents.receipt_footer'));
    }

    public function test_logo_upload_is_validated_stored_privately_and_served(): void
    {
        $this->as('admin');

        Livewire::test('pages::admin.institution')->set('tab', 'branding')
            ->set('logo', UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml'))
            ->call('saveBranding')->assertHasErrors(['logo']);

        Livewire::test('pages::admin.institution')->set('tab', 'branding')
            ->set('brand.primary_color', '#123456')->set('brand.secondary_color', '#ABCDEF')
            ->set('logo', UploadedFile::fake()->image('logo.png', 200, 200))
            ->call('saveBranding')->assertHasNoErrors();

        $t = $this->tenant->fresh();
        $this->assertNotNull($t->logo_path);
        $this->assertStringStartsWith("tenants/{$t->id}/branding/", $t->logo_path);
        Storage::disk('local')->assertExists($t->logo_path);
        $this->assertSame('#123456', $t->brandColor('primary'));

        // Public logo route (used by printed documents and favicon).
        auth()->logout();
        $this->get(route('branding.logo', $t->slug))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

        // Replacing deletes the old file; removing falls back to the system logo.
        $this->as('admin');
        $old = $t->logo_path;
        Livewire::test('pages::admin.institution')->set('logo', UploadedFile::fake()->image('new.png', 100, 100))->call('saveBranding');
        Storage::disk('local')->assertMissing($old);
        Livewire::test('pages::admin.institution')->call('removeLogo');
        $this->assertNull($this->tenant->fresh()->logo_path);
        $this->get(route('branding.logo', $t->slug))->assertNotFound();
    }

    public function test_signatories_are_managed_and_printed_on_assigned_documents(): void
    {
        $this->as('admin');
        $doctor = $this->staff['doctor'];
        $doctor->forceFill(['regulator' => 'KMPDC', 'registration_number' => 'A9999'])->save();

        Livewire::test('pages::admin.institution')->set('tab', 'signatories')
            ->set('sig.user_id', (string) $doctor->id)
            ->assertSet('sig.name', $doctor->name)
            ->assertSet('sig.registration', 'KMPDC A9999')
            ->set('sig.title', 'Medical Superintendent')
            ->set('sig.documents', ['referral', 'discharge'])
            ->set('signature', UploadedFile::fake()->image('sig.png', 300, 100))
            ->call('saveSignatory')->assertHasNoErrors();

        $s = Signatory::firstOrFail();
        $this->assertSame(['referral', 'discharge'], $s->documents);
        Storage::disk('local')->assertExists($s->signature_path);
        $this->assertCount(1, $this->tenant->fresh()->signatoriesFor('referral'));
        $this->assertCount(0, $this->tenant->fresh()->signatoriesFor('receipt'));

        // Signature images require sign-in.
        $this->get(route('branding.signature', $s))->assertOk();

        // Unknown document keys and outside users are rejected.
        $outsider = User::factory()->create();
        Livewire::test('pages::admin.institution')
            ->set('sig.name', 'X')->set('sig.title', 'Y')->set('sig.documents', ['hack'])
            ->call('saveSignatory')->assertHasErrors(['sig.documents.0']);
        Livewire::test('pages::admin.institution')
            ->set('sig.name', 'X')->set('sig.title', 'Y')->set('sig.user_id', (string) $outsider->id)
            ->call('saveSignatory')->assertHasErrors(['sig.user_id']);

        // Rendered on the letterhead preview.
        Livewire::test('pages::admin.institution')->assertSee('Medical Superintendent');

        Livewire::test('pages::admin.institution')->call('deleteSignatory', $s->id);
        $this->assertSame(0, Signatory::count());
        Storage::disk('local')->assertMissing($s->signature_path);
    }

    public function test_branding_is_tenant_isolated_and_admin_only(): void
    {
        $this->as('admin');
        Livewire::test('pages::admin.institution')->set('sig.name', 'Dr A')->set('sig.title', 'MS')->set('signature', UploadedFile::fake()->image('s.png'))->call('saveSignatory');
        $mine = Signatory::firstOrFail();

        $other = app(TenantProvisioningService::class)->provision(['name' => 'Other Hospital'], ['name' => 'Main', 'code' => 'MAIN']);
        $otherAdmin = User::factory()->create(['email_verified_at' => now()]);
        $other->users()->attach($otherAdmin, ['status' => 'active', 'is_default' => true, 'joined_at' => now()]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($other->id);
        $otherAdmin->assignRole(RoleCatalog::HOSPITAL_ADMINISTRATOR);

        app(TenantContext::class)->clear();
        $this->actingAs($otherAdmin);
        $this->get(route('branding.signature', $mine))->assertNotFound();

        $this->as('doctor');
        $this->get(route('admin.institution'))->assertForbidden();
    }

    public function test_institution_logo_is_the_favicon_when_signed_in(): void
    {
        // No logo yet: the system icon.
        $this->as('admin');
        $this->get(route('dashboard'))->assertOk()->assertSee('href="/favicon.svg"', false);

        $this->tenant->update(['logo_path' => UploadedFile::fake()->image('logo.png', 64, 64)->store("tenants/{$this->tenant->id}/branding", 'local')]);
        $this->as('admin');
        $logoUrl = $this->tenant->fresh()->logoUrl();

        $this->get(route('dashboard'))->assertOk()
            ->assertSee('<link rel="icon" href="'.e($logoUrl).'" data-brand="institution">', false)
            ->assertSee('<link rel="apple-touch-icon" href="'.e($logoUrl).'">', false);

        // Signed out (login page, no institution yet): the system icon.
        auth()->logout();
        app(TenantContext::class)->clear();
        $this->get(route('login'))->assertOk()->assertSee('href="/favicon.svg"', false)->assertDontSee($logoUrl, false);
    }

    public function test_sidebar_and_page_title_use_institution_name(): void
    {
        $this->tenant->update(['short_name' => 'MWH']);
        $this->as('admin');

        $this->get(route('dashboard'))->assertOk()->assertSee('MWH')->assertSee('<title>', false);
    }
}
