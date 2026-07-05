<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessStripeConnectPaymentJob;
use App\Models\PaymentWebhookEvent;
use App\Services\PaymentGateway\PartnerGatewayFactory;
use App\Services\StripeConnectService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class StripeConnectWebhookController extends Controller
{
    public function __construct(
        private readonly StripeConnectService $stripeService
    ) {
    }

    public function __invoke(Request $request)
    {
        $payload = $request->getContent();
        $signature = $request->header('Stripe-Signature');
        $data = $request->all();

        $event = PaymentWebhookEvent::create([
            'provider' => 'stripe_connect',
            'event_type' => $data['type'] ?? 'unknown',
            'external_id' => $data['id'] ?? null,
            'payload' => $data,
            'signature' => $signature,
            'status' => 'received',
        ]);

        if (!$this->stripeService->validateWebhook($payload, $signature)) {
            $event->update(['status' => 'failed', 'error_message' => 'Invalid signature']);
            Log::warning('Stripe webhook: invalid signature', ['event_id' => $event->id]);
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        $event->update(['status' => 'processing']);

        try {
            match ($data['type'] ?? 'unknown') {
                'payment_intent.succeeded' => $this->handlePaymentIntentSucceeded($data, $event),
                'account.updated' => $this->handleAccountUpdated($data, $event),
                default => $this->handleIgnored($data, $event),
            };

            return response()->json(['message' => 'Received'], 200);
        } catch (\Throwable $e) {
            $event->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'processed_at' => now(),
            ]);

            Log::error('Stripe webhook processing failed', [
                'event_id' => $event->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Processing failed'], 500);
        }
    }

    private function handlePaymentIntentSucceeded(array $data, PaymentWebhookEvent $event): void
    {
        $orderId = $data['data']['object']['metadata']['order_id'] ?? null;

        if (!$orderId) {
            throw new \RuntimeException('Missing order_id in webhook payload');
        }

        ProcessStripeConnectPaymentJob::dispatch((int) $orderId, $data);

        $event->update(['status' => 'processed', 'processed_at' => now()]);
    }

    private function handleAccountUpdated(array $data, PaymentWebhookEvent $event): void
    {
        $gateway = PartnerGatewayFactory::make('stripe_connect');
        $gateway->handleWebhook($data);

        $event->update(['status' => 'processed', 'processed_at' => now()]);
    }

    private function handleIgnored(array $data, PaymentWebhookEvent $event): void
    {
        $event->update(['status' => 'processed', 'processed_at' => now()]);
    }
}
