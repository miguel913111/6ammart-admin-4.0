<?php

namespace App\Services\PaymentGateway;

use InvalidArgumentException;

class PartnerGatewayFactory
{
    /**
     * Available gateway implementations.
     */
    private const GATEWAYS = [
        'stripe_connect' => StripeConnectGateway::class,
        'ryft' => RyftGateway::class,
        'mangopay' => MangopayGateway::class,
    ];

    /**
     * Make a gateway instance by name.
     *
     * @throws InvalidArgumentException
     */
    public static function make(?string $gateway = null): PartnerGatewayInterface
    {
        $gateway ??= config('services.default_payment_gateway', 'stripe_connect');

        if (!isset(self::GATEWAYS[$gateway])) {
            throw new InvalidArgumentException("Unsupported payment gateway: {$gateway}");
        }

        $class = self::GATEWAYS[$gateway];

        return app($class);
    }

    /**
     * Resolve the gateway for a given partner (uses partner's stored gateway or default).
     *
     * @param  object  $partner  Store|DeliveryMan
     */
    public static function forPartner(object $partner): PartnerGatewayInterface
    {
        $gateway = $partner->payment_gateway ?? config('services.default_payment_gateway', 'stripe_connect');

        return self::make($gateway);
    }

    /**
     * List supported gateway names.
     *
     * @return array<string>
     */
    public static function supported(): array
    {
        return array_keys(self::GATEWAYS);
    }
}
