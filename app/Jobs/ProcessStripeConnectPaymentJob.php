<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\OrderTransaction;
use App\Services\PartnerPaymentOrchestrator;
use App\Services\PaymentGateway\PartnerGatewayFactory;
use App\Services\PaymentSplitCalculator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessStripeConnectPaymentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 120, 300];

    public function __construct(
        private readonly int $orderId,
        private readonly array $payload
    ) {
    }

    public function handle(PartnerPaymentOrchestrator $orchestrator): void
    {
        $order = Order::find($this->orderId);

        if (!$order) {
            Log::error('ProcessStripeConnectPaymentJob: order not found', ['order_id' => $this->orderId]);
            return;
        }

        $status = $this->payload['data']['object']['status']
            ?? $this->payload['status']
            ?? 'unknown';

        if ($status !== 'succeeded') {
            Log::info('ProcessStripeConnectPaymentJob: payment not succeeded yet', [
                'order_id' => $order->id,
                'status' => $status,
            ]);
            return;
        }

        $split = PaymentSplitCalculator::forSixamMart($order);
        $transferGroup = 'order_' . $order->id;
        $sourceTransaction = $this->payload['data']['object']['latest_charge']
            ?? $this->payload['data']['object']['charges']['data'][0]['id']
            ?? null;
        $transfers = [];
        $kycPending = [];

        try {
            $store = $order->store;
            if ($store && $split['store_net'] > 0) {
                if ($orchestrator->status($store)['can_receive_transfers']) {
                    $transfers[] = $orchestrator->transfer(
                        partner: $store,
                        amount: $split['store_net'],
                        currency: 'eur',
                        metadata: [
                            'order_id' => $order->id,
                            'type' => 'store_payout',
                            'transfer_group' => $transferGroup,
                        ],
                        sourceTransaction: $sourceTransaction,
                    );
                } else {
                    $kycPending[] = 'store';
                    Log::info('ProcessStripeConnectPaymentJob: store KYC not ready', [
                        'order_id' => $order->id,
                        'store_id' => $store->id,
                    ]);
                }
            }

            $deliveryMan = $order->delivery_man;
            if ($deliveryMan && $split['delivery_net'] > 0) {
                if ($orchestrator->status($deliveryMan)['can_receive_transfers']) {
                    $transfers[] = $orchestrator->transfer(
                        partner: $deliveryMan,
                        amount: $split['delivery_net'],
                        currency: 'eur',
                        metadata: [
                            'order_id' => $order->id,
                            'type' => 'delivery_payout',
                            'transfer_group' => $transferGroup,
                        ],
                        sourceTransaction: $sourceTransaction,
                    );
                } else {
                    $kycPending[] = 'delivery_man';
                    Log::info('ProcessStripeConnectPaymentJob: delivery man KYC not ready', [
                        'order_id' => $order->id,
                        'delivery_man_id' => $deliveryMan->id,
                    ]);
                }
            }

            $order->payment_status = 'paid';
            $order->payment_method = 'stripe_connect';
            $order->payment_split_status = empty($kycPending) ? 'completed' : 'pending_kyc';
            $order->payment_session_status = $status;
            $order->platform_fee = $split['platform_fee_brutto'];
            $order->processing_fee = $split['processing_fee'];
            $order->payment_provider_response = array_merge($this->payload, [
                'transfers' => $transfers,
                'kyc_pending_for' => $kycPending,
            ]);
            $order->save();

            $transaction = OrderTransaction::firstOrNew(['order_id' => $order->id]);
            $transaction->vendor_id = $order->store_id;
            $transaction->delivery_man_id = $order->delivery_man_id;
            $transaction->order_amount = $order->order_amount;
            $transaction->store_amount = $split['store_gross'];
            $transaction->admin_commission = $split['platform_fee_brutto'];
            $transaction->platform_fee = $split['platform_fee_brutto'];
            $transaction->processing_fee = $split['processing_fee'];
            $transaction->net_store_amount = $split['store_net'];
            $transaction->net_delivery_amount = $split['delivery_net'];
            $transaction->delivery_charge = $split['delivery_gross'];
            $transaction->zone_id = $order->zone_id;
            $transaction->module_id = $order->module_id;
            $transaction->status = empty($kycPending) ? 'paid' : 'pending_kyc';
            $transaction->save();

            Log::info('ProcessStripeConnectPaymentJob: order updated', [
                'order_id' => $order->id,
                'split' => $split,
                'transfers' => $transfers,
                'kyc_pending_for' => $kycPending,
            ]);

            if (empty($kycPending)) {
                IssueInvoiceJob::dispatch($order->id);
            }
        } catch (\Throwable $e) {
            Log::error('ProcessStripeConnectPaymentJob: transfer failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            $order->payment_split_status = 'failed';
            $order->payment_provider_response = array_merge($this->payload, [
                'error' => $e->getMessage(),
            ]);
            $order->save();

            throw $e;
        }
    }
}
