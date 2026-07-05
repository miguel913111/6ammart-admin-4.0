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
            if (!Schema::hasColumn('stores', 'payment_gateway')) {
                $table->string('payment_gateway', 50)->nullable()->after('kyc_verified_at');
            }
            if (!Schema::hasColumn('stores', 'gateway_account_id')) {
                $table->string('gateway_account_id', 100)->nullable()->after('payment_gateway');
            }
            if (!Schema::hasColumn('stores', 'gateway_account_status')) {
                $table->enum('gateway_account_status', ['pending', 'active', 'restricted', 'rejected', 'inactive'])
                    ->default('inactive')
                    ->after('gateway_account_id');
            }
        });

        Schema::table('delivery_men', function (Blueprint $table) {
            if (!Schema::hasColumn('delivery_men', 'payment_gateway')) {
                $table->string('payment_gateway', 50)->nullable()->after('kyc_verified_at');
            }
            if (!Schema::hasColumn('delivery_men', 'gateway_account_id')) {
                $table->string('gateway_account_id', 100)->nullable()->after('payment_gateway');
            }
            if (!Schema::hasColumn('delivery_men', 'gateway_account_status')) {
                $table->enum('gateway_account_status', ['pending', 'active', 'restricted', 'rejected', 'inactive'])
                    ->default('inactive')
                    ->after('gateway_account_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['payment_gateway', 'gateway_account_id', 'gateway_account_status']);
        });

        Schema::table('delivery_men', function (Blueprint $table) {
            $table->dropColumn(['payment_gateway', 'gateway_account_id', 'gateway_account_status']);
        });
    }
};
