<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\OrderTransaction;
use App\Services\PaymentSplitCalculator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Process a paid EuPago MBWay payment.
 *
 * Unlike Stripe/Ryft/MangoPay, EuPago performs the split automatically at the
 * moment the customer pays because the beneficiaries were already declared
 * when the reference was created. This job therefore records the audit trail
 * and triggers invoice issuance.
 */
class ProcessEuPagoPaymentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 120, 300];

    public function __construct(
        private readonly int $orderId,
        private readonly array $payload
    ) {
    }

    public function handle(): void
    {
        $order = Order::find($this->orderId);

        if (!$order) {
            Log::error('ProcessEuPagoPaymentJob: order not found', ['order_id' => $this->orderId]);
            return;
        }

        $transaction = $this->payload['transactions'] ?? $this->payload;
        $status = strtolower($transaction['status'] ?? ($transaction['estado'] ?? 'unknown'));

        if ($status !== 'paid' && $status !== 'pago' && $status !== 'paga') {
            Log::info('ProcessEuPagoPaymentJob: payment not paid yet', [
                'order_id' => $order->id,
                'status' => $status,
            ]);
            return;
        }

        $split = PaymentSplitCalculator::forSixamMart($order);

        try {
            $order->payment_status = 'paid';
            $order->payment_method = 'eupago';
            $order->payment_split_status = 'completed';
            $order->payment_session_status = 'succeeded';
            $order->platform_fee = $split['platform_fee_brutto'];
            $order->processing_fee = $split['processing_fee'];
            $order->payment_provider_response = array_merge(
                $order->payment_provider_response ?? [],
                ['eupago_processed' => $this->payload]
            );
            $order->save();

            $orderTransaction = OrderTransaction::firstOrNew(['order_id' => $order->id]);
            $orderTransaction->vendor_id = $order->store_id;
            $orderTransaction->delivery_man_id = $order->delivery_man_id;
            $orderTransaction->order_amount = $order->order_amount;
            $orderTransaction->store_amount = $split['store_gross'];
            $orderTransaction->admin_commission = $split['platform_fee_brutto'];
            $orderTransaction->platform_fee = $split['platform_fee_brutto'];
            $orderTransaction->processing_fee = $split['processing_fee'];
            $orderTransaction->net_store_amount = $split['store_net'];
            $orderTransaction->net_delivery_amount = $split['delivery_net'];
            $orderTransaction->delivery_charge = $split['delivery_gross'];
            $orderTransaction->zone_id = $order->zone_id;
            $orderTransaction->module_id = $order->module_id;
            $orderTransaction->status = 'paid';
            $orderTransaction->save();

            Log::info('ProcessEuPagoPaymentJob: order updated', [
                'order_id' => $order->id,
                'split' => $split,
            ]);

            IssueInvoiceJob::dispatch($order->id);
        } catch (\Throwable $e) {
            Log::error('ProcessEuPagoPaymentJob: processing failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            $order->payment_split_status = 'failed';
            $order->payment_provider_response = array_merge(
                $order->payment_provider_response ?? [],
                ['eupago_error' => $e->getMessage()]
            );
            $order->save();

            throw $e;
        }
    }
}
