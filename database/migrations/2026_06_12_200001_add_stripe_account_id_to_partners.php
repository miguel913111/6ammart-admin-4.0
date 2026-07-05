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
        Schema::table('stores', function (Blueprint $table) {
            $table->string('stripe_account_id', 100)->nullable()->after('mangopay_wallet_id');
        });

        Schema::table('delivery_men', function (Blueprint $table) {
            $table->string('stripe_account_id', 100)->nullable()->after('mangopay_wallet_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('stripe_account_id');
        });

        Schema::table('delivery_men', function (Blueprint $table) {
            $table->dropColumn('stripe_account_id');
        });
    }
};
