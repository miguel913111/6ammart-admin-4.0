<?php

namespace App\Services\PaymentGateway;

use RuntimeException;

class RyftGateway implements PartnerGatewayInterface
{
    public function createAccount(object $partner): array
    {
        throw new RuntimeException('Ryft gateway onboarding not implemented yet');
    }

    public function getOnboardingUrl(object $partner, array $urls): array
    {
        throw new RuntimeException('Ryft gateway onboarding not implemented yet');
    }

    public function getAccountStatus(object $partner): array
    {
        throw new RuntimeException('Ryft gateway status not implemented yet');
    }

    public function canReceiveTransfers(object $partner): bool
    {
        return false;
    }

    public function createTransfer(object $partner, float $amount, string $currency, array $metadata): array
    {
        throw new RuntimeException('Ryft gateway transfers not implemented yet');
    }

    public function handleWebhook(array $payload): void
    {
        // TODO: implement Ryft webhook handling
    }
}
