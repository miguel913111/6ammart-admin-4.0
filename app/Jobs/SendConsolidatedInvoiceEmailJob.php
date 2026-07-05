<?php

namespace App\Jobs;

use App\Mail\ConsolidatedInvoiceMail;
use App\Models\InvoiceDocument;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendConsolidatedInvoiceEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [60, 300];

    public function __construct(
        private readonly int $orderId
    ) {
    }

    public function handle(): void
    {
        $order = Order::with(['store', 'delivery_man', 'customer'])->find($this->orderId);

        if (!$order) {
            Log::error('SendConsolidatedInvoiceEmailJob: order not found', ['order_id' => $this->orderId]);
            return;
        }

        $documents = InvoiceDocument::where('order_id', $order->id)
            ->where('status', 'issued')
            ->whereNotNull('file_path')
            ->get();

        if ($documents->isEmpty()) {
            Log::warning('SendConsolidatedInvoiceEmailJob: no documents to send', ['order_id' => $order->id]);
            return;
        }

        $recipient = $order->is_guest
            ? ($order->receiver_details['contact_person_email'] ?? null)
            : ($order->customer?->email ?? null);

        if (empty($recipient)) {
            Log::warning('SendConsolidatedInvoiceEmailJob: no recipient email', ['order_id' => $order->id]);
            return;
        }

        Mail::to($recipient)->send(new ConsolidatedInvoiceMail($order, $documents));

        Log::info('SendConsolidatedInvoiceEmailJob: email sent', [
            'order_id' => $order->id,
            'recipient' => $recipient,
            'documents_count' => $documents->count(),
        ]);
    }
}
