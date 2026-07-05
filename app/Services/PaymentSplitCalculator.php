<?php

namespace App\Services;

use App\Models\Order;

class PaymentSplitCalculator
{
    /**
     * Calculate split for a 6amMart order.
     *
     * Example with defaults:
     *   order_amount = 13.00 €
     *   delivery_charge = 3.00 €
     *   store_gross = 10.00 €
     *   platform_fee_brutto = 0.50 € (0.41 base + 0.09 IVA)
     *   processing_fee = 13.00 * 1.5% = 0.20 €
     *   store_net = 10.00 - 0.20 - 0.50 = 9.30 €
     *   delivery_net = 3.00 €
     *   platform_net = 0.41 €
     *
     * @param  Order  $order
     * @param  float  $processingFeeRate  e.g. 0.015 for 1.5%
     * @return array
     */
    public static function forSixamMart(Order $order, float $processingFeeRate = 0.015): array
    {
        $total = self::round($order->order_amount);
        $deliveryGross = self::round($order->delivery_charge ?? 0);
        $storeGross = max(0, self::round($total - $deliveryGross));

        $platformFeeBrutto = self::round(config('services.platform_fees.sixammart', 0.50));
        $vatRate = config('services.platform_fees.vat_rate', 0.23);

        $platformFeeBase = self::round($platformFeeBrutto / (1 + $vatRate));
        $platformFeeVat = self::round($platformFeeBrutto - $platformFeeBase);

        $processingFee = self::round($storeGross * $processingFeeRate);
        $storeNet = max(0, self::round($storeGross - $processingFee - $platformFeeBrutto));
        $deliveryNet = $deliveryGross;

        return [
            'platform' => 'sixammart',
            'total' => $total,
            'store_gross' => $storeGross,
            'delivery_gross' => $deliveryGross,
            'processing_fee' => $processingFee,
            'platform_fee_brutto' => $platformFeeBrutto,
            'platform_fee_base' => $platformFeeBase,
            'platform_fee_vat' => $platformFeeVat,
            'vat_rate' => $vatRate,
            'store_net' => $storeNet,
            'delivery_net' => $deliveryNet,
            'platform_net' => $platformFeeBase,
        ];
    }

    /**
     * Calculate split for a DriveMond trip.
     *
     * Example with defaults:
     *   total_fare = 10.00 €
     *   platform_fee_brutto = 0.15 € (0.12 base + 0.03 IVA)
     *   processing_fee = 10.00 * 1.5% = 0.15 €
     *   gps_cost = 0.03 €
     *   invoice_cost = 0.02 €
     *   driver_net = 10.00 - 0.15 - 0.03 - 0.02 - 0.15 = 9.65 €
     *
     * @param  float  $totalFare
     * @param  float  $processingFeeRate  e.g. 0.015 for 1.5%
     * @param  float  $gpsCost
     * @param  float  $invoiceCost
     * @return array
     */
    public static function forDriveMond(
        float $totalFare,
        float $processingFeeRate = 0.015,
        float $gpsCost = 0.03,
        float $invoiceCost = 0.02
    ): array {
        $total = self::round($totalFare);

        $platformFeeBrutto = self::round(config('services.platform_fees.drivemond', 0.15));
        $vatRate = config('services.platform_fees.vat_rate', 0.23);

        $platformFeeBase = self::round($platformFeeBrutto / (1 + $vatRate));
        $platformFeeVat = self::round($platformFeeBrutto - $platformFeeBase);

        $processingFee = self::round($total * $processingFeeRate);
        $gpsCost = self::round($gpsCost);
        $invoiceCost = self::round($invoiceCost);

        $driverNet = max(0, self::round($total - $processingFee - $gpsCost - $invoiceCost - $platformFeeBrutto));

        return [
            'platform' => 'drivemond',
            'total' => $total,
            'processing_fee' => $processingFee,
            'gps_cost' => $gpsCost,
            'invoice_cost' => $invoiceCost,
            'platform_fee_brutto' => $platformFeeBrutto,
            'platform_fee_base' => $platformFeeBase,
            'platform_fee_vat' => $platformFeeVat,
            'vat_rate' => $vatRate,
            'driver_net' => $driverNet,
            'platform_net' => $platformFeeBase,
        ];
    }

    /**
     * Round to 2 decimal places (cent).
     */
    private static function round(float $value): float
    {
        return round($value, 2);
    }
}
