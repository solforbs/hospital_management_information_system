<?php

namespace Tests\Feature\Tenancy;

use App\Models\Facility;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_users_active_tenant_is_resolved_from_membership_not_input(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $user = User::factory()->create();
        $tenantA->users()->attach($user, ['status' => 'active', 'is_default' => true, 'joined_at' => now()]);

        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $this->assertSame($tenantA->id, app(TenantContext::class)->id());
        $this->assertNotSame($tenantB->id, app(TenantContext::class)->id());
    }

    public function test_a_user_cannot_switch_into_a_tenant_they_do_not_belong_to(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $user = User::factory()->create();
        $tenantA->users()->attach($user, ['status' => 'active', 'is_default' => true, 'joined_at' => now()]);

        $this->actingAs($user);
        session(['current_tenant_id' => $tenantB->id]);

        $this->get(route('dashboard'))->assertOk();

        // The unauthorized selection must not be honoured: the resolver
        // falls back to a tenant the user actually belongs to.
        $this->assertSame($tenantA->id, app(TenantContext::class)->id());
    }

    public function test_a_facility_scoped_to_one_tenant_is_invisible_from_another_tenants_context(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $facilityA = Facility::factory()->for($tenantA)->create();
        Facility::factory()->for($tenantB)->create();

        $context = app(TenantContext::class);
        $context->set($tenantA);

        $visible = Facility::all();

        $this->assertTrue($visible->contains($facilityA));
        $this->assertCount(1, $visible);

        $context->set($tenantB);

        $this->assertFalse(Facility::all()->contains($facilityA));
    }
}
