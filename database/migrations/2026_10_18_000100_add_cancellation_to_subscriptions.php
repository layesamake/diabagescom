<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->timestampTz('cancel_at')->nullable()->after('current_period_end');
            $table->index(['status', 'cancel_at']);
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropIndex('subscriptions_status_cancel_at_index');
            $table->dropColumn('cancel_at');
        });
    }
};
