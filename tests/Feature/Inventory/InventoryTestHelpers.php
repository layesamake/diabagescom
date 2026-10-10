<?php

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\MovementLine;
use App\Domain\Inventory\Quantity;
use App\Domain\Inventory\StockLedger;
use App\Domain\Tenancy\TenantContext;
use App\Models\Product;
use App\Models\StockOperation;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait InventoryTestHelpers
{
    protected function enter(array $setup): void
    {
        app(TenantContext::class)->set($setup['tenant'], $setup['membership']->load('role', 'tenant'), $setup['warehouse']);
    }

    protected function makeProduct(array $setup, string $sku = 'RIZ', string $unit = 'U', int $cost = 1000, string $type = 'stockable'): Product
    {
        return app(TenantContext::class)->runAs($setup['tenant'], function () use ($sku, $unit, $cost, $type) {
            $product = new Product;
            $product->forceFill([
                'sku' => $sku, 'name' => "Article {$sku}", 'type' => $type,
                'unit_id' => Unit::query()->where('code', $unit)->value('id'),
                'tax_rate_id' => TaxRate::query()->where('is_default', true)->value('id'),
                'sale_price' => $cost * 2, 'standard_cost' => $cost,
            ])->save();

            return $product;
        });
    }

    protected function makeWarehouse(array $setup, string $code): Warehouse
    {
        return app(TenantContext::class)->runAs($setup['tenant'], fn () => Warehouse::query()->create(['code' => $code, 'name' => "Dépôt {$code}"]));
    }

    protected function move(array $setup, string $type, array $lines, ?string $key = null, ?string $reason = null, bool $allowNegative = false): StockOperation
    {
        $this->enter($setup);

        return app(StockLedger::class)->post(
            $type,
            $key ?? (string) Str::uuid(),
            array_map(fn (array $l) => new MovementLine($l[0], $l[1], Quantity::of($l[2]), $l[3] ?? null), $lines),
            $setup['user'],
            reason: $reason,
            allowNegative: $allowNegative,
        );
    }

    protected function balance(string $warehouseId, string $productId): array
    {
        $row = DB::table('inventory_balances')->where('warehouse_id', $warehouseId)->where('product_id', $productId)->first();

        return ['quantity' => $row?->quantity_on_hand ?? '0.000', 'average_cost' => (int) ($row?->average_cost ?? 0)];
    }
}
