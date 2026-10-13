<?php

namespace Tests\Feature\Platform;

use App\Models\AuditEvent;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\TenantProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PlatformTenantProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function superAdmin(): User
    {
        $user = User::factory()->withTwoFactor()->create();
        $user->platform_role_id = Role::system('platform', 'super_admin')->id;
        $user->save();

        return $user;
    }

    public function test_support_sees_the_profile_but_never_the_stamp(): void
    {
        $setup = $this->createTenantWithMember();
        $this->actingAs($setup['user'])->post(route('company.images.store', 'stamp'), ['image' => UploadedFile::fake()->image('s.png')]);
        $this->actingAs($setup['user'])->post(route('company.images.store', 'logo'), ['image' => UploadedFile::fake()->image('l.png')]);
        auth()->logout();
        $this->app['auth']->forgetGuards();

        $owner = $this->superAdmin();
        $page = $this->actingAs($owner)->get(route('platform.tenants.show', $setup['tenant']->id));
        $page->assertInertia(fn (Assert $p) => $p
            ->where('profile.vat_regime', 'real')
            ->where('profile.has_stamp', true)
            ->whereType('profile.logo_url', 'string')
            ->missing('profile.stamp_url'));

        $url = $page->viewData('page')['props']['profile']['logo_url'];
        $this->get($url)->assertOk()->assertHeader('content-type', 'image/webp');

        // La signature ne se consulte pas depuis la console.
        $this->actingAs($owner)->get(route('platform.tenants.profile.images.show', [$setup['tenant']->id, 'stamp']))->assertClientError();
    }

    public function test_changes_need_a_reason_and_are_audited_in_the_company_journal(): void
    {
        $setup = $this->createTenantWithMember();
        $owner = $this->superAdmin();

        $this->actingAs($owner)->put(route('platform.tenants.profile.update', $setup['tenant']->id), ['vat_regime' => 'exempt', 'maps_url' => ''])
            ->assertSessionHasErrors('reason');
        $this->assertSame('real', TenantProfile::query()->find($setup['tenant']->id)?->vat_regime ?? 'real');

        $this->actingAs($owner)->put(route('platform.tenants.profile.update', $setup['tenant']->id), [
            'vat_regime' => 'exempt', 'maps_url' => 'https://maps.app.goo.gl/AbCd1234', 'reason' => 'Demande du client par téléphone',
        ])->assertRedirect();

        $profile = TenantProfile::query()->findOrFail($setup['tenant']->id);
        $this->assertSame('exempt', $profile->vat_regime);

        $event = AuditEvent::query()->withoutGlobalScopes()->where('action', 'company.profile_updated')->latest('created_at')->firstOrFail();
        $this->assertSame($setup['tenant']->id, $event->tenant_id);
        $this->assertSame($owner->id, $event->actor_id);
        $this->assertSame('Demande du client par téléphone', $event->metadata['reason'] ?? null);

        // Le lien doit rester un lien Google Maps.
        $this->actingAs($owner)->put(route('platform.tenants.profile.update', $setup['tenant']->id), [
            'vat_regime' => 'real', 'maps_url' => 'https://evil.example/maps', 'reason' => 'Test lien',
        ])->assertSessionHasErrors('maps_url');
    }

    public function test_support_replaces_and_removes_logo_and_cover_with_a_reason_even_when_suspended(): void
    {
        $setup = $this->createTenantWithMember();
        Subscription::query()->withoutGlobalScopes()->where('tenant_id', $setup['tenant']->id)->update(['status' => Subscription::SUSPENDED]);
        $owner = $this->superAdmin();

        $this->actingAs($owner)->post(route('platform.tenants.profile.images.store', [$setup['tenant']->id, 'logo']), ['image' => UploadedFile::fake()->image('l.png')])
            ->assertSessionHasErrors('reason');
        $this->assertNull(TenantProfile::query()->find($setup['tenant']->id)?->logo_path);

        $this->actingAs($owner)->post(route('platform.tenants.profile.images.store', [$setup['tenant']->id, 'logo']), [
            'image' => UploadedFile::fake()->image('l.png'), 'reason' => 'Logo envoyé par e-mail',
        ])->assertRedirect();
        $this->assertNotNull(TenantProfile::query()->findOrFail($setup['tenant']->id)->logo_path);

        // Le support ne remplace pas un cachet : seulement le logo et la devanture.
        $this->actingAs($owner)->post(route('platform.tenants.profile.images.store', [$setup['tenant']->id, 'stamp']), [
            'image' => UploadedFile::fake()->image('s.png'), 'reason' => 'Tentative',
        ])->assertClientError();
        $this->assertNull(TenantProfile::query()->findOrFail($setup['tenant']->id)->stamp_path);

        $this->actingAs($owner)->delete(route('platform.tenants.profile.images.destroy', [$setup['tenant']->id, 'logo']))->assertSessionHasErrors('reason');
        $this->actingAs($owner)->delete(route('platform.tenants.profile.images.destroy', [$setup['tenant']->id, 'logo']), ['reason' => 'Logo inapproprié'])->assertRedirect();
        $this->assertNull(TenantProfile::query()->findOrFail($setup['tenant']->id)->logo_path);
    }

    public function test_support_can_remove_a_stamp_with_a_reason(): void
    {
        $setup = $this->createTenantWithMember();
        $this->actingAs($setup['user'])->post(route('company.images.store', 'stamp'), ['image' => UploadedFile::fake()->image('s.png')]);

        $this->actingAs($this->superAdmin())->delete(route('platform.tenants.profile.images.destroy', [$setup['tenant']->id, 'stamp']), ['reason' => 'Cachet erroné'])->assertRedirect();
        $this->assertNull(TenantProfile::query()->findOrFail($setup['tenant']->id)->stamp_path);
    }

    public function test_a_company_user_cannot_reach_the_support_endpoints(): void
    {
        $setup = $this->createTenantWithMember();

        $this->actingAs($setup['user'])->put(route('platform.tenants.profile.update', $setup['tenant']->id), ['vat_regime' => 'exempt', 'reason' => 'Test test'])->assertForbidden();
        $this->actingAs($setup['user'])->get(route('platform.tenants.profile.images.show', [$setup['tenant']->id, 'logo']))->assertForbidden();
    }
}
