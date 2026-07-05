<?php

namespace App\Services\PaymentGateway;

interface PartnerGatewayInterface
{
    /**
     * Create a partner account on the gateway.
     *
     * @param  object  $partner  Store|DeliveryMan
     * @return array Account data (must include 'id' or 'account_id')
     */
    public function createAccount(object $partner): array;

    /**
     * Generate an onboarding URL for the partner.
     *
     * @param  object  $partner  Store|DeliveryMan
     * @param  array  $urls  ['refresh_url' => ..., 'return_url' => ...]
     * @return array ['url' => ..., ...]
     */
    public function getOnboardingUrl(object $partner, array $urls): array;

    /**
     * Fetch the current account/KYC status from the gateway.
     *
     * @param  object  $partner  Store|DeliveryMan
     * @return array ['status' => ..., 'kyc_status' => ..., 'kyc_verified_at' => ..., ...]
     */
    public function getAccountStatus(object $partner): array;

    /**
     * Determine whether the partner can receive transfers.
     *
     * @param  object  $partner  Store|DeliveryMan
     */
    public function canReceiveTransfers(object $partner): bool;

    /**
     * Send a transfer/payout to the partner account.
     *
     * @param  object  $partner  Store|DeliveryMan
     * @param  float  $amount Amount in main currency unit (e.g. EUR)
     * @param  string  $currency ISO currency code
     * @param  array  $metadata Extra metadata for the transfer
     * @return array Transfer data
     */
    public function createTransfer(object $partner, float $amount, string $currency, array $metadata): array;

    /**
     * Process a gateway-specific webhook payload.
     *
     * @param  array  $payload Decoded webhook payload
     */
    public function handleWebhook(array $payload): void;
}
