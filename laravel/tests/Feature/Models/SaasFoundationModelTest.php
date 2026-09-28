<?php

namespace Tests\Feature\Models;

use App\Models\Branch;
use App\Models\Laboratory;
use App\Models\LaboratoryUser;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaasFoundationModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_laboratory_can_have_multiple_branches_and_each_branch_belongs_to_it(): void
    {
        $laboratory = Laboratory::factory()->create();
        $branches = Branch::factory()->count(2)->for($laboratory)->create();

        $this->assertCount(2, $laboratory->branches);
        $this->assertTrue($branches->every(
            fn (Branch $branch): bool => $branch->laboratory->is($laboratory)
        ));
    }

    public function test_users_and_laboratories_have_a_many_to_many_relationship(): void
    {
        $user = User::factory()->create();
        $laboratories = Laboratory::factory()->count(2)->create();
        $secondUser = User::factory()->create();

        $user->laboratories()->attach($laboratories->pluck('id'));
        $laboratories->first()->users()->attach($secondUser, ['is_active' => true]);

        $this->assertCount(2, $user->laboratories);
        $this->assertCount(2, $laboratories->first()->users);
    }

    public function test_membership_exposes_active_state_and_belongs_to_user_and_laboratory(): void
    {
        $membership = LaboratoryUser::factory()->asDefault()->create(['is_active' => false]);

        $this->assertFalse($membership->is_active);
        $this->assertTrue($membership->is_default);
        $this->assertInstanceOf(User::class, $membership->user);
        $this->assertInstanceOf(Laboratory::class, $membership->laboratory);

        $laboratory = $membership->user->laboratories()->firstOrFail();

        $this->assertInstanceOf(LaboratoryUser::class, $laboratory->pivot);
        $this->assertFalse($laboratory->pivot->is_active);
        $this->assertTrue($laboratory->pivot->is_default);
    }

    public function test_user_cannot_have_more_than_one_default_laboratory(): void
    {
        $user = User::factory()->create();
        LaboratoryUser::factory()->asDefault()->create(['user_id' => $user->id]);

        $this->expectException(QueryException::class);

        LaboratoryUser::factory()->asDefault()->create(['user_id' => $user->id]);
    }

    public function test_membership_cannot_be_duplicated_for_the_same_user_and_laboratory(): void
    {
        $membership = LaboratoryUser::factory()->create();

        $this->expectException(QueryException::class);

        LaboratoryUser::factory()->create([
            'laboratory_id' => $membership->laboratory_id,
            'user_id' => $membership->user_id,
        ]);
    }

    public function test_different_laboratories_can_use_the_same_branch_code(): void
    {
        $firstBranch = Branch::factory()->create(['code' => 'CENTRAL']);
        $secondBranch = Branch::factory()->create(['code' => 'CENTRAL']);

        $this->assertNotSame($firstBranch->laboratory_id, $secondBranch->laboratory_id);
        $this->assertSame('CENTRAL', $secondBranch->code);
    }

    public function test_laboratory_cannot_repeat_a_branch_code(): void
    {
        $laboratory = Laboratory::factory()->create();

        Branch::factory()->for($laboratory)->create(['code' => 'CENTRAL']);

        $this->expectException(QueryException::class);

        Branch::factory()->for($laboratory)->create(['code' => 'CENTRAL']);
    }

    public function test_subscription_belongs_to_a_laboratory_and_a_plan(): void
    {
        $subscription = Subscription::factory()->create();

        $this->assertInstanceOf(Laboratory::class, $subscription->laboratory);
        $this->assertInstanceOf(Plan::class, $subscription->plan);
    }

    public function test_laboratory_and_plan_can_keep_multiple_subscription_records(): void
    {
        $laboratory = Laboratory::factory()->create();
        $plan = Plan::factory()->create();

        Subscription::factory()->for($laboratory)->for($plan)->create([
            'status' => Subscription::STATUS_INACTIVE,
        ]);
        Subscription::factory()->for($laboratory)->for($plan)->create([
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $this->assertCount(2, $laboratory->subscriptions);
        $this->assertCount(2, $plan->subscriptions);
    }

    public function test_seeders_are_idempotent_for_saas_foundation_data(): void
    {
        $this->seed();
        $this->seed();

        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('plans', 1);
        $this->assertDatabaseCount('laboratories', 1);
        $this->assertDatabaseCount('branches', 1);
        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertDatabaseCount('laboratory_user', 2);

        $this->assertDatabaseHas('branches', [
            'code' => 'MAIN',
            'is_main' => true,
        ]);
        $this->assertDatabaseHas('laboratory_user', ['is_active' => true]);
    }
}
