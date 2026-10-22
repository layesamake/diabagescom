<?php

namespace Tests\Feature\Simple;

use App\Domain\Cash\CashService;
use App\Http\Middleware\ResolveTenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Inventory\InventoryTestHelpers;
use Tests\Feature\Sales\SalesTestHelpers;
use Tests\TestCase;

/**
 * Mode simple — Accueil aligné sur la maquette 01.
 *
 * La maquette ajoute, par rapport à l'accueil initial (lot 3) :
 * - une formule « Bonjour / Bonsoir {prénom} » avec la date du jour et un
 *   badge « Essai : N jours » quand l'entreprise est en période d'essai ;
 * - une section « Cette semaine » : sept jours, du plus ancien au plus
 *   récent, avec le total encaissé ;
 * - une alerte « à récupérer » qui remplace « caisse à fermer » pour un
 *   vendeur : montant total dû par les clients en retard + nombre de
 *   clients concernés.
 *
 * Le contrôleur reste un passe-plat : aucune logique métier nouvelle, on
 * agrège les sommes par jour et on ramène les arriérés depuis la table
 * sales_documents, comme le fait déjà AlertCenter.
 */
class SimpleAccueilMaquetteTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;
    use InventoryTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('diaba.simple_mode.enabled', true);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-22 09:30:00'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function act(array $setup): self
    {
        return $this->actingAs($setup['user'])
            ->withSession([ResolveTenantContext::SESSION_TENANT => $setup['tenant']->id]);
    }

    public function test_the_home_page_exposes_seven_days_for_the_week_chart(): void
    {
        $setup = $this->createTenantWithMember();

        $this->act($setup)
            ->get('/simple')
            ->assertInertia(fn ($page) => $page
                ->has('week', 7)
                ->where('week.0.day', '2026-10-16')
                ->where('week.6.day', '2026-10-22'));
    }

    public function test_the_home_page_exposes_credit_to_recover_when_invoices_are_overdue(): void
    {
        $setup = $this->createTenantWithMember();
        $this->enter($setup);
        $rice = $this->makeProduct($setup);
        $this->move($setup, 'initial', [[$setup['warehouse']->id, $rice->id, '50', 1000]]);
        $session = $this->openSession($setup);
        $awa = $this->makeCustomer($setup, creditLimit: 50000, terms: 15);

        // Vente à crédit « Awa » : 5 × 2 000 = 10 000 F, mais échéance au
        // 2026-09-30 (avant aujourd'hui) et paiement partiel de 0 F.
        $this->sell($setup, $session, [[$rice->id, '5']], [], customerId: $awa->id);
        $sale = \App\Models\SalesDocument::query()->latest('validated_at')->first();
        DB::table('sales_documents')->where('id', $sale->id)->update([
            'validated_at' => '2026-09-25 10:00:00',
            'due_date' => '2026-09-30',
        ]);

        $this->act($setup)
            ->get('/simple')
            ->assertInertia(fn ($page) => $page
                ->where('alerts.credit_to_recover.amount', 10_000)
                ->where('alerts.credit_to_recover.customers', 1));
    }

    public function test_the_home_page_omits_credit_to_recover_when_there_is_no_overdue_invoice(): void
    {
        $setup = $this->createTenantWithMember();

        $this->act($setup)
            ->get('/simple')
            ->assertInertia(fn ($page) => $page
                ->where('alerts.credit_to_recover', null));
    }
}
