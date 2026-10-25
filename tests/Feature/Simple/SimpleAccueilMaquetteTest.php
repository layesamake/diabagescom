<?php

namespace Tests\Feature\Simple;

use App\Http\Middleware\ResolveTenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Inventory\InventoryTestHelpers;
use Tests\Feature\Sales\SalesTestHelpers;
use Tests\TestCase;

/**
 * Mode simple - Accueil aligne sur la maquette 01.
 *
 * La maquette ajoute, par rapport a l'accueil initial (lot 3) :
 * - une formule "Bonjour / Bonsoir {prenom}" avec la date du jour et un
 *   badge "Essai : N jours" quand l'entreprise est en periode d'essai ;
 * - une section "Cette semaine" : sept jours, du plus ancien au plus
 *   recent, avec le total encaisse ;
 * - une alerte "a recuperer" qui remplace "caisse a fermer" pour un
 *   vendeur : montant total du par les clients en retard + nombre de
 *   clients concernes.
 *
 * Le controleur reste un passe-plat : aucune logique metier nouvelle, on
 * agrege les sommes par jour et on ramene les arrieres depuis la table
 * sales_documents, comme le fait deja AlertCenter.
 */
class SimpleAccueilMaquetteTest extends TestCase
{
    use InventoryTestHelpers;
    use RefreshDatabase;
    use SalesTestHelpers;

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

    /**
     * Insere une facture validee avec des dates choisies, sans passer par
     * PointOfSale (qui poserait validated_at = now() et declencherait le
     * trigger "frozen"). Utile pour les tests d'arriere de credit.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function seedValidatedInvoice(array $setup, array $overrides = []): void
    {
        $now = now();
        $row = array_merge([
            'id' => (string) Str::uuid(),
            'tenant_id' => $setup['tenant']->id,
            'type' => 'invoice',
            'number' => 'INV-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'status' => 'validated',
            'channel' => 'backoffice',
            'warehouse_id' => $setup['warehouse']->id,
            'customer_id' => null,
            'issue_date' => '2026-09-25',
            'due_date' => '2026-09-30',
            'total_gross' => 10_000,
            'total_discount' => 0,
            'total_excl_tax' => 10_000,
            'total_tax' => 0,
            'total' => 10_000,
            'amount_paid' => 0,
            'amount_credited' => 0,
            'payment_status' => 'unpaid',
            'created_by' => $setup['user']->id,
            'validated_by' => $setup['user']->id,
            'validated_at' => '2026-09-25 10:00:00',
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides);

        DB::table('sales_documents')->insert($row);
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
        $awa = $this->makeCustomer($setup, creditLimit: 50000, terms: 15);

        // Facture validee le 25/09 avec echeance au 30/09 et 0 F payes :
        // le trigger "frozen" empeche de mettre a jour validated_at sur
        // une facture validee, donc on insere directement avec les dates.
        $this->seedValidatedInvoice($setup, [
            'customer_id' => $awa->id,
            'total' => 10_000,
            'total_gross' => 10_000,
            'total_excl_tax' => 10_000,
            'amount_paid' => 0,
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
