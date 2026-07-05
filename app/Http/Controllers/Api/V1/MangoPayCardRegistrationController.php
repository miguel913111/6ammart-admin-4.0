<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\MangoPayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MangoPayCardRegistrationController extends Controller
{
    public function __construct(
        private readonly MangoPayService $mangoPayService,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'order_id' => 'required|integer|exists:orders,id',
        ]);

        $order = Order::with('customer')->find($request->order_id);

        if (!$order) {
            return response()->json(['errors' => ['message' => 'Order not found']], 404);
        }

        try {
            $customerUser = $this->mangoPayService->getOrCreateCustomerUser($order);
            $cardRegistration = $this->mangoPayService->createCardRegistration($customerUser['id']);

            return response()->json([
                'order_id' => $order->id,
                'user_id' => $customerUser['id'],
                'card_registration' => $cardRegistration,
            ]);
        } catch (\Throwable $e) {
            Log::error('MangoPayCardRegistrationController: failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['errors' => ['message' => 'Card registration failed: ' . $e->getMessage()]], 500);
        }
    }
}
