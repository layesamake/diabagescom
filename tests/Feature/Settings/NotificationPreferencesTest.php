<?php

namespace Tests\Feature\Settings;

use App\Http\Middleware\ResolveTenantContext;
use App\Models\AuditEvent;
use App\Notifications\AlertDigestNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Sales\SalesTestHelpers;
use Tests\TestCase;

class NotificationPreferencesTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    /** Une caisse restée ouverte depuis hier : un point « à fermer » pour l'administrateur. */
    private function withForgottenCashSession(): array
    {
        $setup = $this->createTenantWithMember();
        $this->openSession($setup);
        $this->travel(1)->day();

        return $setup;
    }

    public function test_defaults_are_home_on_and_email_off_and_choices_are_saved_and_audited(): void
    {
        $setup = $this->createTenantWithMember();

        $this->actingAs($setup['user'])->get(route('notifications.edit'))->assertInertia(fn (Assert $p) => $p
            ->component('settings/Notifications')
            ->where('preferences.low_stock', ['in_app' => true, 'email' => false])
            ->has('categories', 6));

        $this->actingAs($setup['user'])->put(route('notifications.update'), ['preferences' => [
            'low_stock' => ['in_app' => false, 'email' => true],
            'cash_to_close' => ['in_app' => true, 'email' => true],
        ]])->assertRedirect();

        $this->actingAs($setup['user'])->get(route('notifications.edit'))->assertInertia(fn (Assert $p) => $p
            ->where('preferences.low_stock', ['in_app' => false, 'email' => true])
            ->where('preferences.cash_to_close', ['in_app' => true, 'email' => true])
            // Une catégorie non envoyée est considérée comme décochée.
            ->where('preferences.overdue_invoices', ['in_app' => false, 'email' => false]));
        $this->assertTrue(AuditEvent::query()->withoutGlobalScopes()->where('action', 'notifications.preferences_updated')->exists());
    }

    public function test_only_categories_allowed_by_the_role_can_be_configured(): void
    {
        $setup = $this->createTenantWithMember();
        $cashier = $this->member($setup, 'cashier');

        $this->actingAs($cashier['user'])->get(route('notifications.edit'))->assertInertia(fn (Assert $p) => $p
            ->missing('preferences.saas_invoice')
            ->missing('preferences.low_stock'));

        $this->actingAs($cashier['user'])->put(route('notifications.update'), ['preferences' => [
            'saas_invoice' => ['in_app' => true, 'email' => true],
        ]])->assertRedirect();
        $this->assertSame(0, DB::table('notification_preferences')->where('user_id', $cashier['user']->id)->where('category', 'saas_invoice')->count());
    }

    public function test_the_home_page_follows_the_in_app_choice(): void
    {
        $setup = $this->withForgottenCashSession();

        $this->actingAs($setup['user'])->get(route('dashboard'))->assertInertia(fn (Assert $p) => $p->where('alerts.cash_to_close', 1));

        $this->actingAs($setup['user'])->put(route('notifications.update'), ['preferences' => ['cash_to_close' => ['in_app' => false, 'email' => false]]]);
        $this->actingAs($setup['user'])->get(route('dashboard'))->assertInertia(fn (Assert $p) => $p->missing('alerts.cash_to_close'));
    }

    public function test_the_daily_digest_is_opt_in_counts_only_and_sent_once_a_day(): void
    {
        Notification::fake();
        $setup = $this->withForgottenCashSession();

        // Par défaut, aucun e-mail.
        $this->artisan('diaba:alerts:digest')->assertSuccessful();
        Notification::assertNothingSent();

        $this->actingAs($setup['user'])->put(route('notifications.update'), ['preferences' => ['cash_to_close' => ['in_app' => true, 'email' => true]]]);

        $this->artisan('diaba:alerts:digest')->assertSuccessful();
        Notification::assertSentToTimes($setup['user'], AlertDigestNotification::class, 1);
        Notification::assertSentTo($setup['user'], AlertDigestNotification::class, function (AlertDigestNotification $n) use ($setup) {
            $body = implode(' ', $n->toMail($setup['user'])->introLines);

            return $n->counts === ['cash_to_close' => 1] && ! str_contains($body, 'XOF');
        });

        // Rejeu le même jour : pas de doublon.
        $this->artisan('diaba:alerts:digest')->assertSuccessful();
        Notification::assertSentToTimes($setup['user'], AlertDigestNotification::class, 1);

        // Le lendemain, si le point est toujours à traiter, un nouveau résumé part.
        $this->travel(1)->day();
        $this->artisan('diaba:alerts:digest')->assertSuccessful();
        Notification::assertSentToTimes($setup['user'], AlertDigestNotification::class, 2);
    }

    public function test_no_digest_when_there_is_nothing_to_handle(): void
    {
        Notification::fake();
        $setup = $this->createTenantWithMember();
        $this->actingAs($setup['user'])->put(route('notifications.update'), ['preferences' => ['cash_to_close' => ['in_app' => true, 'email' => true]]]);

        $this->artisan('diaba:alerts:digest')->assertSuccessful();
        Notification::assertNothingSent();
    }

    public function test_choices_are_per_company(): void
    {
        $setup = $this->createTenantWithMember();
        $this->actingAs($setup['user'])->put(route('notifications.update'), ['preferences' => ['low_stock' => ['in_app' => false, 'email' => true]]]);

        $other = $this->createTenantWithMember(user: $setup['user']);
        $this->actingAs($setup['user'])->withSession([ResolveTenantContext::SESSION_TENANT => $other['tenant']->id])
            ->get(route('notifications.edit'))->assertInertia(fn (Assert $p) => $p
            ->where('preferences.low_stock', ['in_app' => true, 'email' => false]));
    }
}
