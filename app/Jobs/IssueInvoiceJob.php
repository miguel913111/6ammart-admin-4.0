<?php

namespace App\Jobs;

use App\Mail\ConsolidatedInvoiceMail;
use App\Models\InvoiceDocument;
use App\Models\InvoiceJob;
use App\Models\Order;
use App\Services\InvoiceXpressService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class IssueInvoiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 120, 300];

    public function __construct(
        private readonly int $orderId
    ) {
    }

    public function handle(InvoiceXpressService $invoiceService): void
    {
        $order = Order::with(['store', 'delivery_man', 'customer'])->find($this->orderId);

        if (!$order) {
            Log::error('IssueInvoiceJob: order not found', ['order_id' => $this->orderId]);
            return;
        }

        $job = InvoiceJob::firstOrCreate(
            ['order_id' => $order->id],
            ['status' => 'processing']
        );

        if ($job->status === 'completed') {
            return;
        }

        $job->update(['status' => 'processing', 'error_message' => null]);

        try {
            $this->issueStoreInvoice($order, $invoiceService);
            $this->issueDeliveryInvoice($order, $invoiceService);
            $this->issuePlatformInvoice($order, $invoiceService);

            $job->update(['status' => 'completed', 'processed_at' => now()]);

            SendConsolidatedInvoiceEmailJob::dispatch($order->id);
        } catch (\Throwable $e) {
            $job->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'retry_count' => $job->retry_count + 1,
            ]);

            Log::error('IssueInvoiceJob: failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function issueStoreInvoice(Order $order, InvoiceXpressService $service): void
    {
        $store = $order->store;
        if (!$store) {
            return;
        }

        $document = InvoiceDocument::firstOrNew([
            'order_id' => $order->id,
            'document_type' => 'store',
        ]);

        if ($document->status === 'issued') {
            return;
        }

        $credentials = $service->getPartnerCredentials($store);

        $result = $service->createInvoice([
            'date' => now()->format('Y-m-d'),
            'reference' => 'ORDER-' . $order->id,
            'series' => $credentials['series'],
            'client_name' => $order->is_guest
                ? ($order->receiver_details['contact_person_name'] ?? 'Cliente')
                : ($order->customer?->f_name . ' ' . $order->customer?->l_name),
            'client_nif' => $order->is_guest
                ? '999999990'
                : null,
            'client_email' => $order->is_guest
                ? ($order->receiver_details['contact_person_email'] ?? '')
                : ($order->customer?->email ?? ''),
            'item_name' => 'Venda de produtos / refeição',
            'item_description' => 'Pedido #' . $order->id,
            'unit_price' => round($order->order_amount - ($order->delivery_charge ?? 0), 2),
            'quantity' => 1,
            'tax_rate' => 23,
        ], $credentials);

        $document->fill([
            'nif' => $store->nif,
            'series' => $credentials['series'],
            'external_id' => $result['invoice']['id'] ?? null,
            'reference_number' => $result['invoice']['reference'] ?? null,
            'status' => 'issued',
        ]);
        $document->save();

        if (!empty($result['invoice']['id'])) {
            $path = $service->downloadPdf($result['invoice']['id'], $credentials);
            $document->update(['file_path' => $path]);
        }
    }

    private function issueDeliveryInvoice(Order $order, InvoiceXpressService $service): void
    {
        $deliveryMan = $order->delivery_man;
        if (!$deliveryMan) {
            return;
        }

        $document = InvoiceDocument::firstOrNew([
            'order_id' => $order->id,
            'document_type' => 'delivery',
        ]);

        if ($document->status === 'issued') {
            return;
        }

        $credentials = $service->getPartnerCredentials($deliveryMan);

        $result = $service->createInvoice([
            'date' => now()->format('Y-m-d'),
            'reference' => 'ORDER-' . $order->id,
            'series' => $credentials['series'],
            'client_name' => $order->is_guest
                ? ($order->receiver_details['contact_person_name'] ?? 'Cliente')
                : ($order->customer?->f_name . ' ' . $order->customer?->l_name),
            'client_nif' => '999999990',
            'client_email' => $order->is_guest
                ? ($order->receiver_details['contact_person_email'] ?? '')
                : ($order->customer?->email ?? ''),
            'item_name' => 'Serviço de entrega',
            'item_description' => 'Entrega do pedido #' . $order->id,
            'unit_price' => round($order->delivery_charge ?? 0, 2),
            'quantity' => 1,
            'tax_rate' => 23,
        ], $credentials);

        $document->fill([
            'nif' => $deliveryMan->nif,
            'series' => $credentials['series'],
            'external_id' => $result['invoice']['id'] ?? null,
            'reference_number' => $result['invoice']['reference'] ?? null,
            'status' => 'issued',
        ]);
        $document->save();

        if (!empty($result['invoice']['id'])) {
            $path = $service->downloadPdf($result['invoice']['id'], $credentials);
            $document->update(['file_path' => $path]);
        }
    }

    private function issuePlatformInvoice(Order $order, InvoiceXpressService $service): void
    {
        $store = $order->store;
        if (!$store) {
            return;
        }

        $document = InvoiceDocument::firstOrNew([
            'order_id' => $order->id,
            'document_type' => 'platform',
        ]);

        if ($document->status === 'issued') {
            return;
        }

        $platformFee = round(config('services.platform_fees.sixammart', 0.50), 2);

        $result = $service->createInvoice([
            'date' => now()->format('Y-m-d'),
            'reference' => 'ORDER-' . $order->id,
            'series' => 'PLAT',
            'client_name' => $store->name ?? 'Restaurante',
            'client_nif' => $store->nif ?? '999999990',
            'client_email' => $store->email ?? '',
            'item_name' => 'Taxa de serviço da plataforma',
            'item_description' => 'Comissão sobre o pedido #' . $order->id,
            'unit_price' => round($platformFee / 1.23, 2),
            'quantity' => 1,
            'tax_rate' => 23,
        ]);

        $document->fill([
            'nif' => config('services.invoicexpress.account_name'),
            'series' => 'PLAT',
            'external_id' => $result['invoice']['id'] ?? null,
            'reference_number' => $result['invoice']['reference'] ?? null,
            'status' => 'issued',
        ]);
        $document->save();

        if (!empty($result['invoice']['id'])) {
            $path = $service->downloadPdf($result['invoice']['id']);
            $document->update(['file_path' => $path]);
        }
    }
}
