<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * List of legacy digital gateways that are not part of the new
     * split-payment architecture (Stripe Connect / Ryft / MangoPay).
     */
    private array $legacyGateways = [
        'ssl_commerz',
        'stripe',
        'paypal',
        'razor_pay',
        'paystack',
        'senang_pay',
        'paymob_accept',
        'flutterwave',
        'paytm',
        'paytabs',
        'liqpay',
        'mercadopago',
        'bkash',
        'fatoorah',
        'xendit',
        'amazon_pay',
        'iyzi_pay',
        'hyper_pay',
        'foloosi',
        'ccavenue',
        'pvit',
        'moncash',
        'thawani',
        'tap',
        'viva_wallet',
        'hubtel',
        'maxicash',
        'esewa',
        'swish',
        'momo',
        'payfast',
        'worldpay',
        'sixcash',
    ];

    public function up(): void
    {
        DB::table('addon_settings')
            ->where('settings_type', 'payment_config')
            ->whereIn('key_name', $this->legacyGateways)
            ->update(['is_active' => 0]);
    }

    public function down(): void
    {
        DB::table('addon_settings')
            ->where('settings_type', 'payment_config')
            ->whereIn('key_name', $this->legacyGateways)
            ->update(['is_active' => 1]);
    }
};
