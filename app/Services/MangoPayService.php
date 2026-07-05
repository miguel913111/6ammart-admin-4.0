<?php

namespace App\Services;

use App\Models\DeliveryMan;
use App\Models\Order;
use App\Models\Store;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class MangoPayService
{
    private string $baseUrl;
    private ?string $clientId;
    private ?string $apiKey;
    private ?string $webhookSecret;
    private bool $mockMode;

    public function __construct()
    {
        $config = config('services.mangopay');
        $this->baseUrl = rtrim($config['base_url'] ?? 'https://api.sandbox.mangopay.com', '/');
        $this->clientId = $config['client_id'] ?? null;
        $this->apiKey = $config['api_key'] ?? null;
        $this->webhookSecret = $config['webhook_secret'] ?? null;
        $this->mockMode = filter_var($config['mock_mode'] ?? true, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Create a natural user (restaurant or delivery man).
     *
     * @param  Store|DeliveryMan  $partner
     * @return array
     */
    public function createNaturalUser(object $partner): array
    {
        if ($this->mockMode || empty($this->clientId) || empty($this->apiKey)) {
            return $this->mockCreateNaturalUser($partner);
        }

        $payload = [
            'FirstName' => $partner->f_name ?? $partner->name ?? 'Partner',
            'LastName' => $partner->l_name ?? '',
            'Email' => $partner->email,
            'Nationality' => 'PT',
            'CountryOfResidence' => 'PT',
            'Birthday' => 0,
        ];

        $response = Http::withBasicAuth($this->clientId, $this->apiKey)
            ->post("{$this->baseUrl}/v2.01/{$this->clientId}/users/natural", $payload);

        if ($response->failed()) {
            throw new \RuntimeException('MangoPay create user failed: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Create a wallet for a user.
     */
    public function createWallet(string $userId, string $currency = 'EUR'): array
    {
        if ($this->mockMode || empty($this->clientId) || empty($this->apiKey)) {
            return $this->mockCreateWallet($userId);
        }

        $payload = [
            'Owners' => [$userId],
            'Description' => 'Partner wallet',
            'Currency' => $currency,
        ];

        $response = Http::withBasicAuth($this->clientId, $this->apiKey)
            ->post("{$this->baseUrl}/v2.01/{$this->clientId}/wallets", $payload);

        if ($response->failed()) {
            throw new \RuntimeException('MangoPay create wallet failed: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Create a full partner account (user + wallet) and persist it.
     *
     * @param  Store|DeliveryMan  $partner
     */
    public function createPartnerAccount(object $partner): array
    {
        if ($this->mockMode || empty($this->clientId) || empty($this->apiKey)) {
            $account = $this->mockCreateNaturalUser($partner);
            $wallet = $this->mockCreateWallet($account['Id']);
            $account['wallet_id'] = $wallet['Id'];
            $this->savePartnerAccount($partner, $account);
            return $account;
        }

        $user = $this->createNaturalUser($partner);
        $wallet = $this->createWallet($user['Id']);

        $account = array_merge($user, ['wallet_id' => $wallet['Id']]);
        $this->savePartnerAccount($partner, $account);

        return $account;
    }

    /**
     * Persist MangoPay account details on a partner model.
     */
    public function savePartnerAccount(object $partner, array $account): void
    {
        $partner->mangopay_user_id = $account['Id'] ?? $account['user_id'] ?? $partner->mangopay_user_id;
        $partner->mangopay_wallet_id = $account['wallet_id'] ?? $partner->mangopay_wallet_id;
        $partner->save();
    }

    /**
     * Create a card registration for native card tokenization.
     */
    public function createCardRegistration(string $userId): array
    {
        if ($this->mockMode || empty($this->clientId) || empty($this->apiKey)) {
            return [
                'id' => 'cardreg_' . Str::random(10),
                'user_id' => $userId,
                'access_key' => 'mock_access_key_' . Str::random(16),
                'preregistration_data' => 'mock_prereg_data_' . Str::random(16),
                'card_registration_url' => 'https://homologation-webpayment.payline.com/webpayment/getToken',
                '_mock' => true,
            ];
        }

        $payload = [
            'UserId' => $userId,
            'Currency' => 'EUR',
            'CardType' => 'CB_VISA_MASTERCARD',
        ];

        $response = Http::withBasicAuth($this->clientId, $this->apiKey)
            ->post("{$this->baseUrl}/v2.01/{$this->clientId}/cardregistrations", $payload);

        if ($response->failed()) {
            throw new \RuntimeException('MangoPay card registration failed: ' . $response->body());
        }

        $data = $response->json();

        return [
            'id' => $data['Id'],
            'user_id' => $data['UserId'],
            'access_key' => $data['AccessKey'],
            'preregistration_data' => $data['PreregistrationData'],
            'card_registration_url' => $data['CardRegistrationURL'],
        ];
    }

    /**
     * Complete card registration and return CardId.
     */
    public function completeCardRegistration(string $cardRegistrationId, string $registrationData): array
    {
        if ($this->mockMode || empty($this->clientId) || empty($this->apiKey)) {
            return [
                'id' => $cardRegistrationId,
                'card_id' => 'card_' . Str::random(10),
                'status' => 'VALIDATED',
                '_mock' => true,
            ];
        }

        $payload = [
            'Id' => $cardRegistrationId,
            'RegistrationData' => $registrationData,
        ];

        $response = Http::withBasicAuth($this->clientId, $this->apiKey)
            ->put("{$this->baseUrl}/v2.01/{$this->clientId}/cardregistrations/{$cardRegistrationId}", $payload);

        if ($response->failed()) {
            throw new \RuntimeException('MangoPay complete card registration failed: ' . $response->body());
        }

        $data = $response->json();

        return [
            'id' => $data['Id'],
            'card_id' => $data['CardId'],
            'status' => $data['Status'],
        ];
    }

    /**
     * Send raw card details to MangoPay's card registration URL (Payline)
     * and return the RegistrationData string. Data is not stored or logged.
     */
    public function tokenizeCardAtMangoPay(array $cardDetails, array $registration): string
    {
        $required = ['number', 'expiry_month', 'expiry_year', 'cvc'];
        foreach ($required as $field) {
            if (empty($cardDetails[$field])) {
                throw new \InvalidArgumentException("Missing card field: {$field}");
            }
        }

        if ($this->mockMode || empty($this->clientId) || empty($this->apiKey)) {
            return 'data=mock_registration_data_' . Str::random(24);
        }

        $expiryMonth = str_pad((string) $cardDetails['expiry_month'], 2, '0', STR_PAD_LEFT);
        $expiryYear = substr((string) $cardDetails['expiry_year'], -2);

        $response = Http::asForm()
            ->timeout(30)
            ->post($registration['card_registration_url'], [
                'data' => $registration['preregistration_data'],
                'accessKeyRef' => $registration['access_key'],
                'cardNumber' => preg_replace('/\D/', '', $cardDetails['number']),
                'cardExpirationDate' => $expiryMonth . $expiryYear,
                'cardCvx' => $cardDetails['cvc'],
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('MangoPay card tokenization failed: ' . $response->body());
        }

        $body = trim($response->body());

        // Payline returns a string like "data=XXXXX".
        if (!str_starts_with($body, 'data=')) {
            throw new \RuntimeException('Unexpected MangoPay card registration response');
        }

        return $body;
    }

    /**
     * Create a direct card pay-in (no redirect).
     */
    public function createDirectCardPayIn(string $authorId, string $cardId, string $creditedWalletId, int $amountCents, string $statementDescriptor = null): array
    {
        if ($this->mockMode || empty($this->clientId) || empty($this->apiKey)) {
            return [
                'id' => 'payin_' . Str::random(10),
                'status' => 'SUCCEEDED',
                'amount' => $amountCents,
                'currency' => 'EUR',
                '_mock' => true,
            ];
        }

        $payload = [
            'AuthorId' => $authorId,
            'CreditedWalletId' => $creditedWalletId,
            'PaymentType' => 'CARD',
            'PaymentDetails' => ['CardId' => $cardId],
            'DebitedFunds' => ['Currency' => 'EUR', 'Amount' => $amountCents],
            'Fees' => ['Currency' => 'EUR', 'Amount' => 0],
            'ExecutionType' => 'DIRECT',
            'ExecutionDetails' => [
                'SecureModeReturnURL' => url('/payment-return/mangopay'),
                'SecureMode' => 'DEFAULT',
            ],
        ];

        if ($statementDescriptor) {
            $payload['StatementDescriptor'] = $statementDescriptor;
        }

        $response = Http::withBasicAuth($this->clientId, $this->apiKey)
            ->post("{$this->baseUrl}/v2.01/{$this->clientId}/payins/card/direct", $payload);

        if ($response->failed()) {
            throw new \RuntimeException('MangoPay direct pay-in failed: ' . $response->body());
        }

        $data = $response->json();

        return [
            'id' => $data['Id'],
            'status' => $data['Status'],
            'amount' => $data['DebitedFunds']['Amount'] ?? $amountCents,
            'currency' => $data['DebitedFunds']['Currency'] ?? 'EUR',
            'secure_mode_needed' => $data['ExecutionDetails']['SecureModeNeeded'] ?? false,
            'secure_mode_redirect_url' => $data['ExecutionDetails']['SecureModeRedirectURL'] ?? null,
        ];
    }

    /**
     * Create a card pay-in for an order.
     *
     * Returns a client_token (RedirectURL for WEB flow) that the Flutter app
     * can open in an internal WebView without leaving the app.
     */
    public function createPaymentSession(Order $order, array $split): array
    {
        if ($this->mockMode || empty($this->clientId) || empty($this->apiKey)) {
            return $this->mockCreatePaymentSession($order, $split);
        }

        $customerUser = $this->getOrCreateCustomerUser($order);
        $platformWalletId = $this->getPlatformWalletId();
        $totalCents = (int) round($split['total'] * 100);

        $payload = [
            'AuthorId' => $customerUser['id'],
            'CreditedWalletId' => $platformWalletId,
            'PaymentType' => 'CARD',
            'PaymentDetails' => [
                'CardType' => 'CB_VISA_MASTERCARD',
            ],
            'DebitedFunds' => [
                'Currency' => 'EUR',
                'Amount' => $totalCents,
            ],
            'Fees' => [
                'Currency' => 'EUR',
                'Amount' => 0,
            ],
            'ExecutionType' => 'WEB',
            'ExecutionDetails' => [
                'ReturnURL' => url('/payment-return/mangopay/' . $order->id),
                'Culture' => 'PT',
            ],
            'Tag' => 'order_' . $order->id,
        ];

        $response = Http::withBasicAuth($this->clientId, $this->apiKey)
            ->post("{$this->baseUrl}/v2.01/{$this->clientId}/payins/card/web", $payload);

        if ($response->failed()) {
            throw new \RuntimeException('MangoPay pay-in failed: ' . $response->body());
        }

        $payIn = $response->json();

        return [
            'id' => $payIn['Id'],
            'status' => $payIn['Status'],
            'client_token' => $payIn['ExecutionDetails']['RedirectURL'] ?? null,
            'redirect_url' => $payIn['ExecutionDetails']['RedirectURL'] ?? null,
            'return_url' => $payIn['ExecutionDetails']['ReturnURL'] ?? null,
            'amount' => $totalCents,
            'currency' => 'EUR',
        ];
    }

    /**
     * Create transfers for an order: platform wallet -> store + delivery man wallets.
     */
    public function createOrderTransfers(Order $order, array $split): array
    {
        if ($this->mockMode || empty($this->clientId) || empty($this->apiKey)) {
            return $this->mockCreateOrderTransfers($order, $split);
        }

        $platformWalletId = $this->getPlatformWalletId();
        $results = [];

        $store = $order->store;
        if ($store && !empty($store->mangopay_wallet_id) && $split['store_net'] > 0) {
            $results[] = $this->createTransfer(
                $platformWalletId,
                $platformWalletId,
                $store->mangopay_wallet_id,
                (int) round($split['store_net'] * 100),
                'EUR'
            );
        }

        $deliveryMan = $order->delivery_man;
        if ($deliveryMan && !empty($deliveryMan->mangopay_wallet_id) && $split['delivery_net'] > 0) {
            $results[] = $this->createTransfer(
                $platformWalletId,
                $platformWalletId,
                $deliveryMan->mangopay_wallet_id,
                (int) round($split['delivery_net'] * 100),
                'EUR'
            );
        }

        return $results;
    }

    /**
     * Create transfers from platform wallet to partners.
     */
    public function createTransfer(string $authorId, string $debitedWalletId, string $creditedWalletId, int $amountCents, string $currency = 'EUR'): array
    {
        if ($this->mockMode || empty($this->clientId) || empty($this->apiKey)) {
            return $this->mockCreateTransfer($authorId, $amountCents);
        }

        $payload = [
            'AuthorId' => $authorId,
            'DebitedWalletId' => $debitedWalletId,
            'CreditedWalletId' => $creditedWalletId,
            'DebitedFunds' => ['Currency' => $currency, 'Amount' => $amountCents],
            'Fees' => ['Currency' => $currency, 'Amount' => 0],
        ];

        $response = Http::withBasicAuth($this->clientId, $this->apiKey)
            ->post("{$this->baseUrl}/v2.01/{$this->clientId}/transfers", $payload);

        if ($response->failed()) {
            throw new \RuntimeException('MangoPay transfer failed: ' . $response->body());
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

    public function getOrCreateCustomerUser(Order $order): array
    {
        $email = $order->customer?->email ?? 'customer-' . ($order->customer_id ?? uniqid()) . '@sixammart.test';
        $name = $order->customer?->f_name ?? 'Customer';
        $parts = explode(' ', $name, 2);

        $payload = [
            'FirstName' => $parts[0] ?? 'Customer',
            'LastName' => $parts[1] ?? 'Teste',
            'Email' => $email,
            'Nationality' => 'PT',
            'CountryOfResidence' => 'PT',
            'Birthday' => 0,
        ];

        $response = Http::withBasicAuth($this->clientId, $this->apiKey)
            ->post("{$this->baseUrl}/v2.01/{$this->clientId}/users/natural", $payload);

        if ($response->failed()) {
            throw new \RuntimeException('MangoPay create customer user failed: ' . $response->body());
        }

        $user = $response->json();

        return ['id' => $user['Id']];
    }

    public function getPlatformWalletId(): string
    {
        $configured = config('services.mangopay.platform_wallet_id');
        if (!empty($configured)) {
            return $configured;
        }

        if ($this->mockMode || empty($this->clientId) || empty($this->apiKey)) {
            return 'wallet_platform';
        }

        $platformEmail = 'platform@sixammart.test';

        $payload = [
            'FirstName' => '6amMart',
            'LastName' => 'Platform',
            'Email' => $platformEmail,
            'Nationality' => 'PT',
            'CountryOfResidence' => 'PT',
            'Birthday' => 0,
        ];

        $response = Http::withBasicAuth($this->clientId, $this->apiKey)
            ->post("{$this->baseUrl}/v2.01/{$this->clientId}/users/natural", $payload);

        if ($response->failed()) {
            throw new \RuntimeException('MangoPay create platform user failed: ' . $response->body());
        }

        $user = $response->json();

        $walletPayload = [
            'Owners' => [$user['Id']],
            'Description' => '6amMart platform wallet',
            'Currency' => 'EUR',
        ];

        $walletResponse = Http::withBasicAuth($this->clientId, $this->apiKey)
            ->post("{$this->baseUrl}/v2.01/{$this->clientId}/wallets", $walletPayload);

        if ($walletResponse->failed()) {
            throw new \RuntimeException('MangoPay create platform wallet failed: ' . $walletResponse->body());
        }

        $wallet = $walletResponse->json();

        return $wallet['Id'];
    }

    private function mockCreateNaturalUser(object $partner): array
    {
        return [
            'Id' => 'user_' . Str::random(10),
            'FirstName' => $partner->f_name ?? $partner->name ?? 'Partner',
            'LastName' => $partner->l_name ?? '',
            'Email' => $partner->email,
            'KYCLevel' => 'LIGHT',
            '_mock' => true,
        ];
    }

    private function mockCreateWallet(string $userId): array
    {
        return [
            'Id' => 'wallet_' . Str::random(10),
            'Owners' => [$userId],
            'Balance' => ['Currency' => 'EUR', 'Amount' => 0],
            '_mock' => true,
        ];
    }

    private function mockCreateOrderTransfers(Order $order, array $split): array
    {
        $results = [];

        if ($order->store?->mangopay_wallet_id && $split['store_net'] > 0) {
            $results[] = [
                'Id' => 'transfer_' . Str::random(10),
                'Status' => 'SUCCEEDED',
                'DebitedFunds' => ['Currency' => 'EUR', 'Amount' => (int) round($split['store_net'] * 100)],
                'CreditedFunds' => ['Currency' => 'EUR', 'Amount' => (int) round($split['store_net'] * 100)],
                'CreditedWalletId' => $order->store->mangopay_wallet_id,
                'Tag' => 'order_' . $order->id . '_store',
                '_mock' => true,
            ];
        }

        if ($order->delivery_man?->mangopay_wallet_id && $split['delivery_net'] > 0) {
            $results[] = [
                'Id' => 'transfer_' . Str::random(10),
                'Status' => 'SUCCEEDED',
                'DebitedFunds' => ['Currency' => 'EUR', 'Amount' => (int) round($split['delivery_net'] * 100)],
                'CreditedFunds' => ['Currency' => 'EUR', 'Amount' => (int) round($split['delivery_net'] * 100)],
                'CreditedWalletId' => $order->delivery_man->mangopay_wallet_id,
                'Tag' => 'order_' . $order->id . '_delivery',
                '_mock' => true,
            ];
        }

        return $results;
    }

    private function mockCreatePaymentSession(Order $order, array $split): array
    {
        $payInId = 'payin_' . Str::random(10);

        return [
            'id' => $payInId,
            'status' => 'CREATED',
            'client_token' => url('/payment-return/mangopay/' . $order->id) . '?mock=' . $payInId,
            'redirect_url' => url('/payment-return/mangopay/' . $order->id) . '?mock=' . $payInId,
            'return_url' => url('/payment-return/mangopay/' . $order->id) . '?mock=' . $payInId,
            'amount' => (int) round($split['total'] * 100),
            'currency' => 'EUR',
            '_mock' => true,
        ];
    }

    private function mockCreateTransfer(string $authorId, int $amountCents): array
    {
        return [
            'Id' => 'transfer_' . Str::random(10),
            'AuthorId' => $authorId,
            'Status' => 'SUCCEEDED',
            'DebitedFunds' => ['Currency' => 'EUR', 'Amount' => $amountCents],
            'CreditedFunds' => ['Currency' => 'EUR', 'Amount' => $amountCents],
            '_mock' => true,
        ];
    }
}
