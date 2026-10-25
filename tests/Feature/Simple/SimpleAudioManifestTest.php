<?php

namespace Tests\Feature\Simple;

use App\Domain\Audio\PhraseCatalog;
use App\Http\Middleware\ResolveTenantContext;
use App\Models\AudioClip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Sales\SalesTestHelpers;
use Tests\TestCase;

/**
 * Mode simple — messages audio gardés sur le téléphone : le manifeste liste les
 * fichiers à télécharger (phrases sans variable et mots de chiffres), chacun avec
 * la date de sa prise pour que le téléphone suive les réenregistrements.
 */
class SimpleAudioManifestTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('diaba.simple_mode.enabled', true);
        Storage::fake('local');
    }

    private function act(array $setup): self
    {
        return $this->actingAs($setup['user'])
            ->withSession([ResolveTenantContext::SESSION_TENANT => $setup['tenant']->id]);
    }

    private function chooseLanguage(array $admin): void
    {
        $this->act($admin)->put('/simple/voix/langue', ['language' => 'wo'])->assertRedirect();
    }

    private function record(array $admin, string $code): void
    {
        foreach (PhraseCatalog::requiredSegments($code) as $segment) {
            $file = UploadedFile::fake()->create("{$code}-{$segment}.webm", 5, 'audio/webm');
            $this->act($admin)->post("/simple/voix/{$code}/{$segment}", ['audio' => $file])->assertRedirect();
        }
    }

    private function clip(array $admin, string $code, int $segment = 0): AudioClip
    {
        return AudioClip::withoutGlobalScopes()
            ->where(['tenant_id' => $admin['tenant']->id, 'code' => $code, 'segment_index' => $segment])
            ->firstOrFail();
    }

    private function versioned(AudioClip $clip): string
    {
        return "/simple/audio/clips/{$clip->id}?v=".$clip->updated_at->getTimestamp();
    }

    public function test_the_manifest_returns_404_when_simple_mode_is_disabled(): void
    {
        config()->set('diaba.simple_mode.enabled', false);

        $this->act($this->createTenantWithMember())->getJson('/simple/audio/manifest')->assertNotFound();
    }

    public function test_without_a_voice_the_manifest_is_empty(): void
    {
        $this->act($this->createTenantWithMember())
            ->getJson('/simple/audio/manifest')
            ->assertOk()
            ->assertExactJson(['version' => '0', 'phrases' => [], 'digits' => []]);
    }

    public function test_a_recorded_phrase_is_listed_with_a_versioned_address_and_a_cashier_can_read_it(): void
    {
        $admin = $this->createTenantWithMember();
        $cashier = $this->member($admin, 'cashier');
        $this->chooseLanguage($admin);
        $this->record($admin, 'STK-01');

        $response = $this->act($cashier)->getJson('/simple/audio/manifest')->assertOk();

        $response->assertJsonPath('phrases.STK-01.0', $this->versioned($this->clip($admin, 'STK-01')));
        $this->assertMatchesRegularExpression('#^/simple/audio/clips/[0-9a-f-]{36}\?v=\d+$#', $response->json('phrases.STK-01.0'));
        // Le navigateur doit toujours redemander le manifeste, sans le partager.
        $cache = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cache);
        $this->assertStringContainsString('no-cache', $cache);
    }

    public function test_only_recorded_phrases_are_listed(): void
    {
        $admin = $this->createTenantWithMember();
        $this->chooseLanguage($admin);
        $this->record($admin, 'STK-01');

        // Une autre phrase fixe, jamais enregistrée : absente du manifeste.
        $other = collect(PhraseCatalog::all())
            ->filter(fn (array $phrase, string $code): bool => $phrase['tokens'] === [] && $code !== 'STK-01')
            ->keys()
            ->first();

        $response = $this->act($admin)->getJson('/simple/audio/manifest')->assertOk();

        $this->assertSame(['STK-01'], array_keys($response->json('phrases')));
        $response->assertJsonMissingPath("phrases.{$other}");
    }

    public function test_the_digit_words_are_listed_and_phrases_with_a_variable_are_not(): void
    {
        $admin = $this->createTenantWithMember();
        $this->chooseLanguage($admin);
        $this->record($admin, 'PIL-01');
        foreach (PhraseCatalog::codesInGroup('Chiffres') as $code) {
            $this->record($admin, $code);
        }

        $response = $this->act($admin)->getJson('/simple/audio/manifest')->assertOk();

        // PIL-01 dit un montant : il se joue depuis le serveur, pas depuis le cache.
        $response->assertJsonMissingPath('phrases.PIL-01');
        $this->assertCount(count(PhraseCatalog::codesInGroup('Chiffres')), $response->json('digits'));
        $response->assertJsonPath('digits.CHF-01', $this->versioned($this->clip($admin, 'CHF-01')));
    }

    public function test_re_recording_a_clip_changes_its_address_and_the_manifest_version(): void
    {
        $admin = $this->createTenantWithMember();
        $this->chooseLanguage($admin);
        $this->record($admin, 'STK-01');
        $before = $this->act($admin)->getJson('/simple/audio/manifest')->json();

        $this->travel(5)->minutes();
        $this->record($admin, 'STK-01');
        $after = $this->act($admin)->getJson('/simple/audio/manifest')->json();

        $this->assertNotSame($before['phrases']['STK-01'], $after['phrases']['STK-01']);
        $this->assertNotSame($before['version'], $after['version']);
    }

    public function test_the_manifest_never_lists_another_companys_files(): void
    {
        $admin = $this->createTenantWithMember();
        $other = $this->createTenantWithMember();
        $this->chooseLanguage($admin);
        $this->record($admin, 'STK-01');

        $this->act($other)
            ->getJson('/simple/audio/manifest')
            ->assertOk()
            ->assertExactJson(['version' => '0', 'phrases' => [], 'digits' => []]);
    }
}
