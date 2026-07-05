<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessMangoPayPaymentJob;
use App\Models\PaymentWebhookEvent;
use App\Services\MangoPayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MangoPayWebhookController extends Controller
{
    public function __construct(
        private readonly MangoPayService $mangoPayService
    ) {
    }

    public function __invoke(Request $request)
    {
        $payload = $request->getContent();
        $signature = $request->header('X-Mangopay-Signature') ?? $request->header('X-Webhook-Signature');

        $event = PaymentWebhookEvent::create([
            'provider' => 'mangopay',
            'event_type' => $request->input('EventType') ?? 'unknown',
            'external_id' => $request->input('RessourceId') ?? $request->input('ResourceId') ?? null,
            'payload' => $request->all(),
            'signature' => $signature,
            'status' => 'received',
        ]);

        if (!$this->mangoPayService->validateWebhook($payload, $signature)) {
            $event->update(['status' => 'failed', 'error_message' => 'Invalid signature']);
            Log::warning('MangoPay webhook: invalid signature', ['event_id' => $event->id]);
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        $event->update(['status' => 'processing']);

        try {
            $data = $request->all();

            // MangoPay webhooks include a Tag with order_id.
            $tag = $data['Tag'] ?? '';
            $orderId = null;

            if (str_starts_with($tag, 'order_')) {
                $orderId = (int) str_replace('order_', '', $tag);
            }

            if (!$orderId && isset($data['metadata']['order_id'])) {
                $orderId = (int) $data['metadata']['order_id'];
            }

            if (!$orderId) {
                throw new \RuntimeException('Missing order_id in webhook payload');
            }

            ProcessMangoPayPaymentJob::dispatch($orderId, $data);

            $event->update(['status' => 'processed', 'processed_at' => now()]);

            return response()->json(['message' => 'Received'], 200);
        } catch (\Throwable $e) {
            $event->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'processed_at' => now(),
            ]);

            Log::error('MangoPay webhook processing failed', [
                'event_id' => $event->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Processing failed'], 500);
        }
    }
}
