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
            $table->string('eupago_transaction_id', 100)->nullable()->after('mangopay_payin_id');
            $table->string('eupago_reference', 100)->nullable()->after('eupago_transaction_id');
            $table->string('eupago_phone', 50)->nullable()->after('eupago_reference');
            $table->json('eupago_provider_response')->nullable()->after('eupago_phone');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'eupago_transaction_id',
                'eupago_reference',
                'eupago_phone',
                'eupago_provider_response',
            ]);
        });
    }
};
