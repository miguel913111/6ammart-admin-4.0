<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessEuPagoPaymentJob;
use App\Jobs\ProcessMangoPayPaymentJob;
use App\Jobs\ProcessRyftPaymentJob;
use App\Models\Order;
use App\Services\EuPagoService;
use App\Services\MangoPayService;
use App\Services\PaymentSplitCalculator;
use App\Services\RyftService;
use App\Services\StripeConnectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Confirma um pagamento nativo (Stripe, Ryft ou MangoPay) sem redirect/browser externo.
 */
class PaymentChargeController extends Controller
{
    public function __construct(
        private readonly StripeConnectService $stripeService,
        private readonly RyftService $ryftService,
        private readonly MangoPayService $mangoPayService,
        private readonly EuPagoService $euPagoService,
    ) {
    }

    public function store(Request $request, string $sessionId): JsonResponse
    {
        $order = Order::where('payment_session_id', $sessionId)
            ->orWhere('mangopay_payin_id', $sessionId)
            ->orWhere('ryft_payment_intent_id', $sessionId)
            ->first();

        if (!$order) {
            return response()->json(['errors' => ['message' => 'Session not found']], 404);
        }

        $gateway = $order->payment_method ?? config('services.default_payment_gateway', 'stripe_connect');

        try {
            $result = match ($gateway) {
                'stripe_connect' => $this->chargeStripe($order, $request),
                'ryft' => $this->chargeRyft($order, $request),
                'mangopay' => $this->chargeMangoPay($order, $request),
                'eupago' => $this->chargeEuPago($order, $request),
                default => throw new \RuntimeException('Unsupported gateway: ' . $gateway),
            };

            return response()->json($result);
        } catch (\Throwable $e) {
            Log::error('PaymentChargeController: charge failed', [
                'order_id' => $order->id,
                'gateway' => $gateway,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['errors' => ['message' => 'Payment charge failed: ' . $e->getMessage()]], 500);
        }
    }

    private function chargeStripe(Order $order, Request $request): array
    {
        $request->validate([
            'payment_method_id' => 'nullable|string',
            'stripe_token' => 'nullable|string',
            'card_details' => 'nullable|array',
            'card_details.number' => 'required_with:card_details|string',
            'card_details.expiry_month' => 'required_with:card_details|integer|min:1|max:12',
            'card_details.expiry_year' => 'required_with:card_details|integer|min:2024|max:2100',
            'card_details.cvc' => 'required_with:card_details|string',
        ]);

        if (!$request->payment_method_id && !$request->stripe_token && !$request->card_details) {
            throw new \InvalidArgumentException('Either payment_method_id, stripe_token or card_details is required');
        }

        if ($this->stripeService->isMockMode()) {
            return $this->dispatchStripeConnectJob($order, $order->payment_session_id, true);
        }

        $paymentMethodId = $request->payment_method_id;
        if (!$paymentMethodId && $request->stripe_token) {
            $paymentMethodId = $this->stripeService->createPaymentMethodFromToken($request->stripe_token);
        }
        if (!$paymentMethodId && $request->card_details) {
            $paymentMethodId = $this->stripeService->createPaymentMethodFromCardDetails($request->card_details);
        }

        $intent = $this->stripeService->confirmPaymentIntent($order->payment_session_id, $paymentMethodId);

        Log::info('PaymentChargeController: Stripe PaymentIntent confirmed', [
            'order_id' => $order->id,
            'payment_intent_id' => $intent['id'] ?? null,
            'status' => $intent['status'] ?? 'unknown',
        ]);

        // Only proceed when Stripe confirms the payment was actually captured.
        if (($intent['status'] ?? '') === 'succeeded') {
            return $this->dispatchStripeConnectJob($order, $intent['id'], false, $intent);
        }

        // Save the current status (e.g. requires_action, requires_payment_method)
        // but do NOT mark the order as paid.
        $order->payment_session_status = $intent['status'];
        $order->save();

        return [
            'gateway' => 'stripe_connect',
            'status' => $intent['status'],
            'order_id' => $order->id,
            'client_secret' => $intent['client_secret'] ?? null,
        ];
    }

    /**
     * Mark the order as paid and dispatch the job that splits the payment.
     */
    private function dispatchStripeConnectJob(Order $order, string $paymentIntentId, bool $isMock, array $intentData = []): array
    {
        $order->payment_session_status = 'succeeded';
        $order->payment_status = 'paid';
        $order->order_status = 'confirmed';
        $order->confirmed = now();
        $order->save();

        $payload = [
            'id' => $isMock ? 'evt_test_' . uniqid() : ($intentData['id'] ?? 'evt_' . uniqid()),
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => array_merge($intentData, [
                    'id' => $paymentIntentId,
                    'status' => 'succeeded',
                    'latest_charge' => $intentData['latest_charge'] ?? null,
                    'metadata' => ['order_id' => $order->id, 'platform' => 'sixammart'],
                ]),
            ],
        ];

        \App\Jobs\ProcessStripeConnectPaymentJob::dispatch($order->id, $payload);

        Log::info('PaymentChargeController: Stripe Connect split job dispatched', [
            'order_id' => $order->id,
            'payment_intent_id' => $paymentIntentId,
            'mock' => $isMock,
        ]);

        return [
            'gateway' => 'stripe_connect',
            'status' => 'succeeded',
            'order_id' => $order->id,
        ];
    }

    private function chargeRyft(Order $order, Request $request): array
    {
        $request->validate([
            'payment_method_token' => 'nullable|string',
            'card_details' => 'nullable|array',
            'card_details.number' => 'required_with:card_details|string',
            'card_details.expiry_month' => 'required_with:card_details|integer|min:1|max:12',
            'card_details.expiry_year' => 'required_with:card_details|integer|min:2024|max:2100',
            'card_details.cvc' => 'required_with:card_details|string',
        ]);

        if (!$request->payment_method_token && !$request->card_details) {
            throw new \InvalidArgumentException('Either payment_method_token or card_details is required');
        }

        if ($this->ryftService->isMockMode()) {
            $order->payment_session_status = 'captured';
            $order->payment_status = 'paid';
            $order->save();

            $payload = [
                'id' => 'evt_test_' . uniqid(),
                'type' => 'PaymentSession.captured',
                'data' => [
                    'object' => [
                        'id' => $order->payment_session_id,
                        'status' => 'captured',
                        'metadata' => ['order_id' => $order->id, 'platform' => 'sixammart'],
                    ],
                ],
            ];
            ProcessRyftPaymentJob::dispatch($order->id, $payload);

            return [
                'gateway' => 'ryft',
                'status' => 'captured',
                'order_id' => $order->id,
            ];
        }

        $paymentMethod = $request->payment_method_token;
        if (!$paymentMethod && $request->card_details) {
            $this->ryftService->validateCardDetails($request->card_details);
            $paymentMethod = $request->card_details;
        }

        $result = $this->ryftService->confirmPaymentSession($order->payment_session_id, $paymentMethod);

        $order->payment_session_status = $result['status'] ?? 'requires_payment_method';
        $order->save();

        if (($result['status'] ?? '') === 'captured') {
            ProcessRyftPaymentJob::dispatch($order->id, [
                'id' => $result['id'] ?? uniqid(),
                'type' => 'PaymentSession.captured',
                'data' => ['object' => $result],
            ]);
        }

        return [
            'gateway' => 'ryft',
            'status' => $result['status'] ?? 'unknown',
            'order_id' => $order->id,
        ];
    }

    private function chargeMangoPay(Order $order, Request $request): array
    {
        $request->validate([
            'card_registration_id' => 'nullable|string',
            'registration_data' => 'nullable|string',
            'card_details' => 'nullable|array',
            'card_details.number' => 'required_with:card_details|string',
            'card_details.expiry_month' => 'required_with:card_details|integer|min:1|max:12',
            'card_details.expiry_year' => 'required_with:card_details|integer|min:2024|max:2100',
            'card_details.cvc' => 'required_with:card_details|string',
        ]);

        $hasExistingRegistration = $request->card_registration_id && $request->registration_data;
        $hasCardDetails = (bool) $request->card_details;

        if (!$hasExistingRegistration && !$hasCardDetails) {
            throw new \InvalidArgumentException('Either card_registration_id + registration_data or card_details is required');
        }

        $split = PaymentSplitCalculator::forSixamMart($order);
        $customerUser = $this->mangoPayService->getOrCreateCustomerUser($order);
        $platformWalletId = $this->mangoPayService->getPlatformWalletId();

        if ($hasCardDetails) {
            $registration = $this->mangoPayService->createCardRegistration($customerUser['id']);
            $registrationData = $this->mangoPayService->tokenizeCardAtMangoPay(
                $request->card_details,
                $registration
            );
            $card = $this->mangoPayService->completeCardRegistration(
                $registration['id'],
                $registrationData
            );
        } else {
            $card = $this->mangoPayService->completeCardRegistration(
                $request->card_registration_id,
                $request->registration_data
            );
        }

        $payIn = $this->mangoPayService->createDirectCardPayIn(
            $customerUser['id'],
            $card['card_id'],
            $platformWalletId,
            (int) round($split['total'] * 100),
            '6amMart order ' . $order->id
        );

        $order->payment_session_status = $payIn['status'];
        $order->mangopay_payin_id = $payIn['id'];
        $order->save();

        if ($payIn['status'] === 'SUCCEEDED') {
            ProcessMangoPayPaymentJob::dispatch($order->id, [
                'EventType' => 'PAYIN_NORMAL_SUCCEEDED',
                'ResourceId' => $payIn['id'],
                'Tag' => 'order_' . $order->id,
            ]);
        }

        return [
            'gateway' => 'mangopay',
            'status' => $payIn['status'],
            'secure_mode_needed' => $payIn['secure_mode_needed'] ?? false,
            'secure_mode_redirect_url' => $payIn['secure_mode_redirect_url'] ?? null,
            'order_id' => $order->id,
        ];
    }

    /**
     * Check the current status of an MBWay payment.
     *
     * The customer approves the payment in the MBWay app; this endpoint is used
     * by the frontend to poll the current state while waiting for the webhook.
     */
    private function chargeEuPago(Order $order, Request $request): array
    {
        if ($this->euPagoService->isMockMode()) {
            $order->payment_session_status = 'succeeded';
            $order->payment_status = 'paid';
            $order->save();

            ProcessEuPagoPaymentJob::dispatch($order->id, [
                'transactions' => [
                    'status' => 'Paid',
                    'identifier' => (string) $order->id,
                    'trid' => $order->eupago_transaction_id,
                ],
            ]);

            return [
                'gateway' => 'eupago',
                'status' => 'succeeded',
                'order_id' => $order->id,
            ];
        }

        $transactionId = $order->eupago_transaction_id;

        if (empty($transactionId)) {
            throw new \InvalidArgumentException('EuPago transaction id not found for this order');
        }

        $transaction = $this->euPagoService->getTransaction($transactionId);
        $status = strtolower($transaction['status'] ?? 'unknown');

        if ($status === 'paid') {
            $order->payment_session_status = 'succeeded';
            $order->payment_status = 'paid';
            $order->order_status = 'confirmed';
            $order->confirmed = now();
            $order->save();

            ProcessEuPagoPaymentJob::dispatch($order->id, $transaction);
        }

        return [
            'gateway' => 'eupago',
            'status' => $status,
            'order_id' => $order->id,
            'reference' => $order->eupago_reference,
        ];
    }
}
