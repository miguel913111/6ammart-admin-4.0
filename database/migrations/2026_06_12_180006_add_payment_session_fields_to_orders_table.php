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
            $table->string('payment_session_id', 100)->nullable()->after('ryft_payment_intent_id');
            $table->string('payment_session_client_token', 255)->nullable()->after('payment_session_id');
            $table->string('payment_session_status', 50)->nullable()->after('payment_session_client_token');
            $table->json('payment_split_payload')->nullable()->after('payment_session_status');
            $table->json('payment_provider_response')->nullable()->after('payment_split_payload');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'payment_session_id',
                'payment_session_client_token',
                'payment_session_status',
                'payment_split_payload',
                'payment_provider_response',
            ]);
        });
    }
};
