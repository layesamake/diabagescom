<?php

namespace Tests\Feature\Backup;

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantScope;
use App\Models\AuditEvent;
use App\Models\CloudBackupConnection;
use App\Models\TenantExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Sales\SalesTestHelpers;
use Tests\TestCase;

/**
 * Sauvegardes vers Google Drive : connexion OAuth liée à la session et à
 * l'entreprise, jeton chiffré, envoi automatique, accès retiré géré,
 * déconnexion révoquée chez Google. Aucun appel réel (Http::fake).
 */
class GoogleDriveBackupTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google_drive.client_id' => 'client-test', 'services.google_drive.client_secret' => 'secret-test',
            'services.google_drive.redirect' => 'https://dev.diaba.test/settings/backup/google/callback']);
        Storage::fake('local');
    }

    private function idToken(string $email): string
    {
        return 'x.'.rtrim(strtr(base64_encode((string) json_encode(['email' => $email])), '+/', '-_'), '=').'.y';
    }

    private function fakeGoogle(bool $revoked = false): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => fn (Request $r) => $r['grant_type'] === 'authorization_code'
                ? Http::response(['refresh_token' => 'refresh-1', 'access_token' => 'access-1', 'scope' => 'openid https://www.googleapis.com/auth/drive.file email', 'id_token' => $this->idToken('awa@example.sn')])
                : ($revoked ? Http::response(['error' => 'invalid_grant'], 400) : Http::response(['access_token' => 'access-2'])),
            'oauth2.googleapis.com/revoke' => Http::response([], 200),
            'www.googleapis.com/drive/v3/files?fields=id' => Http::response(['id' => 'folder-1']),
            'www.googleapis.com/upload/drive/v3/files*' => Http::response([], 200, ['Location' => 'https://www.googleapis.com/upload/session-1']),
            'www.googleapis.com/upload/session-1' => Http::response(['id' => 'file-1']),
            'www.googleapis.com/drive/v3/files/*' => Http::response(['id' => 'folder-1', 'trashed' => false]),
            'www.googleapis.com/drive/v3/files*' => Http::response(['files' => []]),
        ]);
    }

    private function connect(array $setup): void
    {
        $redirect = $this->actingAs($setup['user'])->get(route('backup.google.connect'))->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $redirect);
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);
        $this->assertSame('openid email https://www.googleapis.com/auth/drive.file', $query['scope']);

        $this->get(route('backup.google.callback', ['state' => $query['state'], 'code' => 'code-1']))->assertRedirect(route('backup.index'));
    }

    public function test_an_admin_connects_drive_and_the_token_is_stored_encrypted(): void
    {
        $this->fakeGoogle();
        $setup = $this->createTenantWithMember();
        $this->connect($setup);

        $connection = CloudBackupConnection::query()->withoutGlobalScope(TenantScope::class)->sole();
        $this->assertSame('awa@example.sn', $connection->account_email);
        $this->assertSame('refresh-1', $connection->refresh_token);
        $this->assertStringNotContainsString('refresh-1', (string) DB::table('cloud_backup_connections')->value('refresh_token'));
        $this->assertSame(1, AuditEvent::query()->withoutGlobalScopes()->where('action', 'backup.cloud_connected')->count());
    }

    public function test_a_callback_with_a_forged_state_or_from_another_company_is_refused(): void
    {
        $this->fakeGoogle();
        $setup = $this->createTenantWithMember();
        $this->actingAs($setup['user'])->get(route('backup.google.connect'));

        $this->get(route('backup.google.callback', ['state' => 'forged', 'code' => 'code-1']))->assertRedirect(route('backup.index'));
        $this->assertSame(0, CloudBackupConnection::query()->withoutGlobalScope(TenantScope::class)->count());
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'oauth2.googleapis.com/token'));
    }

    public function test_send_now_builds_the_backup_and_uploads_it_to_the_company_folder(): void
    {
        $this->fakeGoogle();
        $setup = $this->createTenantWithMember();
        $this->connect($setup);

        $this->post(route('backup.google.send'))->assertRedirect(route('backup.index'));

        $export = TenantExport::query()->withoutGlobalScope(TenantScope::class)->sole();
        $this->assertSame(TenantExport::READY, $export->status);
        $this->assertNotNull($export->uploaded_at, (string) $export->upload_error);
        $this->assertSame('folder-1', CloudBackupConnection::query()->withoutGlobalScope(TenantScope::class)->value('folder_id'));
        Http::assertSent(fn (Request $r) => $r->url() === 'https://www.googleapis.com/upload/session-1' && $r->hasHeader('Authorization', 'Bearer access-2'));
    }

    public function test_access_removed_from_the_google_account_is_reported_and_the_backup_stays_downloadable(): void
    {
        $this->fakeGoogle(revoked: true);
        $setup = $this->createTenantWithMember();
        $this->connect($setup);

        $this->post(route('backup.google.send'));

        $export = TenantExport::query()->withoutGlobalScope(TenantScope::class)->sole();
        $this->assertSame(TenantExport::READY, $export->status);
        $this->assertNull($export->uploaded_at);
        $this->assertStringContainsString('reconnectez', (string) $export->upload_error);
        $this->get(route('backup.download', $export))->assertOk();
    }

    public function test_the_scheduler_backs_up_due_companies_only(): void
    {
        $this->fakeGoogle();
        $setup = $this->createTenantWithMember();
        $this->connect($setup);

        $this->artisan('diaba:backups:cloud')->assertSuccessful();
        $this->artisan('diaba:backups:cloud')->assertSuccessful();
        $this->assertSame(1, TenantExport::query()->withoutGlobalScope(TenantScope::class)->count(), 'Daily: only one backup per day.');

        $this->travel(1)->days();
        $this->artisan('diaba:backups:cloud')->assertSuccessful();
        $this->assertSame(2, TenantExport::query()->withoutGlobalScope(TenantScope::class)->whereNotNull('uploaded_at')->count());
    }

    public function test_disconnecting_revokes_access_at_google_and_only_admins_manage_it(): void
    {
        $this->fakeGoogle();
        $setup = $this->createTenantWithMember();
        $this->connect($setup);

        $cashier = $this->member($setup, 'cashier');
        $this->actingAs($cashier['user'])->delete(route('backup.google.destroy'))->assertForbidden();
        $this->actingAs($cashier['user'])->get(route('backup.google.connect'))->assertForbidden();

        $this->actingAs($setup['user'])->delete(route('backup.google.destroy'))->assertRedirect(route('backup.index'));
        $this->assertSame(0, CloudBackupConnection::query()->withoutGlobalScope(TenantScope::class)->count());
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'oauth2.googleapis.com/revoke') && $r['token'] === 'refresh-1');
        app(TenantContext::class)->clear();
    }
}
