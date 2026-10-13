<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Les imports CSV portent aussi sur les clients et les fournisseurs.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE catalog_imports DROP CONSTRAINT catalog_imports_type_check');
        DB::statement("ALTER TABLE catalog_imports ADD CONSTRAINT catalog_imports_type_check CHECK (type IN ('products', 'customers', 'suppliers'))");
    }

    public function down(): void
    {
        DB::statement("DELETE FROM catalog_imports WHERE type <> 'products'");
        DB::statement('ALTER TABLE catalog_imports DROP CONSTRAINT catalog_imports_type_check');
        DB::statement("ALTER TABLE catalog_imports ADD CONSTRAINT catalog_imports_type_check CHECK (type IN ('products'))");
    }
};
