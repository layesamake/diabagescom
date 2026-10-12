<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storefront_orders', function (Blueprint $table): void {
            $table->uuid('idempotency_key')->nullable()->after('number');
            $table->unique(['storefront_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::table('storefront_orders', function (Blueprint $table): void {
            $table->dropUnique(['storefront_id', 'idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
};
