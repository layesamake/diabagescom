<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->boolean('online_store_enabled')->default(false)->after('accounting_enabled');
        });

        Schema::create('storefronts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('slug', 100)->unique();
            $table->boolean('enabled')->default(false);
            $table->string('tagline', 160)->nullable();
            $table->text('description')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('whatsapp', 32)->nullable();
            $table->string('address', 200)->nullable();
            $table->string('opening_hours', 500)->nullable();
            $table->boolean('accepts_orders')->default(true);
            $table->boolean('delivery_enabled')->default(false);
            $table->timestampsTz();
        });

        Schema::create('storefront_products', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('storefront_id');
            $table->uuid('product_id');
            $table->boolean('visible')->default(false);
            $table->string('public_name', 160)->nullable();
            $table->text('public_description')->nullable();
            $table->bigInteger('public_price')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('storefront_id')->references('id')->on('storefronts')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'product_id'])->references(['tenant_id', 'id'])->on('products')->cascadeOnDelete();
            $table->unique(['storefront_id', 'product_id']);
            $table->index(['storefront_id', 'visible', 'sort_order']);
        });
        DB::statement('ALTER TABLE storefront_products ADD CONSTRAINT storefront_products_price_check CHECK (public_price IS NULL OR public_price >= 0)');

        Schema::create('storefront_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('storefront_id');
            $table->string('number', 32);
            $table->string('status', 24)->default('new');
            $table->string('customer_name', 160);
            $table->string('customer_phone', 32);
            $table->string('customer_email')->nullable();
            $table->string('delivery_address', 300)->nullable();
            $table->string('fulfillment', 16)->default('pickup');
            $table->text('notes')->nullable();
            $table->bigInteger('total')->default(0);
            $table->char('currency', 3)->default('XOF');
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('storefront_id')->references('id')->on('storefronts')->cascadeOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status', 'created_at']);
        });
        DB::statement("ALTER TABLE storefront_orders ADD CONSTRAINT storefront_orders_status_check CHECK (status IN ('new', 'confirmed', 'preparing', 'ready', 'completed', 'rejected', 'cancelled'))");
        DB::statement("ALTER TABLE storefront_orders ADD CONSTRAINT storefront_orders_fulfillment_check CHECK (fulfillment IN ('pickup', 'delivery'))");
        DB::statement('ALTER TABLE storefront_orders ADD CONSTRAINT storefront_orders_total_check CHECK (total >= 0)');

        Schema::create('storefront_order_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('order_id');
            $table->uuid('product_id')->nullable();
            $table->string('product_name', 160);
            $table->string('sku', 40);
            $table->decimal('quantity', 15, 3);
            $table->bigInteger('unit_price');
            $table->bigInteger('subtotal');
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'order_id'])->references(['tenant_id', 'id'])->on('storefront_orders')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'product_id'])->references(['tenant_id', 'id'])->on('products')->nullOnDelete();
            $table->index(['tenant_id', 'order_id']);
        });
        DB::statement('ALTER TABLE storefront_order_items ADD CONSTRAINT storefront_order_items_quantity_check CHECK (quantity > 0)');
        DB::statement('ALTER TABLE storefront_order_items ADD CONSTRAINT storefront_order_items_amounts_check CHECK (unit_price >= 0 AND subtotal >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_order_items');
        Schema::dropIfExists('storefront_orders');
        Schema::dropIfExists('storefront_products');
        Schema::dropIfExists('storefronts');
        Schema::table('plans', function (Blueprint $table): void {
            $table->dropColumn('online_store_enabled');
        });
    }
};
