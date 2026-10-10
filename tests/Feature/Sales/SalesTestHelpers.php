<?php

namespace Tests\Feature\Sales;

use App\Domain\Cash\CashService;
use App\Domain\Sales\PointOfSale;
use App\Domain\Tenancy\TenantContext;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Customer;
use App\Models\SalesDocument;
use App\Models\Supplier;
use App\Models\User;
use Database\Factories\TenantFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Inventory\InventoryTestHelpers;

trait SalesTestHelpers
{
    use InventoryTestHelpers;

    /**
     * Membre supplémentaire affecté au dépôt principal, avec contexte prêt à l'emploi.
     */
    protected function member(array $setup, string $role): array
    {
        $user = User::factory()->withTwoFactor()->create();
        $membership = TenantFactory::addMember($setup['tenant'], $user, $role);
        DB::table('warehouse_memberships')->insert(['tenant_id' => $setup['tenant']->id, 'warehouse_id' => $setup['warehouse']->id, 'membership_id' => $membership->id]);

        return [...$setup, 'user' => $user, 'membership' => $membership];
    }

    protected function openSession(array $setup, int $opening = 10000): CashSession
    {
        $this->enter($setup);
        $register = CashRegister::query()->where('warehouse_id', $setup['warehouse']->id)->firstOrFail();

        return app(CashService::class)->open($register, $opening, $setup['user']);
    }

    protected function makeCustomer(array $setup, ?int $creditLimit = null, int $terms = 0): Customer
    {
        return app(TenantContext::class)->runAs($setup['tenant'], function () use ($creditLimit, $terms) {
            $customer = new Customer;
            $customer->forceFill(['code' => 'C-'.Str::random(5), 'name' => 'Client Ndiaye', 'credit_limit' => $creditLimit, 'payment_term_days' => $terms])->save();

            return $customer;
        });
    }

    protected function makeSupplier(array $setup): Supplier
    {
        return app(TenantContext::class)->runAs($setup['tenant'], function () {
            $supplier = new Supplier;
            $supplier->forceFill(['code' => 'F-'.Str::random(5), 'name' => 'Fournisseur Sow', 'payment_term_days' => 30])->save();

            return $supplier;
        });
    }

    /**
     * @param  list<array{0: string, 1: string, 2?: int|null, 3?: int}>  $lines  [product_id, quantity, unit_price, discount]
     * @param  list<array{0: string, 1: int}>  $tenders  [method, amount]
     */
    protected function sell(array $setup, CashSession $session, array $lines, array $tenders, ?string $customerId = null, ?string $key = null, ?string $negativeReason = null): SalesDocument
    {
        $this->enter($setup);

        return app(PointOfSale::class)->checkout([
            'warehouse_id' => $setup['warehouse']->id,
            'customer_id' => $customerId,
            'cash_session_id' => $session->id,
            'negative_stock_reason' => $negativeReason,
            'lines' => array_map(fn (array $l) => ['product_id' => $l[0], 'quantity' => $l[1], 'unit_price' => $l[2] ?? null, 'discount' => $l[3] ?? 0], $lines),
            'tenders' => array_map(fn (array $t) => ['method' => $t[0], 'amount' => $t[1]], $tenders),
        ], $setup['user'], $key ?? (string) Str::uuid());
    }
}
