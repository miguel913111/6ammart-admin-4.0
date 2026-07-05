<?php

namespace App\Services\PaymentGateway;

use App\Models\DeliveryMan;
use App\Models\Store;

class PartnerGatewayHelper
{
    /**
     * Detect whether the partner is a store or delivery man.
     */
    public static function partnerType(object $partner): string
    {
        if ($partner instanceof Store) {
            return 'store';
        }

        if ($partner instanceof DeliveryMan) {
            return 'delivery_man';
        }

        return 'unknown';
    }

    /**
     * Persist gateway account data on the partner model.
     * Keeps legacy gateway-specific columns in sync with generic columns.
     */
    public static function saveAccountData(
        object $partner,
        string $gateway,
        string $accountId,
        ?string $accountStatus = null,
        ?string $kycStatus = null,
        ?\DateTimeInterface $kycVerifiedAt = null,
    ): void {
        $partner->payment_gateway = $gateway;
        $partner->gateway_account_id = $accountId;

        if ($accountStatus !== null) {
            $partner->gateway_account_status = $accountStatus;
        }

        if ($kycStatus !== null) {
            $partner->kyc_status = $kycStatus;
        }

        if ($kycVerifiedAt !== null) {
            $partner->kyc_verified_at = $kycVerifiedAt;
        }

        // Sync legacy columns so existing code keeps working.
        match ($gateway) {
            'stripe_connect' => $partner->stripe_account_id = $accountId,
            'ryft' => $partner->ryft_sub_account_id = $accountId,
            'mangopay' => $partner->mangopay_user_id = $accountId,
            default => null,
        };

        $partner->save();
    }

    /**
     * Read a property safely from any partner object (Eloquent or stdClass).
     */
    public static function getProperty(object $partner, string $property): mixed
    {
        if (property_exists($partner, $property) || $partner instanceof \Illuminate\Database\Eloquent\Model) {
            return $partner->{$property} ?? null;
        }

        return null;
    }

    /**
     * Update only status/KYC fields from a webhook or status check.
     */
    public static function saveStatusData(
        object $partner,
        ?string $accountStatus = null,
        ?string $kycStatus = null,
        ?\DateTimeInterface $kycVerifiedAt = null,
    ): void {
        if ($accountStatus !== null) {
            $partner->gateway_account_status = $accountStatus;
        }

        if ($kycStatus !== null) {
            $partner->kyc_status = $kycStatus;
        }

        if ($kycVerifiedAt !== null) {
            $partner->kyc_verified_at = $kycVerifiedAt;
        }

        $partner->save();
    }
}
