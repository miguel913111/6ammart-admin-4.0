<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\PartnerPaymentOrchestrator;
use App\Services\PaymentGateway\PartnerGatewayFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PartnerPaymentAccountController extends Controller
{
    public function __construct(
        private readonly PartnerPaymentOrchestrator $orchestrator,
    ) {
    }

    /**
     * Return the current payment account status for the authenticated partner.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $partner = $this->orchestrator->resolvePartner($user);

        if (!$partner) {
            return response()->json(['errors' => ['message' => 'Partner profile not found']], 404);
        }

        return response()->json([
            'accounts' => [
                'stripe_connect' => [
                    'account_id' => $partner->stripe_account_id,
                ],
                'ryft' => [
                    'account_id' => $partner->ryft_sub_account_id,
                ],
                'mangopay' => [
                    'user_id' => $partner->mangopay_user_id,
                    'wallet_id' => $partner->mangopay_wallet_id,
                ],
            ],
            'status' => $this->orchestrator->status($partner),
        ]);
    }

    /**
     * Create a payment account and return the onboarding link for the authenticated partner.
     *
     * Supported user types: store (restaurant) and delivery_man.
     * Supported providers: stripe_connect, ryft, mangopay.
     */
    public function store(Request $request, string $provider): JsonResponse
    {
        if (!in_array($provider, PartnerGatewayFactory::supported(), true)) {
            return response()->json(['errors' => ['message' => 'Invalid provider']], 400);
        }

        $user = $request->user();
        $partner = $this->orchestrator->resolvePartner($user);

        if (!$partner) {
            return response()->json(['errors' => ['message' => 'Partner profile not found']], 404);
        }

        try {
            $result = $this->orchestrator->onboard(
                partner: $partner,
                gateway: $provider,
                urls: $this->orchestrator->onboardingUrls($request),
            );

            return response()->json([
                'provider' => $provider,
                ...$result,
            ]);
        } catch (\Throwable $e) {
            Log::error('PartnerPaymentAccountController: failed to create account', [
                'user_id' => $user->id,
                'user_type' => $user->user_type ?? 'unknown',
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['errors' => ['message' => 'Failed to create payment account']], 500);
        }
    }
}
