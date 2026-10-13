<?php

namespace Tests\Feature\Marketing;

use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WelcomePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_lists_active_plans_from_the_database_in_order(): void
    {
        Plan::query()->updateOrCreate(['code' => 'zz_hidden'], ['name' => 'Ancienne', 'included_users' => 1, 'active' => false, 'sort_order' => 1]);
        Plan::query()->where('code', 'business')->update(['price_monthly' => 15000, 'price_yearly' => 150000]);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('<meta name="description"', false)
            ->assertSee('og:title', false)
            ->assertInertia(fn ($page) => $page
                ->component('Welcome')
                ->where('plans', fn ($plans) => collect($plans)->pluck('code')->doesntContain('zz_hidden'))
                ->where('plans', fn ($plans) => collect($plans)->firstWhere('code', 'business')['price_monthly'] === 15000)
                ->where('trialDays', config('diaba.subscriptions.trial_days'))
                ->missing('plans.0.id'));
    }

    public function test_the_online_store_is_advertised_only_on_plans_that_include_it(): void
    {
        Plan::query()->where('code', 'business')->update(['online_store_enabled' => true]);
        Plan::query()->where('code', 'essential')->update(['online_store_enabled' => false]);

        $this->get(route('home'))->assertInertia(fn ($page) => $page
            ->where('plans', fn ($plans) => collect($plans)->firstWhere('code', 'business')['online_store_enabled'] === true)
            ->where('plans', fn ($plans) => collect($plans)->firstWhere('code', 'essential')['online_store_enabled'] === false));
    }

    public function test_addons_without_price_are_not_advertised(): void
    {
        config([
            'diaba.addons.extra_user.price_monthly' => 2000,
            'diaba.addons.extra_warehouse.price_monthly' => null,
        ]);

        $this->get(route('home'))
            ->assertInertia(fn ($page) => $page
                ->has('addons', 1)
                ->where('addons.0.code', 'extra_user'));
    }

    public function test_contact_whatsapp_is_normalised_and_hidden_when_empty(): void
    {
        config(['diaba.marketing.whatsapp' => '+221 77 123 45 67']);
        $this->get(route('home'))->assertInertia(fn ($page) => $page->where('contact.whatsapp', '221771234567'));

        config(['diaba.marketing.whatsapp' => null]);
        $this->get(route('home'))->assertInertia(fn ($page) => $page->where('contact.whatsapp', null));
    }

    public function test_robots_block_everything_unless_indexing_is_enabled(): void
    {
        config(['diaba.marketing.indexable' => false]);
        $this->get('/robots.txt')->assertOk()->assertSee("Disallow: /\n", false)->assertDontSee('Sitemap');

        config(['diaba.marketing.indexable' => true]);
        $this->get('/robots.txt')->assertOk()->assertSee('Sitemap: '.url('/sitemap.xml'), false);
    }

    public function test_sitemap_lists_public_pages(): void
    {
        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->assertSee('<loc>'.url('/').'</loc>', false)
            ->assertSee(route('legal.privacy'), false)
            ->assertDontSee('dashboard');
    }
}
