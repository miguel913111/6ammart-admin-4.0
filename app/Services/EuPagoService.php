<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * EuPago REST API integration for MBWay and split payments.
 *
 * The service supports a mock mode for local development. When mock mode is
 * enabled, no HTTP calls are made and a fake reference is returned.
 *
 * @see https://eupago.readme.io/reference/api-eupago
 */
class EuPagoService
{
    private string $env;
    private ?string $apiKey;
    private ?string $clientId;
    private ?string $clientSecret;
    private ?string $webhookSecret;
    private ?string $storeExternKey;
    private ?string $deliveryExternKey;
    private ?string $platformExternKey;
    private bool $mockMode;
    private string $baseUrl;
    private string $callbackPath;

    public function __construct()
    {
        $config = config('services.eupago');

        $this->env = $config['env'] ?? 'sandbox';
        $this->apiKey = $config['api_key'] ?? null;
        $this->clientId = $config['client_id'] ?? null;
        $this->clientSecret = $config['client_secret'] ?? null;
        $this->webhookSecret = $config['webhook_secret'] ?? null;
        $this->storeExternKey = $config['store_extern_key'] ?? null;
        $this->deliveryExternKey = $config['delivery_extern_key'] ?? null;
        $this->platformExternKey = $config['platform_extern_key'] ?? null;
        $this->mockMode = filter_var($config['mock_mode'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $this->baseUrl = rtrim($config['base_url'] ?? 'https://sandbox.eupago.pt', '/');
        $this->callbackPath = $config['callback_path'] ?? '/webhooks/eupago';
    }

    public function isMockMode(): bool
    {
        return $this->mockMode;
    }

    /**
     * Full callback URL sent to EuPago so they can notify us when the
     * customer pays the MBWay reference.
     */
    public function callbackUrl(): string
    {
        $appUrl = rtrim(config('app.url', 'http://localhost'), '/');

        return $appUrl . $this->callbackPath;
    }

    /**
     * Create an MBWay payment request with automatic split between store,
     * delivery man and platform.
     *
     * @param  Order  $order
     * @param  string  $phone  Mobile number in international format (e.g. 351910000000)
     * @param  array  $split  Result from PaymentSplitCalculator::forSixamMart()
     * @return array Response from EuPago API
     */
    public function createMbwayPayment(Order $order, string $phone, array $split): array
    {
        if ($this->mockMode) {
            return $this->mockCreateMbwayPayment($order, $phone, $split);
        }

        $this->guardConfiguration();

        $payload = $this->buildSplitPaymentPayload($order, $phone, $split);

        Log::info('EuPagoService: creating MBWay split payment', [
            'order_id' => $order->id,
            'amount' => $split['total'],
            'phone' => $this->maskPhone($phone),
        ]);

        $response = Http::withHeaders([
            'ApiKey' => $this->apiKey,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])->post("{$this->baseUrl}/api/v1/split-payments/mbway", $payload);

        if ($response->failed()) {
            Log::error('EuPagoService: MBWay split payment failed', [
                'order_id' => $order->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new \RuntimeException('EuPago MBWay payment failed: ' . $response->body());
        }

        $data = $response->json() ?? [];

        Log::info('EuPagoService: MBWay split payment created', [
            'order_id' => $order->id,
            'reference' => $data['reference'] ?? ($data['referencia'] ?? null),
        ]);

        return $data;
    }

    /**
     * Retrieve transaction information from EuPago.
     */
    public function getTransaction(string $transactionId): array
    {
        if ($this->mockMode) {
            return [
                'trid' => $transactionId,
                'status' => 'Paid',
                'method' => 'Mbway',
                '_mock' => true,
            ];
        }

        $token = $this->getBearerToken();

        $response = Http::withToken($token, 'Bearer')
            ->get("{$this->baseUrl}/api/management/v1.02/payouts/transactions/", [
                'trid' => $transactionId,
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('EuPago transaction lookup failed: ' . $response->body());
        }

        return $response->json() ?? [];
    }

    /**
     * Validate the signature of an incoming EuPago webhook.
     *
     * In mock mode any payload is accepted. In production the webhook secret
     * must be configured; otherwise webhooks are rejected for safety.
     */
    public function validateWebhook(Request $request): bool
    {
        if ($this->mockMode) {
            return true;
        }

        if (empty($this->webhookSecret)) {
            return false;
        }

        $signature = $request->header('X-Signature');
        $payload = $request->getContent();

        if (empty($signature) || empty($payload)) {
            return false;
        }

        $expected = hash_hmac('sha256', $payload, $this->webhookSecret, true);

        return hash_equals(base64_encode($expected), $signature);
    }

    /**
     * Build the payload expected by the EuPago split-payments endpoint.
     *
     * NOTE: The exact field names may need to be adjusted once EuPago
     * provides the official integration guide for your account. The structure
     * below is based on the public API documentation.
     */
    private function buildSplitPaymentPayload(Order $order, string $phone, array $split): array
    {
        return [
            'method' => 'mbway',
            'amount' => (float) $split['total'],
            'identifier' => (string) $order->id,
            'callback_url' => $this->callbackUrl(),
            'phone' => $phone,
            'description' => '6amMart order #' . $order->id,
            'beneficiaries' => $this->buildBeneficiaries($split),
        ];
    }

    /**
     * Map the split calculator output to EuPago beneficiaries.
     */
    private function buildBeneficiaries(array $split): array
    {
        $beneficiaries = [];

        if (!empty($this->storeExternKey) && $split['store_net'] > 0) {
            $beneficiaries[] = [
                'externKey' => $this->storeExternKey,
                'amount' => (float) $split['store_net'],
            ];
        }

        if (!empty($this->deliveryExternKey) && $split['delivery_net'] > 0) {
            $beneficiaries[] = [
                'externKey' => $this->deliveryExternKey,
                'amount' => (float) $split['delivery_net'],
            ];
        }

        if (!empty($this->platformExternKey) && $split['platform_net'] > 0) {
            $beneficiaries[] = [
                'externKey' => $this->platformExternKey,
                'amount' => (float) $split['platform_net'],
            ];
        }

        return $beneficiaries;
    }

    /**
     * Obtain an OAuth Bearer token for management endpoints (transaction lookup,
     * payouts, etc.).
     */
    private function getBearerToken(): string
    {
        if ($this->mockMode) {
            return 'mock_token_' . uniqid();
        }

        $response = Http::asForm()->post("{$this->baseUrl}/api/auth/token", [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type' => 'client_credentials',
        ]);

        if ($response->failed()) {
            throw new \RuntimeException('EuPago OAuth token request failed: ' . $response->body());
        }

        $data = $response->json();

        return $data['access_token'] ?? throw new \RuntimeException('EuPago access token missing from response');
    }

    /**
     * Ensure the minimum required configuration is present for live calls.
     */
    private function guardConfiguration(): void
    {
        if (empty($this->apiKey)) {
            throw new \RuntimeException('EuPago API key is not configured (EUPAGO_API_KEY).');
        }

        if (empty($this->storeExternKey) || empty($this->deliveryExternKey) || empty($this->platformExternKey)) {
            throw new \RuntimeException(
                'EuPago split payment extern keys are not configured. ' .
                'Please set EUPAGO_STORE_EXTERN_KEY, EUPAGO_DELIVERY_EXTERN_KEY and EUPAGO_PLATFORM_EXTERN_KEY.'
            );
        }
    }

    /**
     * Mock response for local development and unit tests.
     */
    private function mockCreateMbwayPayment(Order $order, string $phone, array $split): array
    {
        return [
            'success' => true,
            'reference' => 'MW-' . str_pad((string) $order->id, 8, '0', STR_PAD_LEFT),
            'transaction_id' => 'TRX-' . uniqid(),
            'amount' => (float) $split['total'],
            'status' => 'pending',
            'phone' => $this->maskPhone($phone),
            '_mock' => true,
        ];
    }

    /**
     * Mask a phone number for safe logging.
     */
    private function maskPhone(string $phone): string
    {
        $length = strlen($phone);
        if ($length <= 4) {
            return $phone;
        }

        return str_repeat('*', $length - 4) . substr($phone, -4);
    }
}
