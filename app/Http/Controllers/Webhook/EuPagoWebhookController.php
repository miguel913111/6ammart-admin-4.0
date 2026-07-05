<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessEuPagoPaymentJob;
use App\Models\Order;
use App\Models\PaymentWebhookEvent;
use App\Services\EuPagoService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Handle incoming EuPago webhook notifications.
 *
 * EuPago can send the payload either as plain JSON or encrypted inside a
 * "data" field when encryption is enabled. This controller handles both.
 *
 * @see https://eupago.readme.io/reference/realtime-webhooks-20
 */
class EuPagoWebhookController extends Controller
{
    public function __construct(
        private readonly EuPagoService $euPagoService
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $isGet = $request->isMethod('GET');
        $payload = $isGet ? $this->normalizeGetPayload($request) : $request->all();
        $signature = $request->header('X-Signature');

        Log::info('EuPagoWebhookController: callback received', [
            'method' => $request->method(),
            'payload' => $payload,
            'signature_present' => !empty($signature),
        ]);

        if (!$this->euPagoService->validateWebhook($request)) {
            Log::warning('EuPagoWebhookController: invalid webhook signature');

            return response('Unauthorized', 401);
        }

        // If the body is encrypted, decrypt it first.
        if (isset($payload['data']) && is_string($payload['data'])) {
            $payload = $this->decryptPayload($request);
        }

        $transaction = $payload['transactions'] ?? $payload;
        $status = $this->normalizeStatus($transaction['status'] ?? ($transaction['estado'] ?? null));
        $identifier = $transaction['identifier'] ?? ($transaction['identificador'] ?? null);
        $externalId = $transaction['trid'] ?? ($transaction['transacao'] ?? null);

        $event = PaymentWebhookEvent::create([
            'provider' => 'eupago',
            'event_type' => $status ?? 'unknown',
            'external_id' => $externalId,
            'payload' => $payload,
            'signature' => $signature,
            'status' => 'received',
        ]);

        if (empty($identifier)) {
            Log::warning('EuPagoWebhookController: missing order identifier');

            return response('OK', 200);
        }

        $order = Order::find($identifier);

        if (!$order) {
            Log::warning('EuPagoWebhookController: order not found', ['identifier' => $identifier]);

            return response('OK', 200);
        }

        $order->payment_provider_response = array_merge(
            $order->payment_provider_response ?? [],
            ['eupago_webhook' => $payload]
        );
        $order->save();

        if ($status === 'paid') {
            $event->update(['status' => 'processed', 'processed_at' => now()]);

            $order->payment_status = 'paid';
            $order->payment_session_status = 'succeeded';
            $order->order_status = 'confirmed';
            $order->confirmed = now();
            $order->save();

            ProcessEuPagoPaymentJob::dispatch($order->id, $payload);
        }

        return response('OK', 200);
    }

    /**
     * Convert a EuPago GET callback query string into the same shape used by
     * the JSON webhook, so the rest of the controller stays format-agnostic.
     */
    private function normalizeGetPayload(Request $request): array
    {
        return [
            'transactions' => [
                'status' => $request->input('estado'),
                'identifier' => $request->input('identificador'),
                'trid' => $request->input('transacao'),
                'reference' => $request->input('referencia'),
                'amount' => $request->input('valor'),
                'channel' => $request->input('canal'),
            ],
        ];
    }

    /**
     * Convert EuPago status values to a normalized internal status.
     */
    private function normalizeStatus(?string $status): ?string
    {
        if (empty($status)) {
            return null;
        }

        return match (strtolower($status)) {
            'paid', 'pago', 'paga' => 'paid',
            'refund', 'refunded', 'devolvido' => 'refunded',
            'error', 'erro' => 'error',
            'cancel', 'canceled', 'cancelado' => 'canceled',
            'expired', 'expirado' => 'expired',
            default => strtolower($status),
        };
    }

    /**
     * Decrypt an encrypted EuPago webhook payload.
     *
     * NOTE: This requires EUPAGO_WEBHOOK_SECRET to contain the encryption key
     * when encryption is enabled on the EuPago backoffice.
     */
    private function decryptPayload(Request $request): array
    {
        $key = config('services.eupago.webhook_secret');
        $iv = $request->header('X-Initialization-Vector');
        $data = $request->input('data');

        if (empty($key) || empty($iv) || empty($data)) {
            Log::error('EuPagoWebhookController: cannot decrypt payload, missing key/iv/data');

            return [];
        }

        $decrypted = openssl_decrypt(
            base64_decode($data),
            'aes-256-cbc',
            $key,
            OPENSSL_RAW_DATA,
            base64_decode($iv)
        );

        if ($decrypted === false) {
            Log::error('EuPagoWebhookController: payload decryption failed');

            return [];
        }

        return json_decode($decrypted, true) ?? [];
    }
}
