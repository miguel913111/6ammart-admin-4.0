<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('order_transactions', function (Blueprint $table) {
            $table->decimal('platform_fee', 24, 3)->default(0)->after('admin_commission');
            $table->decimal('processing_fee', 24, 3)->default(0)->after('platform_fee');
            $table->decimal('net_store_amount', 24, 3)->default(0)->after('processing_fee');
            $table->decimal('net_delivery_amount', 24, 3)->default(0)->after('net_store_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_transactions', function (Blueprint $table) {
            $table->dropColumn([
                'platform_fee',
                'processing_fee',
                'net_store_amount',
                'net_delivery_amount',
            ]);
        });
    }
};
