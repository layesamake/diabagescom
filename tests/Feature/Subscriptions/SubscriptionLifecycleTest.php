<?php

namespace Tests\Feature\Subscriptions;

use App\Domain\Subscriptions\FeatureAccess;
use App\Domain\Tenancy\TenantScope;
use App\Models\Subscription;
use App\Notifications\TrialEndingNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Cycle d'essai vérifié en temps simulé (critère de fin d'EPIC-02).
 */
class SubscriptionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function subscriptionOf(array $setup): Subscription
    {
        return Subscription::query()->withoutGlobalScope(TenantScope::class)->where('tenant_id', $setup['tenant']->id)->sole();
    }

    private function statusOf(array $setup): string
    {
        $access = app(FeatureAccess::class);
        $access->forget($setup['tenant']);

        return $access->status($setup['tenant']);
    }

    public function test_trial_lasts_ten_days_then_seven_days_read_only_then_suspension(): void
    {
        $this->freezeTime();
        $setup = $this->createTenantWithMember();

        $this->assertSame('trialing', $this->statusOf($setup));

        $this->travel(10)->days();
        $this->travel(-1)->minutes();
        $this->assertSame('trialing', $this->statusOf($setup));

        $this->travel(1)->minutes();
        $this->assertSame('read_only', $this->statusOf($setup), 'Effective even before the scheduler runs.');

        $this->artisan('diaba:subscriptions:tick')->assertSuccessful();
        $subscription = $this->subscriptionOf($setup);
        $this->assertSame('read_only', $subscription->status);
        $this->assertTrue($subscription->read_only_ends_at->equalTo($subscription->trial_ends_at->addDays(7)));

        $this->travel(7)->days();
        $this->artisan('diaba:subscriptions:tick')->assertSuccessful();
        $this->assertSame('suspended', $this->subscriptionOf($setup)->status);
    }

    public function test_a_late_scheduler_does_not_grant_extra_days(): void
    {
        $this->freezeTime();
        $setup = $this->createTenantWithMember();

        // Aucune exécution pendant 20 jours : passage direct à « suspendu ».
        $this->travel(20)->days();
        $this->artisan('diaba:subscriptions:tick')->assertSuccessful();

        $this->assertSame('suspended', $this->subscriptionOf($setup)->status);
    }

    public function test_transitions_are_idempotent_and_audited_once(): void
    {
        $this->freezeTime();
        $setup = $this->createTenantWithMember();
        $this->travel(11)->days();

        $this->artisan('diaba:subscriptions:tick');
        $this->artisan('diaba:subscriptions:tick');

        $this->assertDatabaseCount('audit_events', 2); // trial_started + un seul status_changed
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $setup['tenant']->id, 'action' => 'subscription.status_changed']);
    }

    public function test_trial_notices_are_sent_at_d3_d1_and_d0_exactly_once(): void
    {
        Notification::fake();
        $this->freezeTime();
        $setup = $this->createTenantWithMember();

        $this->travel(6)->days(); // J-4 : rien
        $this->artisan('diaba:subscriptions:tick');
        Notification::assertNothingSent();

        $this->travel(1)->days(); // J-3
        $this->artisan('diaba:subscriptions:tick');
        $this->artisan('diaba:subscriptions:tick');
        Notification::assertSentToTimes($setup['user'], TrialEndingNotification::class, 1);

        $this->travel(2)->days(); // J-1
        $this->artisan('diaba:subscriptions:tick');

        $this->travel(1)->days(); // J0 : fin d'essai
        $this->artisan('diaba:subscriptions:tick');

        Notification::assertSentToTimes($setup['user'], TrialEndingNotification::class, 3);
        Notification::assertSentTo($setup['user'], TrialEndingNotification::class, fn ($n) => $n->daysLeft === 0);
    }

    public function test_a_late_scheduler_only_sends_the_most_relevant_notice(): void
    {
        Notification::fake();
        $this->freezeTime();
        $setup = $this->createTenantWithMember();

        // J-1 atteint sans exécution antérieure.
        $this->travel(9)->days();
        $this->travel(12)->hours();
        $this->artisan('diaba:subscriptions:tick');
        $this->artisan('diaba:subscriptions:tick');

        Notification::assertSentToTimes($setup['user'], TrialEndingNotification::class, 1);
        Notification::assertSentTo($setup['user'], TrialEndingNotification::class, fn ($n) => $n->daysLeft === 1);
    }

    public function test_notices_only_go_to_active_admins(): void
    {
        Notification::fake();
        $this->freezeTime();
        $cashier = $this->createTenantWithMember('cashier');

        $this->travel(7)->days();
        $this->artisan('diaba:subscriptions:tick');

        Notification::assertNotSentTo($cashier['user'], TrialEndingNotification::class);
    }
}
