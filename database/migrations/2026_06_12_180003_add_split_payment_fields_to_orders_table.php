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
        Schema::table('orders', function (Blueprint $table) {
            $table->string('ryft_payment_intent_id', 100)->nullable()->after('payment_method');
            $table->string('mangopay_payin_id', 100)->nullable()->after('ryft_payment_intent_id');
            $table->string('payment_split_status', 50)->nullable()->after('mangopay_payin_id');
            $table->decimal('platform_fee', 24, 3)->default(0)->after('payment_split_status');
            $table->decimal('processing_fee', 24, 3)->default(0)->after('platform_fee');
            $table->decimal('gps_cost', 24, 3)->default(0)->after('processing_fee');
            $table->decimal('invoice_cost', 24, 3)->default(0)->after('gps_cost');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'ryft_payment_intent_id',
                'mangopay_payin_id',
                'payment_split_status',
                'platform_fee',
                'processing_fee',
                'gps_cost',
                'invoice_cost',
            ]);
        });
    }
};
