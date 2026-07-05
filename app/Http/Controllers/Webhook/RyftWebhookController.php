<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessRyftPaymentJob;
use App\Models\PaymentWebhookEvent;
use App\Services\RyftService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RyftWebhookController extends Controller
{
    public function __construct(
        private readonly RyftService $ryftService
    ) {
    }

    public function __invoke(Request $request)
    {
        $payload = $request->getContent();
        $signature = $request->header('X-Ryft-Signature') ?? $request->header('X-Webhook-Signature');

        $event = PaymentWebhookEvent::create([
            'provider' => 'ryft',
            'event_type' => $request->input('event_type') ?? $request->input('type') ?? 'unknown',
            'external_id' => $request->input('id') ?? $request->input('data.id') ?? null,
            'payload' => $request->all(),
            'signature' => $signature,
            'status' => 'received',
        ]);

        if (!$this->ryftService->validateWebhook($payload, $signature)) {
            $event->update(['status' => 'failed', 'error_message' => 'Invalid signature']);
            Log::warning('Ryft webhook: invalid signature', ['event_id' => $event->id]);
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        $event->update(['status' => 'processing']);

        try {
            $data = $request->all();
            $orderId = $data['data']['metadata']['order_id']
                ?? $data['metadata']['order_id']
                ?? null;

            if (!$orderId) {
                throw new \RuntimeException('Missing order_id in webhook payload');
            }

            ProcessRyftPaymentJob::dispatch((int) $orderId, $data);

            $event->update(['status' => 'processed', 'processed_at' => now()]);

            return response()->json(['message' => 'Received'], 200);
        } catch (\Throwable $e) {
            $event->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'processed_at' => now(),
            ]);

            Log::error('Ryft webhook processing failed', [
                'event_id' => $event->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Processing failed'], 500);
        }
    }
}
