<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * EPIC-03 — catalogue (taxes, unités, catégories, articles, codes-barres,
 * réglages par dépôt) et séquences de numérotation par entreprise.
 *
 * Invariants en base :
 *  - toutes les références internes passent par des clés composites (tenant_id, id) ;
 *  - SKU et code-barres uniques par entreprise, sans tenir compte de la casse ;
 *  - un seul code-barres principal par article ; arbre de catégories sans cycle ;
 *  - montants XOF en bigint ≥ 0, quantités en numeric exact (jamais de flottant).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent');

        // unaccent() n'est pas IMMUTABLE : enveloppe figée pour pouvoir l'indexer.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION diaba_search_text(value text) RETURNS text
                LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT
                AS $$ SELECT lower(public.unaccent('public.unaccent'::regdictionary, value)) $$;
        SQL);

        Schema::create('tenant_sequences', function (Blueprint $table) {
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->string('key', 64);
            $table->unsignedBigInteger('next_value')->default(1);
            $table->timestampsTz();

            $table->primary(['tenant_id', 'key']);
        });

        Schema::create('tax_rates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->string('name', 60);
            $table->unsignedInteger('rate_basis_points'); // 1800 = 18 %
            $table->boolean('is_default')->default(false);
            $table->boolean('active')->default(true);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'name']);
        });
        DB::statement('ALTER TABLE tax_rates ADD CONSTRAINT tax_rates_rate_check CHECK (rate_basis_points <= 10000)');
        DB::statement('CREATE UNIQUE INDEX tax_rates_one_default ON tax_rates (tenant_id) WHERE is_default');

        Schema::create('units', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->string('code', 12);
            $table->string('name', 40);
            $table->boolean('decimals_allowed')->default(false);
            $table->boolean('active')->default(true);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('product_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('parent_id')->nullable();
            $table->string('name', 80);
            $table->string('image_path')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'parent_id'])->references(['tenant_id', 'id'])->on('product_categories')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE product_categories ADD CONSTRAINT product_categories_not_self_parent CHECK (parent_id IS NULL OR parent_id <> id)');
        DB::statement('CREATE UNIQUE INDEX product_categories_unique_name ON product_categories (tenant_id, COALESCE(parent_id, id), diaba_search_text(name)) WHERE archived_at IS NULL');

        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('sku', 40);
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->string('type', 16);
            $table->uuid('category_id')->nullable();
            $table->uuid('unit_id');
            $table->uuid('tax_rate_id');
            $table->bigInteger('sale_price')->default(0);
            $table->bigInteger('standard_cost')->default(0);
            $table->string('image_path')->nullable();
            $table->boolean('active')->default(true);
            $table->timestampTz('archived_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'category_id'])->references(['tenant_id', 'id'])->on('product_categories')->restrictOnDelete();
            $table->foreign(['tenant_id', 'unit_id'])->references(['tenant_id', 'id'])->on('units')->restrictOnDelete();
            $table->foreign(['tenant_id', 'tax_rate_id'])->references(['tenant_id', 'id'])->on('tax_rates')->restrictOnDelete();
            $table->index(['tenant_id', 'category_id']);
        });
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_type_check CHECK (type IN ('stockable', 'service', 'consumable'))");
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_amounts_check CHECK (sale_price >= 0 AND standard_cost >= 0)');
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_sku_format_check CHECK (sku ~ '^[A-Za-z0-9][A-Za-z0-9._/-]{0,39}$')");
        DB::statement('CREATE UNIQUE INDEX products_tenant_sku_unique ON products (tenant_id, upper(sku))');
        DB::statement('CREATE INDEX products_search_trgm ON products USING gin (diaba_search_text(name) gin_trgm_ops)');

        Schema::create('product_barcodes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('product_id');
            $table->string('code', 64);
            $table->string('symbology', 16);
            $table->boolean('is_primary')->default(false);
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'product_id'])->references(['tenant_id', 'id'])->on('products')->cascadeOnDelete();
            $table->index(['tenant_id', 'product_id']);
        });
        DB::statement("ALTER TABLE product_barcodes ADD CONSTRAINT product_barcodes_symbology_check CHECK (symbology IN ('ean13', 'upca', 'code128', 'qr', 'internal'))");
        DB::statement("ALTER TABLE product_barcodes ADD CONSTRAINT product_barcodes_code_check CHECK (code ~ '^[!-~]{1,64}$')");
        // Un code-barres désigne un seul article dans l'entreprise (critère de fin d'EPIC-03).
        DB::statement('CREATE UNIQUE INDEX product_barcodes_tenant_code_unique ON product_barcodes (tenant_id, upper(code))');
        DB::statement('CREATE UNIQUE INDEX product_barcodes_one_primary ON product_barcodes (product_id) WHERE is_primary');

        Schema::create('product_warehouse_settings', function (Blueprint $table) {
            $table->uuid('tenant_id');
            $table->uuid('product_id');
            $table->uuid('warehouse_id');
            $table->decimal('reorder_point', 15, 3)->nullable();
            $table->string('preferred_location', 60)->nullable();
            $table->timestampsTz();

            $table->primary(['product_id', 'warehouse_id']);
            $table->foreign(['tenant_id', 'product_id'])->references(['tenant_id', 'id'])->on('products')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'warehouse_id'])->references(['tenant_id', 'id'])->on('warehouses')->cascadeOnDelete();
        });
        DB::statement('ALTER TABLE product_warehouse_settings ADD CONSTRAINT pws_reorder_check CHECK (reorder_point IS NULL OR reorder_point >= 0)');

        foreach (['customers', 'suppliers'] as $partyTable) {
            Schema::create($partyTable, function (Blueprint $table) use ($partyTable) {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
                $table->string('code', 20);
                $table->string('name', 160);
                $table->string('phone', 32)->nullable();
                $table->string('email')->nullable();
                $table->string('address_line', 200)->nullable();
                $table->string('city', 80)->nullable();
                $table->string('tax_number', 40)->nullable(); // NINEA
                if ($partyTable === 'customers') {
                    $table->bigInteger('credit_limit')->nullable(); // null = pas de crédit autorisé
                }
                $table->unsignedSmallInteger('payment_term_days')->default(0);
                $table->text('notes')->nullable();
                $table->boolean('active')->default(true);
                $table->timestampTz('archived_at')->nullable();
                $table->timestampsTz();

                $table->unique(['tenant_id', 'id']);
                $table->unique(['tenant_id', 'code']);
            });
            DB::statement("CREATE INDEX {$partyTable}_search_trgm ON {$partyTable} USING gin (diaba_search_text(name) gin_trgm_ops)");
            DB::statement("ALTER TABLE {$partyTable} ADD CONSTRAINT {$partyTable}_terms_check CHECK (payment_term_days <= 365)");
        }
        DB::statement('ALTER TABLE customers ADD CONSTRAINT customers_credit_check CHECK (credit_limit IS NULL OR credit_limit >= 0)');

        Schema::create('catalog_imports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->string('type', 16);
            $table->string('status', 16);
            $table->string('file_name', 200);
            $table->char('file_hash', 64);
            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('rows_valid')->default(0);
            $table->jsonb('errors')->nullable();
            $table->jsonb('summary')->nullable();
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('applied_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'created_at']);
        });
        DB::statement("ALTER TABLE catalog_imports ADD CONSTRAINT catalog_imports_status_check CHECK (status IN ('previewed', 'applied', 'discarded'))");
        DB::statement("ALTER TABLE catalog_imports ADD CONSTRAINT catalog_imports_type_check CHECK (type IN ('products'))");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION diaba_check_category_cycle() RETURNS trigger AS $$
            BEGIN
                IF NEW.parent_id IS NOT NULL AND EXISTS (
                    WITH RECURSIVE ancestors(id, parent_id) AS (
                        SELECT id, parent_id FROM product_categories WHERE id = NEW.parent_id
                        UNION ALL
                        SELECT c.id, c.parent_id FROM product_categories c JOIN ancestors a ON c.id = a.parent_id
                    )
                    SELECT 1 FROM ancestors WHERE id = NEW.id
                ) THEN
                    RAISE EXCEPTION 'category cycle detected' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER product_categories_no_cycle
                BEFORE INSERT OR UPDATE OF parent_id ON product_categories
                FOR EACH ROW EXECUTE FUNCTION diaba_check_category_cycle();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_imports');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('product_warehouse_settings');
        Schema::dropIfExists('product_barcodes');
        Schema::dropIfExists('products');
        Schema::dropIfExists('product_categories');
        Schema::dropIfExists('units');
        Schema::dropIfExists('tax_rates');
        Schema::dropIfExists('tenant_sequences');
        DB::unprepared('DROP FUNCTION IF EXISTS diaba_check_category_cycle(); DROP FUNCTION IF EXISTS diaba_search_text(text);');
    }
};
