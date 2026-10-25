<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_https_responses_carry_hsts(): void
    {
        $this->get('https://localhost/')
            ->assertOk()
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_plain_http_responses_carry_no_hsts(): void
    {
        $this->get('http://localhost/')->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_hsts_is_not_sent_in_the_local_environment(): void
    {
        $this->app['env'] = 'local';

        $this->get('https://localhost/')->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_csp_still_blocks_framing_and_scripts_without_nonce(): void
    {
        $csp = $this->get('https://localhost/')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
    }
}
