<?php

namespace Tests\Feature\Simple;

use App\Http\Middleware\ResolveTenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Sales\SalesTestHelpers;
use Tests\TestCase;

/**
 * Mode simple — lot 4, alignement de l'écran Messages audio sur la maquette 05a.
 *
 * La maquette montre un sous-titre cadenas « Administrateur » sous le titre,
 * le nom de la langue choisie (« Wolof ») et des onglets de groupes portant
 * le nombre de phrases restantes. Le contrôleur doit donc fournir ces trois
 * informations en plus des groupes et de la progression existants.
 */
class SimpleVoiceMaquetteTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('diaba.simple_mode.enabled', true);
    }

    /**
     * @return array{tenant: mixed, user: mixed}
     */
    private function admin(): array
    {
        $setup = $this->createTenantWithMember();

        return $this->member($setup, 'admin');
    }

    private function act(array $setup): self
    {
        return $this->actingAs($setup['user'])
            ->withSession([ResolveTenantContext::SESSION_TENANT => $setup['tenant']->id]);
    }

    public function test_the_page_carries_the_administrator_subtitle(): void
    {
        $a = $this->admin();

        $this->act($a)
            ->get('/simple/voix')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('simple/Voix')
                ->where('role', 'Administrateur')
                ->where('acces_administrateur', true));
    }

    public function test_the_page_exposes_the_language_display_name(): void
    {
        $a = $this->admin();

        $this->act($a)
            ->put('/simple/voix/langue', ['language' => 'wo'])
            ->assertRedirect();

        $this->act($a)
            ->get('/simple/voix')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('language', 'wo')
                ->where('language_name', 'Wolof')
                ->where('voices_available', true));
    }

    public function test_every_group_reports_its_recorded_and_total_phrase_counts(): void
    {
        $a = $this->admin();

        $this->act($a)->put('/simple/voix/langue', ['language' => 'wo']);

        $this->act($a)
            ->get('/simple/voix')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('groups', 12)
                ->has('groups.0.name')
                ->has('groups.0.restants')
                ->has('groups.0.total')
                ->where('groups.0.name', 'Communs')
                // Aucun enregistrement pour l'instant : les 12 phrases du
                // groupe Communs restent à faire.
                ->where('groups.0.restants', 12)
                ->where('groups.0.total', 12));
    }

    public function test_the_language_sheet_offers_the_seven_named_languages(): void
    {
        $a = $this->admin();

        $this->act($a)
            ->get('/simple/voix')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('languages', 7)
                ->where('languages.0.code', 'wo')
                ->where('languages.0.name', 'Wolof')
                ->where('languages.1.name', 'Pulaar (peul)')
                ->where('languages.6.code', 'autre')
                ->where('languages.6.name', 'Autre langue'));
    }
}
