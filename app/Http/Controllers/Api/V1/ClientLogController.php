<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receive diagnostic logs from the Flutter customer app.
 *
 * This endpoint is intentionally lightweight and does not require
 * authentication so that logs can be captured even during payment
 * failures where the user may not be fully logged in.
 */
class ClientLogController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'level' => 'nullable|string|in:debug,info,warning,error',
            'tag' => 'nullable|string|max:100',
            'message' => 'required|string|max:5000',
            'payload' => 'nullable|array',
            'device_info' => 'nullable|string|max:500',
        ]);

        $level = $validated['level'] ?? 'info';
        $tag = $validated['tag'] ?? 'client';
        $message = $validated['message'];
        $payload = $validated['payload'] ?? [];
        $deviceInfo = $validated['device_info'] ?? 'unknown';

        Log::log($level, "[CLIENT:$tag][$deviceInfo] $message", $payload);

        return response()->json(['success' => true]);
    }
}
