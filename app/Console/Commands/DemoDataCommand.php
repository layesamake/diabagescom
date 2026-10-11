<?php

namespace App\Console\Commands;

use App\Domain\Cash\CashService;
use App\Domain\Inventory\MovementLine;
use App\Domain\Inventory\Quantity;
use App\Domain\Inventory\StockLedger;
use App\Domain\Sales\PointOfSale;
use App\Domain\Tenancy\CreateTenant;
use App\Domain\Tenancy\TenantContext;
use App\Models\CashRegister;
use App\Models\Customer;
use App\Models\Product;
use App\Models\TaxRate;
use App\Models\TenantMembership;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;

/**
 * Entreprise de démonstration pour travailler l'interface en local : articles,
 * stock et ventes des 7 derniers jours, passés par les services métier réels.
 * Le mot de passe est généré et écrit dans storage/app/private (jamais affiché).
 */
#[Signature('diaba:demo')]
#[Description('Crée une entreprise de démonstration (environnement local uniquement)')]
class DemoDataCommand extends Command
{
    public function handle(CreateTenant $createTenant, TenantContext $context, TwoFactorAuthenticationProvider $totp): int
    {
        if (! app()->environment('local')) {
            $this->error('Commande réservée à l’environnement local.');

            return self::FAILURE;
        }

        $email = 'demo@diaba.test';

        if (User::query()->where('email', $email)->exists()) {
            $this->info("La démonstration existe déjà ({$email}).");

            return self::SUCCESS;
        }

        // Administrateur : double authentification obligatoire, comme en production.
        $password = Str::password(20, symbols: false);
        $secret = $totp->generateSecretKey();
        $user = User::query()->forceCreate([
            'name' => 'Awa Diallo', 'email' => $email,
            'password' => $password, 'email_verified_at' => now(),
            'two_factor_secret' => encrypt($secret), 'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => encrypt(json_encode([])),
        ]);
        Storage::disk('local')->put('demo-credentials.txt', "{$email}\n{$password}\ntotp:{$secret}\n");

        $membership = $createTenant($user, [
            'legal_name' => 'Boutique Diallo SARL', 'display_name' => 'Boutique Diallo',
            'country_code' => 'SN', 'timezone' => 'Africa/Dakar',
            'warehouse_name' => 'Boutique Médina', 'warehouse_code' => 'MED', 'plan_code' => 'business_accounting',
        ]);
        $tenant = $membership->tenant;

        $context->runAs($tenant, function () use ($context, $tenant, $user): void {
            $membership = TenantMembership::query()->where('user_id', $user->id)->firstOrFail()->load('role', 'tenant');
            $warehouse = Warehouse::query()->firstOrFail();
            $context->set($tenant, $membership, $warehouse);

            $unit = Unit::query()->where('code', 'U')->value('id');
            $tax = TaxRate::query()->where('is_default', true)->value('id');
            $catalog = [
                ['RIZ25', 'Riz parfumé 25 kg', 14500, 12500], ['HUILE5', 'Huile végétale 5 L', 6500, 5400],
                ['SUCRE1', 'Sucre en poudre 1 kg', 750, 600], ['LAIT400', 'Lait en poudre 400 g', 2100, 1700],
                ['SAVON', 'Savon de Marseille', 400, 280], ['THE', 'Thé vert 250 g', 1500, 1100],
            ];
            $products = collect($catalog)->map(function (array $p) use ($unit, $tax): Product {
                $product = new Product;
                $product->forceFill(['sku' => $p[0], 'name' => $p[1], 'type' => 'stockable', 'unit_id' => $unit,
                    'tax_rate_id' => $tax, 'sale_price' => $p[2], 'standard_cost' => $p[3]])->save();

                return $product;
            });

            $start = CarbonImmutable::now()->subDays(6)->setTime(8, 0);
            CarbonImmutable::setTestNow($start);
            app(StockLedger::class)->post('initial', (string) Str::uuid(),
                $products->map(fn (Product $p) => new MovementLine($warehouse->id, $p->id, Quantity::of('60'), $p->standard_cost))->all(), $user);

            $customer = new Customer;
            $customer->forceFill(['code' => 'C-001', 'name' => 'Restaurant Teranga', 'credit_limit' => 200000, 'payment_term_days' => 0])->save();

            $session = app(CashService::class)->open(CashRegister::query()->where('warehouse_id', $warehouse->id)->firstOrFail(), 20000, $user);
            $pos = app(PointOfSale::class);

            foreach (range(0, 6) as $day) {
                foreach (range(1, 2 + ($day * 3) % 5) as $n) {
                    CarbonImmutable::setTestNow($start->addDays($day)->addHours($n));
                    $picked = $products->shuffle()->take(random_int(1, 3));
                    $lines = $picked->map(fn (Product $p) => ['product_id' => $p->id, 'quantity' => (string) random_int(1, 3), 'unit_price' => null, 'discount' => 0])->values()->all();
                    $total = collect($lines)->sum(fn (array $l) => (int) $l['quantity'] * $products->firstWhere('id', $l['product_id'])->sale_price);
                    $credit = $day === 2 && $n === 1;
                    $pos->checkout([
                        'warehouse_id' => $warehouse->id, 'customer_id' => $credit ? $customer->id : null,
                        'cash_session_id' => $session->id, 'negative_stock_reason' => null, 'lines' => $lines,
                        'tenders' => [['method' => $n % 2 ? 'cash' : 'wave', 'amount' => $credit ? intdiv($total, 2) : $total]],
                    ], $user, (string) Str::uuid());
                }
            }

            CarbonImmutable::setTestNow();
        });

        $this->info("Démonstration créée : {$email} (identifiants dans storage/app/private/demo-credentials.txt).");

        return self::SUCCESS;
    }
}
