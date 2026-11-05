<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TrainingAttempt;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantProvisioningService;
use App\Support\RoleCatalog;
use App\Support\Training;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TrainingTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = app(TenantProvisioningService::class)->provision(['name' => 'Machakos Level 5 Hospital'], ['name' => 'Main', 'code' => 'MAIN']);
    }

    protected function staff(string $role, string $name = 'Staff'): User
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'name' => $name]);
        $this->tenant->users()->attach($user, ['status' => 'active', 'is_default' => true, 'joined_at' => now()]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $user->assignRole($role);

        return $user;
    }

    protected function actAs(User $user): void
    {
        $c = app(TenantContext::class);
        $c->set($this->tenant->fresh());
        $c->setFacility($this->tenant->facilities()->first());
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->actingAs($user);
    }

    /** Displayed positions of the correct answers for this attempt's order. */
    protected function correctPositions(string $module, array $order): array
    {
        return collect(Training::module($module)['questions'])
            ->map(fn ($q, $i) => array_search($q['answer'], $order[$i], true))
            ->all();
    }

    public function test_catalog_is_complete_and_well_formed(): void
    {
        $this->assertCount(11, Training::modules());

        foreach (Training::modules() as $key => $m) {
            $this->assertFileExists(base_path('docs/training/'.$m['file']), $key);
            $this->assertGreaterThanOrEqual(3, count(Training::lessons($key)), "$key lessons");
            $this->assertGreaterThanOrEqual(5, count($m['questions']), "$key questions");
            $this->assertNotEmpty($m['required_for'], $key);

            foreach ($m['questions'] as $i => $q) {
                $this->assertCount(4, $q['options'], "$key q$i");
                $this->assertArrayHasKey($q['answer'], $q['options'], "$key q$i answer");
                $this->assertNotEmpty($q['why'], "$key q$i why");
            }
        }
    }

    public function test_required_modules_follow_the_role(): void
    {
        $nurse = Training::requiredModules([RoleCatalog::NURSE]);
        $this->assertSame(['getting-started', 'privacy-security', 'triage-nursing', 'inpatient-maternity'], array_keys($nurse));

        $cashier = array_keys(Training::requiredModules([RoleCatalog::CASHIER]));
        $this->assertContains('billing-sha', $cashier);
        $this->assertNotContains('clinical', $cashier);

        $this->assertSame(['getting-started', 'privacy-security'], array_keys(Training::requiredModules([])), 'everyone, even platform admins');
    }

    public function test_training_centre_shows_role_modules_and_progress(): void
    {
        $this->actAs($this->staff(RoleCatalog::CASHIER));

        $this->get(route('training.index'))->assertOk()
            ->assertSee('Required for your role')
            ->assertSee('Billing, SHA claims and payments')
            ->assertSee('0 / 3');
        $this->get(route('training.module', 'billing-sha'))->assertOk()->assertSee('How charges reach the bill');
        $this->get('/training/no-such-module')->assertNotFound();
    }

    public function test_passing_a_quiz_records_the_attempt_without_leaking_answers_first(): void
    {
        $cashier = $this->staff(RoleCatalog::CASHIER);
        $this->actAs($cashier);

        $page = Livewire::test('pages::training.module', ['module' => 'billing-sha'])->call('startQuiz');
        $firstWhy = Training::module('billing-sha')['questions'][0]['why'];
        $page->assertDontSee($firstWhy)->assertSee('Submit answers');

        // Unanswered questions are refused.
        $page->call('submit')->assertHasErrors(['answers.0']);

        $page->set('answers', $this->correctPositions('billing-sha', $page->get('order')))
            ->call('submit')->assertHasNoErrors()
            ->assertSee('100%')->assertSee($firstWhy)->assertSee('Retake quiz');

        $attempt = TrainingAttempt::sole();
        $this->assertTrue($attempt->passed);
        $this->assertSame(100, $attempt->percent);
        $this->assertSame($this->tenant->id, $attempt->tenant_id);
        $this->assertSame('billing-sha', $attempt->module);
        $this->assertTrue(Training::progress($cashier)->get('billing-sha')['passed']);
        $this->get(route('training.index'))->assertSee('Passed 100%')->assertSee('1 / 3');
    }

    public function test_wrong_answers_fail_and_retake_reshuffles(): void
    {
        $this->actAs($this->staff(RoleCatalog::NURSE));

        $page = Livewire::test('pages::training.module', ['module' => 'triage-nursing'])->call('startQuiz');
        $order = $page->get('order');
        $correct = $this->correctPositions('triage-nursing', $order);
        $wrong = array_map(fn ($p) => ($p + 1) % 4, $correct);

        $page->set('answers', $wrong)->call('submit')->assertSee('Not passed yet');
        $this->assertFalse(TrainingAttempt::sole()->passed);

        // Answers can't be resubmitted on the same attempt; a retake gets a fresh order.
        $seed = $page->get('seed');
        $page->call('retake');
        $this->assertNotSame($seed, $page->get('seed'));
        $this->assertNull($page->get('result'));
    }

    public function test_grading_uses_the_attempts_shuffled_order(): void
    {
        $quizA = Training::quiz('clinical', 'seed-a');
        $quizB = Training::quiz('clinical', 'seed-b');
        $this->assertSame($quizA, Training::quiz('clinical', 'seed-a'), 'stable for one attempt');
        $this->assertNotSame($quizA['order'], $quizB['order'], 'differs between attempts');

        $this->assertSame(6, Training::grade('clinical', $this->correctPositions('clinical', $quizA['order']), $quizA['order'])['score']);
        $this->assertFalse(Training::grade('clinical', [], $quizA['order'])['passed']);
    }

    public function test_admin_sees_training_records_and_can_export(): void
    {
        $admin = $this->staff(RoleCatalog::HOSPITAL_ADMINISTRATOR, 'Grace Admin');
        $nurse = $this->staff(RoleCatalog::NURSE, 'Nurse Wanjiru');
        TrainingAttempt::create(['user_id' => $nurse->id, 'tenant_id' => $this->tenant->id, 'module' => 'getting-started', 'score' => 6, 'total' => 6, 'percent' => 100, 'passed' => true, 'answers' => []]);

        $this->actAs($admin);
        Livewire::test('pages::training.records')
            ->assertSee('Nurse Wanjiru')->assertSee('1 of 4 passed')
            ->set('incompleteOnly', true)->assertSee('Nurse Wanjiru');

        $this->get(route('admin.training-records'))->assertOk();
        Livewire::test('pages::training.records')->call('export')->assertFileDownloaded('training-records-'.now()->format('Y-m-d').'.csv');

        $this->actAs($nurse);
        $this->get(route('admin.training-records'))->assertForbidden();
    }
}
