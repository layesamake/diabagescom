<?php

namespace Tests\Feature\Simple;

use App\Domain\Audio\NumberWords;
use App\Domain\Audio\PhraseCatalog;
use App\Http\Middleware\ResolveTenantContext;
use App\Models\AudioClip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Sales\SalesTestHelpers;
use Tests\TestCase;

/**
 * Mode simple — haut-parleurs : un vendeur écoute les phrases enregistrées par
 * l'administrateur, et aucune icône n'est proposée pour une phrase incomplète.
 */
class SimpleAudioPlaybackTest extends TestCase
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

    /** L'administrateur enregistre tous les morceaux requis d'une phrase. */
    private function record(array $admin, string $code): void
    {
        foreach (PhraseCatalog::requiredSegments($code) as $segment) {
            $file = UploadedFile::fake()->create("{$code}-{$segment}.webm", 5, 'audio/webm');
            $this->act($admin)->post("/simple/voix/{$code}/{$segment}", ['audio' => $file])->assertRedirect();
        }
    }

    private function clipId(array $admin, string $code, int $segment): string
    {
        return (string) AudioClip::withoutGlobalScopes()
            ->where(['tenant_id' => $admin['tenant']->id, 'code' => $code, 'segment_index' => $segment])
            ->value('id');
    }

    /** @return list<string> */
    private function playable(array $user): array
    {
        $listed = [];

        // Fonction classique (pas fléchée) : une fonction fléchée capture $listed par valeur,
        // et la liste resterait vide pour l'appelant.
        $this->act($user)->get('/simple/voix')->assertInertia(function ($page) use (&$listed): void {
            $page->where('audio.disponibles', function ($list) use (&$listed): bool {
                $listed = collect($list)->all();

                return true;
            });
        });

        return $listed;
    }

    public function test_playback_returns_404_when_simple_mode_is_disabled(): void
    {
        config()->set('diaba.simple_mode.enabled', false);
        $admin = $this->createTenantWithMember();

        $this->act($admin)->getJson('/simple/audio/STK-01')->assertNotFound();
    }

    public function test_nothing_is_playable_without_a_voice_language_or_recordings(): void
    {
        $admin = $this->createTenantWithMember();

        $this->assertSame([], $this->playable($admin));
        $this->act($admin)->getJson('/simple/audio/STK-01')->assertNotFound();
    }

    public function test_a_fully_recorded_phrase_is_listed_and_a_cashier_can_play_it(): void
    {
        $admin = $this->createTenantWithMember();
        $cashier = $this->member($admin, 'cashier');
        $this->chooseLanguage($admin);
        $this->record($admin, 'STK-01');

        $this->assertContains('STK-01', $this->playable($admin));

        $clipId = $this->clipId($admin, 'STK-01', 0);
        $this->act($cashier)
            ->getJson('/simple/audio/STK-01')
            ->assertOk()
            ->assertExactJson(['clips' => ["/simple/audio/clips/{$clipId}"]]);

        // Le morceau lui-même est lisible par un vendeur, pas seulement par l'administrateur.
        $this->act($cashier)->get("/simple/audio/clips/{$clipId}")->assertOk();
    }

    public function test_a_phrase_with_a_missing_segment_is_neither_listed_nor_playable(): void
    {
        $admin = $this->createTenantWithMember();
        $this->chooseLanguage($admin);

        $code = collect(PhraseCatalog::all())
            ->filter(fn (array $p, string $c): bool => PhraseCatalog::segmentCount($c) === 2 && PhraseCatalog::requiredSegments($c) === [0, 1])
            ->keys()
            ->first();
        $file = UploadedFile::fake()->create('only-first.webm', 5, 'audio/webm');
        $this->act($admin)->post("/simple/voix/{$code}/0", ['audio' => $file])->assertRedirect();

        $this->assertNotContains($code, $this->playable($admin));
        $this->act($admin)->getJson("/simple/audio/{$code}")->assertNotFound();
    }

    public function test_an_amount_is_said_with_the_digit_words_in_order(): void
    {
        $admin = $this->createTenantWithMember();
        $this->chooseLanguage($admin);
        $this->record($admin, 'PIL-01');

        // Sans les mots de chiffres, la phrase à montant n'est pas jouable.
        $this->assertNotContains('PIL-01', $this->playable($admin));
        $this->act($admin)->getJson('/simple/audio/PIL-01?montant=1500')->assertNotFound();

        foreach (PhraseCatalog::codesInGroup('Chiffres') as $code) {
            $this->record($admin, $code);
        }

        $this->assertContains('PIL-01', $this->playable($admin));

        $expected = [$this->clipId($admin, 'PIL-01', 0)];
        foreach (NumberWords::montant(1500) as $word) {
            $expected[] = $this->clipId($admin, $word, 0);
        }
        $expected[] = $this->clipId($admin, 'PIL-01', 1);

        $this->act($admin)
            ->getJson('/simple/audio/PIL-01?montant=1500')
            ->assertOk()
            ->assertExactJson(['clips' => array_map(fn (string $id): string => "/simple/audio/clips/{$id}", $expected)]);
    }

    public function test_a_missing_or_invalid_amount_is_refused(): void
    {
        $admin = $this->createTenantWithMember();
        $this->chooseLanguage($admin);
        $this->record($admin, 'PIL-01');
        foreach (PhraseCatalog::codesInGroup('Chiffres') as $code) {
            $this->record($admin, $code);
        }

        $this->act($admin)->getJson('/simple/audio/PIL-01')->assertNotFound();
        $this->act($admin)->getJson('/simple/audio/PIL-01?montant=abc')->assertUnprocessable();
        $this->act($admin)->getJson('/simple/audio/PIL-01?montant=-5')->assertUnprocessable();
    }

    public function test_a_phrase_with_an_article_name_is_not_playable_until_names_can_be_recorded(): void
    {
        $admin = $this->createTenantWithMember();
        $this->chooseLanguage($admin);

        $code = collect(PhraseCatalog::all())
            ->filter(fn (array $p): bool => in_array(PhraseCatalog::TOKEN_ARTICLE, $p['tokens'], true))
            ->keys()
            ->first();
        $this->record($admin, $code);
        foreach (PhraseCatalog::codesInGroup('Chiffres') as $digit) {
            $this->record($admin, $digit);
        }

        $this->assertNotContains($code, $this->playable($admin));
        $this->act($admin)->getJson("/simple/audio/{$code}")->assertNotFound();
    }

    public function test_an_unknown_code_is_a_404_and_a_clip_stays_private_to_its_tenant(): void
    {
        $admin = $this->createTenantWithMember();
        $other = $this->createTenantWithMember();
        $this->chooseLanguage($admin);
        $this->record($admin, 'STK-01');
        $clipId = $this->clipId($admin, 'STK-01', 0);

        $this->act($admin)->getJson('/simple/audio/ZZZ-99')->assertNotFound();
        $this->act($other)->get("/simple/audio/clips/{$clipId}")->assertNotFound();
        $this->act($other)->getJson('/simple/audio/STK-01')->assertNotFound();
    }
}
