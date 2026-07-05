<?php

namespace App\Services\PaymentGateway;

use App\Models\DeliveryMan;
use App\Models\Store;
use App\Services\StripeConnectService;
use Illuminate\Support\Facades\Log;

class StripeConnectGateway implements PartnerGatewayInterface
{
    public function __construct(
        private readonly StripeConnectService $stripeService,
    ) {
    }

    public function createAccount(object $partner): array
    {
        $account = $this->stripeService->createConnectedAccount($partner, 'express');

        PartnerGatewayHelper::saveAccountData(
            partner: $partner,
            gateway: 'stripe_connect',
            accountId: $account['id'],
            accountStatus: 'pending',
            kycStatus: 'pending',
        );

        return $account;
    }

    public function getOnboardingUrl(object $partner, array $urls): array
    {
        $accountId = $partner->gateway_account_id ?? $partner->stripe_account_id;

        if (empty($accountId)) {
            $account = $this->createAccount($partner);
            $accountId = $account['id'];
        }

        $refreshUrl = $urls['refresh_url'] ?? url('/partner/payment-account/stripe_connect/refresh');
        $returnUrl = $urls['return_url'] ?? url('/partner/payment-account/stripe_connect/return');

        return $this->stripeService->createAccountLink($accountId, $refreshUrl, $returnUrl);
    }

    public function getAccountStatus(object $partner): array
    {
        $accountId = PartnerGatewayHelper::getProperty($partner, 'gateway_account_id')
            ?? PartnerGatewayHelper::getProperty($partner, 'stripe_account_id');

        if (empty($accountId)) {
            return [
                'status' => 'inactive',
                'kyc_status' => 'pending',
                'kyc_verified_at' => null,
            ];
        }

        if ($this->stripeService->isMockMode()) {
            return [
                'status' => PartnerGatewayHelper::getProperty($partner, 'gateway_account_status') ?? 'active',
                'kyc_status' => PartnerGatewayHelper::getProperty($partner, 'kyc_status') ?? 'verified',
                'kyc_verified_at' => PartnerGatewayHelper::getProperty($partner, 'kyc_verified_at'),
            ];
        }

        $account = $this->stripeService->retrieveAccount($accountId);

        return $this->normalizeStatus($account);
    }

    public function canReceiveTransfers(object $partner): bool
    {
        $status = $this->getAccountStatus($partner);

        return $status['kyc_status'] === 'verified'
            && in_array($status['status'], ['active', 'pending'], true);
    }

    public function createTransfer(object $partner, float $amount, string $currency, array $metadata): array
    {
        $accountId = PartnerGatewayHelper::getProperty($partner, 'gateway_account_id')
            ?? PartnerGatewayHelper::getProperty($partner, 'stripe_account_id');

        if (empty($accountId)) {
            throw new \RuntimeException('Partner has no Stripe Connect account');
        }

        if ($this->stripeService->isMockMode()) {
            return [
                'id' => 'tr_' . \Illuminate\Support\Str::random(14),
                'amount' => (int) round($amount * 100),
                'currency' => $currency,
                'destination' => $accountId,
                'status' => 'paid',
                '_mock' => true,
            ];
        }

        $params = [
            'amount' => (int) round($amount * 100),
            'currency' => strtolower($currency),
            'destination' => $accountId,
            'transfer_group' => $metadata['transfer_group'] ?? null,
            'metadata' => $metadata,
        ];

        if (!empty($metadata['source_transaction'])) {
            $params['source_transaction'] = $metadata['source_transaction'];
        }

        return $this->stripeService->getClient()->transfers->create($params)->toArray();
    }

    public function handleWebhook(array $payload): void
    {
        $type = $payload['type'] ?? 'unknown';

        match ($type) {
            'account.updated' => $this->handleAccountUpdated($payload),
            default => Log::info('StripeConnectGateway: ignored webhook event', ['type' => $type]),
        };
    }

    private function handleAccountUpdated(array $payload): void
    {
        $account = $payload['data']['object'] ?? [];
        $accountId = $account['id'] ?? null;

        if (!$accountId) {
            Log::warning('StripeConnectGateway: account.updated without account id');
            return;
        }

        $partner = $this->findPartnerByAccountId($accountId);

        if (!$partner) {
            Log::warning('StripeConnectGateway: account.updated for unknown partner', ['account_id' => $accountId]);
            return;
        }

        $status = $this->normalizeStatus($account);

        PartnerGatewayHelper::saveStatusData(
            partner: $partner,
            accountStatus: $status['status'],
            kycStatus: $status['kyc_status'],
            kycVerifiedAt: $status['kyc_verified_at'],
        );

        Log::info('StripeConnectGateway: updated partner status from webhook', [
            'partner_type' => PartnerGatewayHelper::partnerType($partner),
            'partner_id' => $partner->id,
            'status' => $status,
        ]);
    }

    private function findPartnerByAccountId(string $accountId): ?object
    {
        $store = Store::where('stripe_account_id', $accountId)
            ->orWhere('gateway_account_id', $accountId)
            ->first();

        if ($store) {
            return $store;
        }

        return DeliveryMan::where('stripe_account_id', $accountId)
            ->orWhere('gateway_account_id', $accountId)
            ->first();
    }

    /**
     * Normalize a Stripe account object into our generic status shape.
     */
    private function normalizeStatus(array $account): array
    {
        $requirements = $account['requirements'] ?? [];
        $currentlyDue = $requirements['currently_due'] ?? [];
        $eventuallyDue = $requirements['eventually_due'] ?? [];
        $pastDue = $requirements['past_due'] ?? [];
        $disabledReason = $requirements['disabled_reason'] ?? null;

        $chargesEnabled = $account['charges_enabled'] ?? false;
        $payoutsEnabled = $account['payouts_enabled'] ?? false;

        if (!empty($account['requirements']['disabled_reason']) || $disabledReason === 'rejected.fraud') {
            $status = 'rejected';
            $kycStatus = 'rejected';
        } elseif (!$chargesEnabled || !$payoutsEnabled || !empty($currentlyDue) || !empty($pastDue)) {
            $status = 'pending';
            $kycStatus = empty($currentlyDue) && empty($eventuallyDue) ? 'submitted' : 'pending';
        } else {
            $status = 'active';
            $kycStatus = 'verified';
        }

        return [
            'status' => $status,
            'kyc_status' => $kycStatus,
            'kyc_verified_at' => $kycStatus === 'verified' ? now() : null,
            'charges_enabled' => $chargesEnabled,
            'payouts_enabled' => $payoutsEnabled,
        ];
    }
}
