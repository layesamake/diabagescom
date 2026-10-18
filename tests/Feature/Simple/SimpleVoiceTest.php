<?php

namespace Tests\Feature\Simple;

use App\Domain\Audio\PhraseCatalog;
use App\Domain\Onboarding\StartGuide;
use App\Http\Middleware\ResolveTenantContext;
use App\Models\AudioClip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Sales\SalesTestHelpers;
use Tests\TestCase;

/**
 * Mode simple — lot 4 : Messages audio.
 *
 * Réservé à l'administrateur. La disponibilité d'un haut-parleur dépend des
 * segments réellement enregistrés (App\Domain\Audio\PhraseCatalog) ; l'étape
 * 2 du guide se coche automatiquement à 71 enregistrements « cœur ».
 */
class SimpleVoiceTest extends TestCase
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

    public function test_the_page_returns_404_when_simple_mode_is_disabled(): void
    {
        config()->set('diaba.simple_mode.enabled', false);
        $admin = $this->createTenantWithMember();
        $this->act($admin)->get('/simple/voix')->assertNotFound();
    }

    public function test_a_cashier_cannot_access_voice_messages(): void
    {
        $admin = $this->createTenantWithMember();
        $v = $this->member($admin, 'cashier');
        $this->act($v)->get('/simple/voix')->assertForbidden();
    }

    public function test_an_admin_sees_the_groups_and_progress(): void
    {
        $admin = $this->createTenantWithMember();

        $this->act($admin)
            ->get('/simple/voix')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('simple/Voix')
                ->where('language', null)
                ->where('progress.total', 71)
                ->where('progress.done', 0)
                ->has('groups', 12));
    }

    public function test_choosing_a_language_is_required_before_recording(): void
    {
        $admin = $this->createTenantWithMember();

        $file = UploadedFile::fake()->create('clip.webm', 10, 'audio/webm');
        $this->act($admin)
            ->post('/simple/voix/COM-01/0', ['audio' => $file])
            ->assertStatus(422); // Pas de langue choisie encore.
    }

    public function test_an_admin_can_choose_the_language_and_record_a_segment(): void
    {
        $admin = $this->createTenantWithMember();

        $this->act($admin)->put('/simple/voix/langue', ['language' => 'wo'])->assertRedirect('/simple/voix');
        $this->assertSame('wo', $admin['tenant']->fresh()->voice_language);

        $file = UploadedFile::fake()->create('com-01.webm', 10, 'audio/webm');
        $this->act($admin)->post('/simple/voix/COM-01/0', ['audio' => $file])->assertRedirect('/simple/voix');

        $this->assertDatabaseHas('audio_clips', [
            'tenant_id' => $admin['tenant']->id,
            'code' => 'COM-01',
            'language' => 'wo',
            'segment_index' => 0,
        ]);

        $this->act($admin)
            ->get('/simple/voix')
            ->assertInertia(fn ($page) => $page->where('progress.done', 1));
    }

    public function test_an_invalid_language_code_is_rejected(): void
    {
        $admin = $this->createTenantWithMember();

        $this->act($admin)
            ->put('/simple/voix/langue', ['language' => '../../etc/passwd'])
            ->assertInvalid(['language']);
    }

    public function test_a_file_that_is_too_large_or_not_audio_is_rejected(): void
    {
        $admin = $this->createTenantWithMember();
        $this->act($admin)->put('/simple/voix/langue', ['language' => 'wo']);

        $tooLarge = UploadedFile::fake()->create('big.webm', 1024, 'audio/webm');
        $this->act($admin)->post('/simple/voix/COM-01/0', ['audio' => $tooLarge])->assertInvalid(['audio']);

        $wrongType = UploadedFile::fake()->create('notes.txt', 10, 'text/plain');
        $this->act($admin)->post('/simple/voix/COM-01/0', ['audio' => $wrongType])->assertInvalid(['audio']);

        $this->assertDatabaseMissing('audio_clips', ['code' => 'COM-01']);
    }

    public function test_recording_the_71_core_phrases_marks_the_guide_step_done(): void
    {
        $admin = $this->createTenantWithMember();
        $this->act($admin)->put('/simple/voix/langue', ['language' => 'wo']);

        foreach (PhraseCatalog::coreCodes() as $code) {
            foreach (PhraseCatalog::requiredSegments($code) as $segment) {
                $file = UploadedFile::fake()->create("{$code}-{$segment}.webm", 5, 'audio/webm');
                $this->act($admin)->post("/simple/voix/{$code}/{$segment}", ['audio' => $file])->assertRedirect();
            }
        }

        $this->act($admin);
        $summary = app(StartGuide::class)->summary($admin['user']);
        $voice = collect($summary['steps'])->firstWhere('key', 'voice');
        $this->assertSame('done', $voice['status']);
    }

    public function test_re_recording_a_segment_replaces_the_previous_file(): void
    {
        $admin = $this->createTenantWithMember();
        $this->act($admin)->put('/simple/voix/langue', ['language' => 'wo']);

        $first = UploadedFile::fake()->create('a.webm', 5, 'audio/webm');
        $this->act($admin)->post('/simple/voix/COM-01/0', ['audio' => $first]);
        $second = UploadedFile::fake()->create('b.webm', 5, 'audio/webm');
        $this->act($admin)->post('/simple/voix/COM-01/0', ['audio' => $second]);

        $this->assertSame(1, AudioClip::query()->where('code', 'COM-01')->count());
    }

    public function test_a_clip_can_only_be_played_back_by_its_own_tenant(): void
    {
        $admin = $this->createTenantWithMember();
        $other = $this->createTenantWithMember();
        $this->act($admin)->put('/simple/voix/langue', ['language' => 'wo']);
        $file = UploadedFile::fake()->create('a.webm', 5, 'audio/webm');
        $this->act($admin)->post('/simple/voix/COM-01/0', ['audio' => $file]);
        $clip = AudioClip::query()->where('code', 'COM-01')->first();

        $this->act($admin)->get("/simple/voix/clips/{$clip->id}")->assertOk();
        $this->act($other)->get("/simple/voix/clips/{$clip->id}")->assertNotFound();
    }
}
