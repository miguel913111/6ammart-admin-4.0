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
        Schema::table('delivery_men', function (Blueprint $table) {
            $table->string('nif', 50)->nullable()->after('email');
            $table->string('iban', 50)->nullable()->after('nif');
            $table->string('ryft_account_id', 100)->nullable()->after('iban');
            $table->string('ryft_sub_account_id', 100)->nullable()->after('ryft_account_id');
            $table->string('mangopay_user_id', 100)->nullable()->after('ryft_sub_account_id');
            $table->string('mangopay_wallet_id', 100)->nullable()->after('mangopay_user_id');
            $table->text('invoice_xpress_api_token')->nullable()->after('mangopay_wallet_id');
            $table->string('invoice_xpress_series', 50)->nullable()->after('invoice_xpress_api_token');
            $table->enum('kyc_status', ['pending', 'submitted', 'verified', 'rejected'])->default('pending')->after('invoice_xpress_series');
            $table->timestamp('kyc_verified_at')->nullable()->after('kyc_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('delivery_men', function (Blueprint $table) {
            $table->dropColumn([
                'nif',
                'iban',
                'ryft_account_id',
                'ryft_sub_account_id',
                'mangopay_user_id',
                'mangopay_wallet_id',
                'invoice_xpress_api_token',
                'invoice_xpress_series',
                'kyc_status',
                'kyc_verified_at',
            ]);
        });
    }
};
