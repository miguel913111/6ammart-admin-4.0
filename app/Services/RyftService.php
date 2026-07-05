<?php

namespace App\Services;

use App\Models\DeliveryMan;
use App\Models\Order;
use App\Models\Store;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class RyftService
{
    private string $baseUrl;
    private ?string $secretKey;
    private ?string $webhookSecret;
    private bool $mockMode;

    public function isMockMode(): bool
    {
        return $this->mockMode;
    }

    /**
     * Confirm a Ryft payment session with a card/payment-method token or raw card details.
     *
     * @param  string  $sessionId
     * @param  string|array  $paymentMethod  Token string or card_details array
     */
    public function confirmPaymentSession(string $sessionId, string|array $paymentMethod): array
    {
        if ($this->mockMode || empty($this->secretKey)) {
            return [
                'id' => $sessionId,
                'status' => 'captured',
                'amount' => 1000,
                'currency' => 'EUR',
                '_mock' => true,
            ];
        }

        if (is_array($paymentMethod)) {
            $payload = [
                'payment_method' => [
                    'type' => 'card',
                    'card' => [
                        'number' => $paymentMethod['number'] ?? null,
                        'expiry_month' => (int) ($paymentMethod['expiry_month'] ?? 0),
                        'expiry_year' => (int) ($paymentMethod['expiry_year'] ?? 0),
                        'cvc' => $paymentMethod['cvc'] ?? null,
                        'name' => $paymentMethod['holder_name'] ?? null,
                    ],
                ],
            ];
        } else {
            $payload = ['payment_method' => ['token' => $paymentMethod]];
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->secretKey,
            'Content-Type' => 'application/json',
        ])->post("{$this->baseUrl}/v1/payment-sessions/{$sessionId}/confirm", $payload);

        if ($response->failed()) {
            throw new \RuntimeException('Ryft confirm session failed: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Validate raw card details before sending them to Ryft.
     * Ryft accepts card details directly inside the session confirmation call,
     * so this helper just ensures the required fields are present.
     */
    public function validateCardDetails(array $cardDetails): void
    {
        $required = ['number', 'expiry_month', 'expiry_year', 'cvc'];
        foreach ($required as $field) {
            if (empty($cardDetails[$field])) {
                throw new \InvalidArgumentException("Missing card field: {$field}");
            }
        }
    }

    public function __construct()
    {
        $config = config('services.ryft');
        $this->baseUrl = rtrim($config['base_url'] ?? 'https://api.ryftpay.com', '/');
        $this->secretKey = $config['secret_key'] ?? null;
        $this->webhookSecret = $config['webhook_secret'] ?? null;
        $this->mockMode = filter_var($config['mock_mode'] ?? true, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Create a Ryft sub-account for a store or delivery man.
     *
     * @param  Store|DeliveryMan  $partner
     */
    public function createConnectedAccount(object $partner): array
    {
        if ($this->mockMode || empty($this->secretKey)) {
            return $this->mockCreateConnectedAccount($partner);
        }

        $name = $partner->name ?? $partner->f_name ?? 'Partner';
        $email = $partner->email ?? 'partner-' . ($partner->id ?? uniqid()) . '@sixammart.test';

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->secretKey,
            'Content-Type' => 'application/json',
        ])->post("{$this->baseUrl}/v1/accounts", [
            'type' => 'individual',
            'country' => 'GB',
            'email' => $email,
            'name' => $name,
            'metadata' => [
                'partner_id' => (string) ($partner->id ?? ''),
                'platform' => 'sixammart',
            ],
        ]);

        if ($response->failed()) {
            throw new \RuntimeException('Ryft create account failed: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Persist Ryft sub-account ID on the partner model.
     */
    public function savePartnerAccount(object $partner, array $account): void
    {
        $partner->ryft_sub_account_id = $account['id'] ?? $partner->ryft_sub_account_id;
        $partner->save();
    }

    /**
     * Create a Ryft payment session with split destinations.
     *
     * @param  Order  $order
     * @param  array  $split  Result from PaymentSplitCalculator::forSixamMart
     * @return array
     */
    public function createPaymentSession(Order $order, array $split): array
    {
        if ($this->mockMode || empty($this->secretKey)) {
            return $this->mockCreatePaymentSession($order, $split);
        }

        $payload = [
            'amount' => (int) round($split['total'] * 100),
            'currency' => config('services.currency_code', 'EUR'),
            'customer' => [
                'email' => $order->customer?->email ?? 'guest@example.com',
                'name' => $order->is_guest
                    ? ($order->receiver_details['contact_person_name'] ?? 'Guest')
                    : ($order->customer?->f_name . ' ' . $order->customer?->l_name),
            ],
            'split' => $this->buildSplit($order, $split),
            'metadata' => [
                'order_id' => $order->id,
                'platform' => 'sixammart',
            ],
        ];

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->secretKey,
            'Content-Type' => 'application/json',
        ])->post("{$this->baseUrl}/v1/payment-sessions", $payload);

        if ($response->failed()) {
            throw new \RuntimeException('Ryft create session failed: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Confirm / retrieve a payment session.
     */
    public function getPaymentSession(string $sessionId): array
    {
        if ($this->mockMode || empty($this->secretKey)) {
            return $this->mockGetPaymentSession($sessionId);
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->secretKey,
        ])->get("{$this->baseUrl}/v1/payment-sessions/{$sessionId}");

        if ($response->failed()) {
            throw new \RuntimeException('Ryft get session failed: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Validate incoming webhook signature.
     */
    public function validateWebhook(string $payload, ?string $signature): bool
    {
        if ($this->mockMode || empty($this->webhookSecret)) {
            return true;
        }

        if (empty($signature)) {
            return false;
        }

        $expected = hash_hmac('sha256', $payload, $this->webhookSecret);
        return hash_equals($expected, $signature);
    }

    /**
     * Build split destinations for Ryft.
     */
    private function buildSplit(Order $order, array $split): array
    {
        $destinations = [];

        $store = $order->store;
        if ($store && !empty($store->ryft_sub_account_id) && $split['store_net'] > 0) {
            $destinations[] = [
                'account_id' => $store->ryft_sub_account_id,
                'amount' => (int) round($split['store_net'] * 100),
                'description' => 'Store payout',
            ];
        }

        $deliveryMan = $order->delivery_man;
        if ($deliveryMan && !empty($deliveryMan->ryft_sub_account_id) && $split['delivery_net'] > 0) {
            $destinations[] = [
                'account_id' => $deliveryMan->ryft_sub_account_id,
                'amount' => (int) round($split['delivery_net'] * 100),
                'description' => 'Delivery payout',
            ];
        }

        // Platform always receives fee (deducted from store portion).
        $platformAccount = config('services.ryft.platform_account_id');
        if (!empty($platformAccount) && $split['platform_fee_brutto'] > 0) {
            $destinations[] = [
                'account_id' => $platformAccount,
                'amount' => (int) round($split['platform_fee_brutto'] * 100),
                'description' => 'Platform service fee',
            ];
        }

        return [
            'destinations' => $destinations,
            'fees' => [
                'processing_fee' => (int) round($split['processing_fee'] * 100),
                'processing_fee_paid_by' => 'store',
            ],
        ];
    }

    /**
     * Mock response for create connected account.
     */
    private function mockCreateConnectedAccount(object $partner): array
    {
        return [
            'id' => 'acc_' . Str::random(12),
            'type' => 'individual',
            'status' => 'active',
            'name' => $partner->name ?? $partner->f_name ?? 'Partner',
            'email' => $partner->email ?? 'partner@sixammart.test',
            '_mock' => true,
        ];
    }

    /**
     * Mock response for create payment session.
     */
    private function mockCreatePaymentSession(Order $order, array $split): array
    {
        $sessionId = 'sess_' . Str::random(24);

        return [
            'id' => $sessionId,
            'status' => 'requires_payment_method',
            'amount' => (int) round($split['total'] * 100),
            'currency' => 'EUR',
            'client_token' => base64_encode(json_encode([
                'session_id' => $sessionId,
                'mock' => true,
            ])),
            'split' => $split,
            'metadata' => [
                'order_id' => $order->id,
                'platform' => 'sixammart',
            ],
            '_mock' => true,
        ];
    }

    /**
     * Mock response for get payment session.
     */
    private function mockGetPaymentSession(string $sessionId): array
    {
        return [
            'id' => $sessionId,
            'status' => 'captured',
            'amount' => 1300,
            'currency' => 'EUR',
            'metadata' => [
                'order_id' => 1,
                'platform' => 'sixammart',
            ],
            '_mock' => true,
        ];
    }
}
