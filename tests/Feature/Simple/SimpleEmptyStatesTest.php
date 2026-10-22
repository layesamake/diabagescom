<?php

namespace Tests\Feature\Simple;

use App\Http\Middleware\ResolveTenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Sales\SalesTestHelpers;
use Tests\TestCase;

/**
 * Mode simple — couverture transverse des états vides sur les listes.
 *
 * Le cahier des charges exige pour chaque liste trois états :
 * "vide (pictogramme + une phrase), chargement, hors ligne".
 * Les vues implémentent l'état vide (Aucun article / Aucune dette / Aucune vente)
 * mais aucun test n'exerçait jusqu'ici la liste réellement vide renvoyée par
 * le contrôleur. Ces tests verrouillent la forme du payload pour qu'un
 * changement de schéma côté contrôleur casse la CI au lieu de masquer un
 * message vide côté utilisateur.
 */
class SimpleEmptyStatesTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('diaba.simple_mode.enabled', true);
    }

    private function act(array $setup): self
    {
        return $this->actingAs($setup['user'])
            ->withSession([ResolveTenantContext::SESSION_TENANT => $setup['tenant']->id]);
    }

    public function test_the_stock_screen_renders_with_an_empty_products_list(): void
    {
        // Stock manager : peut voir le stock, aucun article créé.
        $s = $this->bootstrap('warehouse_manager');

        $this->act($s)
            ->get('/simple/stock')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('simple/Stock')
                ->where('products', [])
                ->where('counters.enough', 0)
                ->where('counters.low', 0)
                ->where('counters.empty', 0));
    }

    public function test_the_customers_screen_renders_with_an_empty_debtors_list(): void
    {
        // Caissier : voit les clients ; aucun client débiteur n'existe.
        $s = $this->bootstrap('cashier');

        $this->act($s)
            ->get('/simple/clients')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('simple/Clients')
                ->where('customers', []));
    }

    public function test_the_sales_screen_renders_with_an_empty_documents_list(): void
    {
        // Caissier : aucune vente n'a encore été enregistrée aujourd'hui.
        $s = $this->bootstrap('cashier');

        $this->act($s)
            ->get('/simple/ventes')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('simple/Ventes')
                ->where('sales', [])
                ->where('count', 0)
                ->where('total', 0)
                ->where('by_method.cash', 0)
                ->where('by_method.mobile', 0)
                ->where('by_method.credit', 0)
                ->where('can.cancel', false));
    }

    /**
     * Prépare un tenant avec un dépôt et un membre du rôle demandé.
     *
     * @return array<string, mixed>
     */
    private function bootstrap(string $role): array
    {
        $setup = $this->createTenantWithMember();

        return $this->member($setup, $role);
    }
}
