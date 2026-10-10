<?php

namespace Tests\Feature\Platform;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ObservabilityTest extends TestCase
{
    public function test_health_check_succeeds_when_dependencies_are_up(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_health_check_fails_when_database_is_unreachable(): void
    {
        config(['database.connections.pgsql.port' => 1]);
        DB::purge('pgsql');

        $this->get('/up')->assertServerError();
    }

    public function test_a_request_id_is_generated_and_returned(): void
    {
        $response = $this->get('/up');

        $this->assertTrue(Str::isUuid((string) $response->headers->get('X-Request-Id')));
    }

    public function test_a_well_formed_incoming_request_id_is_kept(): void
    {
        $this->get('/up', ['X-Request-Id' => 'edge-1234abcd'])
            ->assertHeader('X-Request-Id', 'edge-1234abcd');
    }

    public function test_a_malformed_incoming_request_id_is_replaced(): void
    {
        $response = $this->get('/up', ['X-Request-Id' => "bad\nvalue<script>"]);

        $this->assertTrue(Str::isUuid((string) $response->headers->get('X-Request-Id')));
    }
}
