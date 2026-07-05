<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class InvoiceXpressService
{
    private string $baseUrl;
    private ?string $apiKey;
    private ?string $accountName;
    private bool $mockMode;

    public function __construct()
    {
        $config = config('services.invoicexpress');
        $this->baseUrl = rtrim($config['base_url'] ?? 'https://fernandorebelo.app.invoicexpress.com', '/');
        $this->apiKey = $config['api_key'] ?? null;
        $this->accountName = $config['account_name'] ?? 'fernandorebelo';
        $this->mockMode = filter_var($config['mock_mode'] ?? true, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Create an invoice or simplified invoice.
     *
     * @param  array  $invoiceData
     * @param  array  $credentials  ['api_key' => ..., 'account_name' => ..., 'series' => ...]
     * @return array
     */
    public function createInvoice(array $invoiceData, array $credentials = []): array
    {
        $apiKey = $credentials['api_key'] ?? $this->apiKey;
        $accountName = $credentials['account_name'] ?? $this->accountName;
        $series = $credentials['series'] ?? $invoiceData['series'] ?? 'DEFAULT';

        if (empty($apiKey)) {
            throw new \RuntimeException('InvoiceXpress API key is missing.');
        }

        $payload = [
            'invoice' => [
                'date' => $invoiceData['date'] ?? now()->format('Y-m-d'),
                'due_date' => $invoiceData['due_date'] ?? now()->format('Y-m-d'),
                'reference' => $invoiceData['reference'] ?? '',
                'serie' => $series,
                'client' => [
                    'name' => $invoiceData['client_name'],
                    'fiscal_id' => $invoiceData['client_nif'] ?? '999999990',
                    'email' => $invoiceData['client_email'] ?? '',
                    'address' => $invoiceData['client_address'] ?? '',
                    'city' => $invoiceData['client_city'] ?? '',
                    'postal_code' => $invoiceData['client_postal_code'] ?? '',
                    'country' => $invoiceData['client_country'] ?? 'PT',
                ],
                'items' => [
                    [
                        'name' => $invoiceData['item_name'],
                        'description' => $invoiceData['item_description'] ?? '',
                        'unit_price' => $invoiceData['unit_price'],
                        'quantity' => $invoiceData['quantity'] ?? 1,
                        'tax' => [
                            'name' => 'IVA',
                            'value' => $invoiceData['tax_rate'] ?? 23,
                        ],
                    ],
                ],
            ],
        ];

        // Mock mode when using default fake credentials for safety.
        if ($this->mockMode || $this->isMockKey($apiKey)) {
            return $this->mockCreateInvoice($payload, $accountName);
        }

        $url = "{$this->baseUrl}/api/v2/{$accountName}/invoices.json?api_key={$apiKey}";
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->post($url, $payload);

        if ($response->failed()) {
            throw new \RuntimeException('InvoiceXpress create invoice failed: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Download PDF of an invoice.
     *
     * @param  string  $documentId
     * @param  array  $credentials
     * @return string  Path to stored PDF
     */
    public function downloadPdf(string $documentId, array $credentials = []): string
    {
        $apiKey = $credentials['api_key'] ?? $this->apiKey;
        $accountName = $credentials['account_name'] ?? $this->accountName;

        if (empty($apiKey)) {
            throw new \RuntimeException('InvoiceXpress API key is missing.');
        }

        if ($this->mockMode || $this->isMockKey($apiKey)) {
            return $this->mockDownloadPdf($documentId);
        }

        $url = "{$this->baseUrl}/api/v2/{$accountName}/invoices/{$documentId}.pdf?api_key={$apiKey}";
        $response = Http::get($url);

        if ($response->failed()) {
            throw new \RuntimeException('InvoiceXpress download PDF failed: ' . $response->body());
        }

        $path = "invoices/" . now()->format('Y/m') . "/{$documentId}.pdf";
        Storage::disk('local')->put($path, $response->body());

        return $path;
    }

    /**
     * Validate a Portuguese NIF (basic mod11).
     */
    public function validateNif(string $nif): bool
    {
        $nif = preg_replace('/[^0-9]/', '', $nif);

        if (strlen($nif) !== 9 || !in_array($nif[0], ['1', '2', '3', '5', '6', '8', '9'])) {
            return false;
        }

        $checkDigit = 0;
        for ($i = 0; $i < 8; $i++) {
            $checkDigit += $nif[$i] * (9 - $i);
        }

        $checkDigit = 11 - ($checkDigit % 11);
        if ($checkDigit >= 10) {
            $checkDigit = 0;
        }

        return $checkDigit === (int) $nif[8];
    }

    /**
     * Determine credentials for a partner based on its NIF/series.
     */
    public function getPartnerCredentials(object $partner): array
    {
        return [
            'api_key' => $partner->invoice_xpress_api_token ?? $this->apiKey,
            'account_name' => $this->accountName,
            'series' => $partner->invoice_xpress_series ?? 'AUTO_' . ($partner->nif ?? 'DEFAULT'),
        ];
    }

    private function isMockKey(?string $apiKey): bool
    {
        return empty($apiKey) || str_contains($apiKey, 'YOUR_API_KEY');
    }

    private function mockCreateInvoice(array $payload, string $accountName): array
    {
        $documentId = 'inv_' . Str::random(12);

        return [
            'invoice' => [
                'id' => $documentId,
                'status' => 'finalized',
                'permalink' => "https://{$accountName}.app.invoicexpress.com/invoices/{$documentId}",
                'reference' => $payload['invoice']['reference'] ?? '',
                'serie' => $payload['invoice']['serie'] ?? 'DEFAULT',
                'date' => $payload['invoice']['date'] ?? now()->format('Y-m-d'),
                'total' => collect($payload['invoice']['items'] ?? [])
                    ->sum(fn ($item) => ($item['unit_price'] ?? 0) * ($item['quantity'] ?? 1)),
            ],
            '_mock' => true,
        ];
    }

    private function mockDownloadPdf(string $documentId): string
    {
        $path = "invoices/" . now()->format('Y/m') . "/{$documentId}.pdf";
        $content = "%PDF-1.4 MOCK PDF {$documentId}";
        Storage::disk('local')->put($path, $content);

        return $path;
    }
}
