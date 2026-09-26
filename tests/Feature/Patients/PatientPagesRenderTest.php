<?php

namespace Tests\Feature\Patients;

use App\Models\Patient;
use App\Models\User;
use App\Services\Tenancy\TenantProvisioningService;
use App\Support\RoleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PatientPagesRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_patients_index_and_show_pages_render_over_http(): void
    {
        $tenant = app(TenantProvisioningService::class)->provision(
            ['name' => 'Test Hospital'],
            ['name' => 'Main', 'code' => 'MAIN'],
        );

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

        $user = User::factory()->create(['email_verified_at' => now()]);
        $tenant->users()->attach($user, ['status' => 'active', 'is_default' => true, 'joined_at' => now()]);
        $user->assignRole(RoleCatalog::RECEPTIONIST);

        $patient = Patient::factory()->for($tenant)->create();

        $this->actingAs($user);

        $this->get(route('patients.index'))->assertOk()->assertSee($patient->mrn);
        $this->get(route('patients.show', $patient))->assertOk()->assertSee($patient->last_name);
    }
}
