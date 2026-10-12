<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storefront_orders', function (Blueprint $table): void {
            $table->uuid('customer_id')->nullable()->after('storefront_id');
            $table->uuid('sales_document_id')->nullable()->after('customer_id');

            $table->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('customers')->restrictOnDelete();
            $table->foreign(['tenant_id', 'sales_document_id'])->references(['tenant_id', 'id'])->on('sales_documents')->restrictOnDelete();
            $table->index(['tenant_id', 'sales_document_id']);
        });
    }

    public function down(): void
    {
        Schema::table('storefront_orders', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'sales_document_id']);
            $table->dropForeign(['tenant_id', 'customer_id']);
            $table->dropIndex(['tenant_id', 'sales_document_id']);
            $table->dropColumn(['customer_id', 'sales_document_id']);
        });
    }
};
