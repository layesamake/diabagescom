<?php

namespace Tests\Feature\Settings;

use App\Models\AuditEvent;
use App\Models\TenantMembership;
use App\Models\User;
use App\Notifications\TeamInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\Feature\Sales\SalesTestHelpers;
use Tests\TestCase;

/**
 * Collaborateurs : invitation par e-mail, rôle et dépôts, limite de la
 * formule, garde-fous (soi-même, dernier administrateur, autre entreprise).
 */
class MembersTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    public function test_an_admin_invites_a_cashier_who_sets_a_password_and_only_sees_their_warehouse(): void
    {
        Notification::fake();
        $setup = $this->createTenantWithMember();
        $other = $this->makeWarehouse($setup, 'B');

        $this->actingAs($setup['user'])->post(route('members.store'), [
            'name' => 'Fatou Ndiaye', 'email' => 'Fatou@Example.sn', 'role' => 'cashier', 'warehouses' => [$setup['warehouse']->id],
        ])->assertSessionHasNoErrors();

        $user = User::query()->where('email', 'fatou@example.sn')->sole();
        $membership = TenantMembership::query()->where('user_id', $user->id)->sole();
        $this->assertSame('cashier', $membership->role->code);
        $this->assertSame([$setup['warehouse']->id], DB::table('warehouse_memberships')->where('membership_id', $membership->id)->pluck('warehouse_id')->all());
        $this->assertNotSame($other->id, DB::table('warehouse_memberships')->where('membership_id', $membership->id)->value('warehouse_id'));
        Notification::assertSentTo($user, TeamInvitationNotification::class, fn (TeamInvitationNotification $n) => $n->newAccount && str_contains($n->link, '/reset-password/'));

        // Le lien d'invitation permet de choisir son mot de passe ; l'adresse est alors vérifiée.
        $token = Password::broker()->createToken($user);
        auth()->logout();
        $this->post(route('password.update'), ['token' => $token, 'email' => $user->email, 'password' => 'Kora2026x', 'password_confirmation' => 'Kora2026x'])
            ->assertSessionHasNoErrors();
        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_an_existing_user_is_added_without_a_new_account(): void
    {
        Notification::fake();
        $setup = $this->createTenantWithMember();
        $existing = User::factory()->create(['email' => 'moussa@example.sn']);

        $this->actingAs($setup['user'])->post(route('members.store'), [
            'name' => 'Ignoré', 'email' => 'moussa@example.sn', 'role' => 'admin',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, User::query()->where('email', 'moussa@example.sn')->count());
        Notification::assertSentTo($existing, TeamInvitationNotification::class, fn (TeamInvitationNotification $n) => ! $n->newAccount);

        // Déjà membre : refusé.
        $this->post(route('members.store'), ['name' => 'X', 'email' => 'moussa@example.sn', 'role' => 'cashier', 'warehouses' => [$setup['warehouse']->id]])
            ->assertSessionHasErrors('email');
    }

    public function test_a_non_admin_role_needs_at_least_one_warehouse(): void
    {
        Notification::fake();
        $setup = $this->createTenantWithMember();

        $this->actingAs($setup['user'])->post(route('members.store'), ['name' => 'A', 'email' => 'a@example.sn', 'role' => 'cashier', 'warehouses' => []])
            ->assertSessionHasErrors('warehouses');
        $this->assertSame(0, User::query()->where('email', 'a@example.sn')->count(), 'Nothing is created on error.');
    }

    public function test_the_plan_user_limit_is_enforced(): void
    {
        Notification::fake();
        $setup = $this->createTenantWithMember(plan: 'essential'); // 2 utilisateurs inclus
        $this->actingAs($setup['user'])->post(route('members.store'), ['name' => 'B', 'email' => 'b@example.sn', 'role' => 'cashier', 'warehouses' => [$setup['warehouse']->id]])
            ->assertSessionHasNoErrors();

        $this->post(route('members.store'), ['name' => 'C', 'email' => 'c@example.sn', 'role' => 'cashier', 'warehouses' => [$setup['warehouse']->id]]);
        $this->assertSame(0, User::query()->where('email', 'c@example.sn')->count());
    }

    public function test_role_change_suspension_and_guards(): void
    {
        Notification::fake();
        $setup = $this->createTenantWithMember();
        $cashier = $this->member($setup, 'cashier');

        $this->actingAs($setup['user'])->put(route('members.update', $cashier['membership']), ['role' => 'management', 'warehouses' => [$setup['warehouse']->id]])
            ->assertSessionHasNoErrors();
        $this->assertSame('management', $cashier['membership']->refresh()->role->code);

        $this->post(route('members.suspend', $cashier['membership']))->assertSessionHasNoErrors();
        $this->assertSame('suspended', $cashier['membership']->refresh()->status);
        $this->post(route('members.reactivate', $cashier['membership']))->assertSessionHasNoErrors();
        $this->assertSame('active', $cashier['membership']->refresh()->status);

        // Soi-même : interdit (et donc dernier administrateur protégé).
        $this->post(route('members.suspend', $setup['membership']))->assertSessionHasErrors('member');
        $this->assertSame('active', $setup['membership']->refresh()->status);

        // Un caissier ne gère pas les collaborateurs.
        $this->actingAs($cashier['user'])->get(route('members.index'))->assertForbidden();
        $this->assertGreaterThanOrEqual(3, AuditEvent::query()->withoutGlobalScopes()->where('action', 'like', 'membership.%')->count());
    }

    public function test_a_member_of_another_company_cannot_be_touched(): void
    {
        $setup = $this->createTenantWithMember();
        $other = $this->createTenantWithMember();
        $foreign = $this->member($other, 'cashier');

        $this->actingAs($setup['user'])->post(route('members.suspend', $foreign['membership']))->assertNotFound();
        $this->assertSame('active', $foreign['membership']->refresh()->status);
    }
}
