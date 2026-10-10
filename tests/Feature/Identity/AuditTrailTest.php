<?php

namespace Tests\Feature\Identity;

use App\Domain\Audit\AuditLog;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_events_cannot_be_updated_in_database(): void
    {
        $event = AuditEvent::query()->create(['action' => 'test.event']);

        $this->expectException(QueryException::class);

        DB::table('audit_events')->where('id', $event->id)->update(['action' => 'tampered']);
    }

    public function test_audit_events_cannot_be_deleted_in_database(): void
    {
        $event = AuditEvent::query()->create(['action' => 'test.event']);

        $this->expectException(QueryException::class);

        DB::table('audit_events')->where('id', $event->id)->delete();
    }

    public function test_audit_events_cannot_be_modified_through_eloquent(): void
    {
        $event = AuditEvent::query()->create(['action' => 'test.event']);

        $this->expectException(LogicException::class);

        $event->update(['action' => 'tampered']);
    }

    public function test_successful_and_failed_logins_are_audited_without_storing_the_raw_email(): void
    {
        $user = User::factory()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password']);
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        $failed = AuditEvent::query()->where('action', 'auth.failed')->sole();
        $this->assertArrayHasKey('email_hash', $failed->metadata ?? []);
        $this->assertStringNotContainsString($user->email, (string) json_encode($failed->metadata));

        $this->assertDatabaseHas('audit_events', ['action' => 'auth.login', 'actor_id' => $user->id]);
        $this->assertNotNull($user->fresh()?->last_login_at);
    }

    public function test_sensitive_fields_are_never_written_to_the_audit_log(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        app(AuditLog::class)->record('test.redaction', $user, after: ['name' => 'X', 'password' => 'secret', 'two_factor_secret' => 's']);

        $event = AuditEvent::query()->where('action', 'test.redaction')->sole();
        $this->assertSame(['name' => 'X'], $event->after);
    }
}
