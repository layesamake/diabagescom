<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HelpCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_help_center_requires_a_login(): void
    {
        $this->get(route('help.index'))->assertRedirect(route('login'));
    }

    public function test_any_member_can_open_the_help_center(): void
    {
        $setup = $this->createTenantWithMember('cashier');

        $this->actingAs($setup['user'])->get(route('help.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('help/Index'));
    }
}
