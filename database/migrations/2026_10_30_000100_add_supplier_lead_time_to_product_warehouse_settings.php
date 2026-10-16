<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_warehouse_settings', function (Blueprint $table): void {
            $table->unsignedSmallInteger('lead_time_days')->default(7)->after('reorder_point');
        });
    }

    public function down(): void
    {
        Schema::table('product_warehouse_settings', function (Blueprint $table): void {
            $table->dropColumn('lead_time_days');
        });
    }
};
