<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\OrderTransaction;
use App\Services\MangoPayService;
use App\Services\PaymentSplitCalculator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessMangoPayPaymentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 120, 300];

    public function __construct(
        private readonly int $orderId,
        private readonly array $payload
    ) {
    }

    public function handle(MangoPayService $mangoPayService): void
    {
        $order = Order::find($this->orderId);

        if (!$order) {
            Log::error('ProcessMangoPayPaymentJob: order not found', ['order_id' => $this->orderId]);
            return;
        }

        $eventType = $this->payload['EventType'] ?? '';
        $resourceStatus = $this->payload['Status'] ?? $this->payload['RessourceStatus'] ?? '';

        if (!str_contains($eventType, 'PAYIN_NORMAL') || $resourceStatus !== 'SUCCEEDED') {
            Log::info('ProcessMangoPayPaymentJob: event ignored', [
                'order_id' => $order->id,
                'event_type' => $eventType,
                'status' => $resourceStatus,
            ]);
            return;
        }

        $split = PaymentSplitCalculator::forSixamMart($order);
        $transfers = $mangoPayService->createOrderTransfers($order, $split);

        $order->payment_status = 'paid';
        $order->payment_provider_response = array_merge($this->payload, ['transfers' => $transfers]);
        $order->payment_split_status = 'completed';
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

        Log::info('ProcessMangoPayPaymentJob: order updated', [
            'order_id' => $order->id,
            'split' => $split,
        ]);

        IssueInvoiceJob::dispatch($order->id);
    }
}
