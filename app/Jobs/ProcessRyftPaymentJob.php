<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\OrderTransaction;
use App\Services\PaymentSplitCalculator;
use App\Services\RyftService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessRyftPaymentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 120, 300];

    public function __construct(
        private readonly int $orderId,
        private readonly array $payload
    ) {
    }

    public function handle(RyftService $ryftService): void
    {
        $order = Order::find($this->orderId);

        if (!$order) {
            Log::error('ProcessRyftPaymentJob: order not found', ['order_id' => $this->orderId]);
            return;
        }

        $status = $this->payload['status'] ?? $this->payload['data']['status'] ?? 'unknown';

        if ($status !== 'captured') {
            Log::info('ProcessRyftPaymentJob: payment not captured yet', [
                'order_id' => $order->id,
                'status' => $status,
            ]);
            return;
        }

        $split = PaymentSplitCalculator::forSixamMart($order);

        // Ryft executa split automaticamente quando a session é capturada.
        // Registramos as transferências esperadas para auditoria.
        $expectedTransfers = $this->buildExpectedTransfers($order, $split);

        $order->payment_status = 'paid';
        $order->payment_provider_response = array_merge($this->payload, ['expected_transfers' => $expectedTransfers]);
        $order->payment_split_status = 'completed';
        $order->payment_session_status = $status;
        $order->platform_fee = $split['platform_fee_brutto'];
        $order->processing_fee = $split['processing_fee'];
        $order->payment_provider_response = $this->payload;
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
        $transaction->status = 'paid';
        $transaction->save();

        Log::info('ProcessRyftPaymentJob: order updated', [
            'order_id' => $order->id,
            'split' => $split,
        ]);

        IssueInvoiceJob::dispatch($order->id);
    }

    private function buildExpectedTransfers(Order $order, array $split): array
    {
        $transfers = [];

        if ($order->store?->ryft_sub_account_id && $split['store_net'] > 0) {
            $transfers[] = [
                'destination' => $order->store->ryft_sub_account_id,
                'amount' => (int) round($split['store_net'] * 100),
                'currency' => 'EUR',
                'description' => 'Store payout',
            ];
        }

        if ($order->delivery_man?->ryft_sub_account_id && $split['delivery_net'] > 0) {
            $transfers[] = [
                'destination' => $order->delivery_man->ryft_sub_account_id,
                'amount' => (int) round($split['delivery_net'] * 100),
                'currency' => 'EUR',
                'description' => 'Delivery payout',
            ];
        }

        return $transfers;
    }
}
