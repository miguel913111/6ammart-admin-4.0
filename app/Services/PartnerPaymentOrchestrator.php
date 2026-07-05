<?php

namespace App\Services;

use App\Models\DeliveryMan;
use App\Models\Store;
use App\Services\PaymentGateway\PartnerGatewayFactory;
use App\Services\PaymentGateway\PartnerGatewayHelper;
use Illuminate\Http\Request;
use InvalidArgumentException;

class PartnerPaymentOrchestrator
{
    /**
     * Resolve the partner profile for an authenticated user.
     * Supported user types: store, delivery_man.
     */
    public function resolvePartner(object $user): ?object
    {
        if (!isset($user->user_type)) {
            return null;
        }

        return match ($user->user_type) {
            'store' => Store::where('vendor_id', $user->id)->first(),
            'delivery_man' => DeliveryMan::where('user_id', $user->id)->first(),
            default => null,
        };
    }

    /**
     * Get the current payment account status for a partner.
     */
    public function status(object $partner): array
    {
        $gateway = PartnerGatewayFactory::forPartner($partner);
        $remote = $gateway->getAccountStatus($partner);

        return [
            'gateway' => $partner->payment_gateway ?? config('services.default_payment_gateway', 'stripe_connect'),
            'account_id' => $partner->gateway_account_id ?? $this->legacyAccountId($partner),
            'account_status' => $remote['status'] ?? $partner->gateway_account_status ?? 'inactive',
            'kyc_status' => $remote['kyc_status'] ?? $partner->kyc_status ?? 'pending',
            'kyc_verified_at' => $remote['kyc_verified_at'] ?? $partner->kyc_verified_at,
            'can_receive_transfers' => $gateway->canReceiveTransfers($partner),
        ];
    }

    /**
     * Start onboarding for a partner on the requested gateway.
     *
     * @throws InvalidArgumentException
     */
    public function onboard(object $partner, ?string $gateway = null, array $urls = []): array
    {
        $gatewayName = $gateway ?? $partner->payment_gateway ?? config('services.default_payment_gateway', 'stripe_connect');

        if (!in_array($gatewayName, PartnerGatewayFactory::supported(), true)) {
            throw new InvalidArgumentException("Unsupported payment gateway: {$gatewayName}");
        }

        // If partner already has an account on a different gateway, keep using it.
        if (!empty($partner->payment_gateway) && $partner->payment_gateway !== $gatewayName) {
            $gatewayName = $partner->payment_gateway;
        }

        $gateway = PartnerGatewayFactory::make($gatewayName);

        // Create account if not present.
        if (empty($partner->gateway_account_id) && empty($this->legacyAccountId($partner))) {
            $gateway->createAccount($partner);
        }

        $link = $gateway->getOnboardingUrl($partner, $urls);

        return [
            'gateway' => $gatewayName,
            'account_id' => $partner->gateway_account_id ?? $this->legacyAccountId($partner),
            'onboarding_url' => $link['url'] ?? null,
            'status' => $this->status($partner),
        ];
    }

    /**
     * Send a transfer to a partner using the correct gateway.
     */
    public function transfer(object $partner, float $amount, string $currency, array $metadata, ?string $sourceTransaction = null): array
    {
        $gateway = PartnerGatewayFactory::forPartner($partner);

        if (!$gateway->canReceiveTransfers($partner)) {
            throw new \RuntimeException('Partner account is not ready to receive transfers');
        }

        if ($sourceTransaction !== null) {
            $metadata['source_transaction'] = $sourceTransaction;
        }

        return $gateway->createTransfer($partner, $amount, $currency, $metadata);
    }

    /**
     * Extract refresh/return URLs from a request.
     */
    public function onboardingUrls(Request $request): array
    {
        return [
            'refresh_url' => $request->input('refresh_url', url('/partner/payment-account/return')),
            'return_url' => $request->input('return_url', url('/partner/payment-account/return')),
        ];
    }

    /**
     * Return a legacy account id for backward compatibility.
     */
    private function legacyAccountId(object $partner): ?string
    {
        $gateway = $partner->payment_gateway ?? config('services.default_payment_gateway', 'stripe_connect');

        return match ($gateway) {
            'stripe_connect' => $partner->stripe_account_id ?? null,
            'ryft' => $partner->ryft_sub_account_id ?? $partner->ryft_account_id ?? null,
            'mangopay' => $partner->mangopay_user_id ?? null,
            default => null,
        };
    }
}
