<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\EuPagoService;
use App\Services\MangoPayService;
use App\Services\PaymentSplitCalculator;
use App\Services\RyftService;
use App\Services\StripeConnectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PaymentSessionController extends Controller
{
    public function __construct(
        private readonly RyftService $ryftService,
        private readonly MangoPayService $mangoPayService,
        private readonly StripeConnectService $stripeService,
        private readonly EuPagoService $euPagoService
    ) {
    }

    /**
     * Create a native payment session.
     *
     * If payment_method is omitted, the gateway configured by the admin
     * (PAYMENT_GATEWAY_DEFAULT) is used.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'order_id' => 'required|integer|exists:orders,id',
            'phone' => 'nullable|string|min:9|max:20',
        ]);

        $order = Order::with(['store', 'delivery_man'])->find($request->order_id);

        if (!$order) {
            return response()->json(['errors' => ['message' => 'Order not found']], 404);
        }

        // O gateway é escolhido pelo admin na configuração; o utilizador não escolhe.
        $paymentMethod = config('services.default_payment_gateway', 'stripe_connect');
        $split = PaymentSplitCalculator::forSixamMart($order);

        try {
            if ($paymentMethod === 'ryft') {
                $session = $this->ryftService->createPaymentSession($order, $split);
                $order->payment_method = 'ryft';
                $order->payment_session_id = $session['id'];
                $order->payment_session_client_token = $session['client_token'] ?? null;
                $order->payment_session_status = $session['status'] ?? 'requires_payment_method';
                $order->payment_split_payload = $session['split'] ?? $split;
                $order->save();

                return response()->json([
                    'payment_method' => 'ryft',
                    'session_id' => $session['id'],
                    'client_token' => $session['client_token'] ?? null,
                    'status' => $session['status'] ?? 'requires_payment_method',
                    'amount' => $split['total'],
                    'currency' => 'EUR',
                ]);
            }

            if ($paymentMethod === 'mangopay') {
                $session = $this->mangoPayService->createPaymentSession($order, $split);
                $order->payment_method = 'mangopay';
                $order->mangopay_payin_id = $session['id'];
                $order->payment_session_client_token = $session['client_token'] ?? null;
                $order->payment_session_status = $session['status'] ?? 'CREATED';
                $order->payment_split_payload = $split;
                $order->save();

                return response()->json([
                    'payment_method' => 'mangopay',
                    'session_id' => $session['id'],
                    'client_token' => $session['client_token'] ?? null,
                    'status' => $session['status'] ?? 'CREATED',
                    'amount' => $split['total'],
                    'currency' => 'EUR',
                ]);
            }

            if ($paymentMethod === 'stripe_connect') {
                $session = $this->stripeService->createPaymentSession($order, $split);
                $order->payment_method = 'stripe_connect';
                $order->payment_session_id = $session['id'];
                $order->payment_session_client_token = $session['client_secret'];
                $order->payment_session_status = $session['status'] ?? 'requires_payment_method';
                $order->payment_split_payload = $split;
                $order->save();

                return response()->json([
                    'payment_method' => 'stripe_connect',
                    'session_id' => $session['id'],
                    'client_token' => $session['client_secret'],
                    'status' => $session['status'] ?? 'requires_payment_method',
                    'amount' => $split['total'],
                    'currency' => 'EUR',
                ]);
            }

            if ($paymentMethod === 'eupago') {
                $phone = $this->resolvePhone($order, $request);

                if (empty($phone)) {
                    return response()->json(['errors' => ['message' => 'Phone number is required for MBWay payments']], 422);
                }

                $session = $this->euPagoService->createMbwayPayment($order, $phone, $split);

                $order->payment_method = 'eupago';
                $order->eupago_transaction_id = $session['transaction_id'] ?? ($session['trid'] ?? null);
                $order->eupago_reference = $session['reference'] ?? ($session['referencia'] ?? null);
                $order->eupago_phone = $phone;
                $order->payment_session_id = $session['transaction_id'] ?? ($session['trid'] ?? $session['reference']);
                $order->payment_session_status = $session['status'] ?? 'pending';
                $order->payment_split_payload = $split;
                $order->eupago_provider_response = $session;
                $order->save();

                return response()->json([
                    'payment_method' => 'eupago',
                    'session_id' => $order->payment_session_id,
                    'reference' => $order->eupago_reference,
                    'status' => $order->payment_session_status,
                    'amount' => $split['total'],
                    'currency' => 'EUR',
                    'phone' => $this->maskPhone($phone),
                    'expires_in_minutes' => 4,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('PaymentSessionController: failed to create session', [
                'order_id' => $order->id,
                'payment_method' => $paymentMethod,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['errors' => ['message' => 'Failed to create payment session']], 500);
        }

        return response()->json(['errors' => ['message' => 'Invalid payment method']], 400);
    }

    /**
     * Retrieve payment session status.
     */
    public function show(Request $request, string $sessionId): JsonResponse
    {
        $order = Order::where('payment_session_id', $sessionId)
            ->orWhere('mangopay_payin_id', $sessionId)
            ->orWhere('ryft_payment_intent_id', $sessionId)
            ->orWhere('eupago_transaction_id', $sessionId)
            ->orWhere('eupago_reference', $sessionId)
            ->first();

        if (!$order) {
            return response()->json(['errors' => ['message' => 'Session not found']], 404);
        }

        return response()->json([
            'session_id' => $order->payment_session_id ?? $order->mangopay_payin_id ?? $order->eupago_transaction_id,
            'reference' => $order->eupago_reference,
            'status' => $order->payment_session_status,
            'payment_status' => $order->payment_status,
            'payment_split_status' => $order->payment_split_status,
        ]);
    }

    /**
     * Resolve the phone number to use for MBWay.
     */
    private function resolvePhone(Order $order, Request $request): ?string
    {
        if ($request->filled('phone')) {
            return $this->normalizePhone($request->phone);
        }

        $customer = $order->customer;
        if ($customer && !empty($customer->phone)) {
            return $this->normalizePhone($customer->phone);
        }

        $deliveryAddress = is_string($order->delivery_address)
            ? json_decode($order->delivery_address, true)
            : $order->delivery_address;

        if (!empty($deliveryAddress['contact_person_number'])) {
            return $this->normalizePhone($deliveryAddress['contact_person_number']);
        }

        return null;
    }

    /**
     * Normalize a phone number to international format without '+' prefix.
     */
    private function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);

        if (str_starts_with($phone, '351')) {
            return $phone;
        }

        if (strlen($phone) === 9) {
            return '351' . $phone;
        }

        return $phone;
    }

    /**
     * Mask a phone number for safe API responses.
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
