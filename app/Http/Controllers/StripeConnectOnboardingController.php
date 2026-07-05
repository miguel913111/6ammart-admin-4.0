<?php

namespace App\Http\Controllers;

use App\Models\DeliveryMan;
use App\Models\Store;
use App\Services\StripeConnectService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class StripeConnectOnboardingController extends Controller
{
    /**
     * Stripe Connect onboarding return URL.
     *
     * Updates the partner account status and shows a completion page.
     * Accepts either store_id, delivery_man_id or account_id to identify the partner.
     */
    public function return(Request $request)
    {
        $partner = $this->resolvePartner($request);

        if ($partner && !empty($partner->gateway_account_id ?? $partner->stripe_account_id)) {
            try {
                $stripeService = new StripeConnectService();
                $accountId = $partner->gateway_account_id ?? $partner->stripe_account_id;
                $status = $stripeService->retrieveAccount($accountId);

                $chargesEnabled = $status['charges_enabled'] ?? false;
                $currentlyDue = $status['requirements']['currently_due'] ?? [];

                $partner->gateway_account_status = $chargesEnabled ? 'active' : 'pending';
                $partner->kyc_status = empty($currentlyDue) ? 'verified' : 'pending';
                if ($partner->kyc_status === 'verified') {
                    $partner->kyc_verified_at = $partner->kyc_verified_at ?? now();
                }
                $partner->save();
            } catch (\Exception $e) {
                Log::error('Stripe Connect onboarding return failed', [
                    'partner_type' => $partner instanceof Store ? 'store' : 'delivery_man',
                    'partner_id' => $partner->id ?? null,
                    'account_id' => $partner->gateway_account_id ?? $partner->stripe_account_id ?? null,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return view('partner-onboarding-complete');
    }

    /**
     * Stripe Connect onboarding refresh URL.
     *
     * Generates a new onboarding link and redirects the user back to Stripe.
     */
    public function refresh(Request $request)
    {
        $partner = $this->resolvePartner($request);

        if ($partner && !empty($partner->gateway_account_id ?? $partner->stripe_account_id)) {
            try {
                $stripeService = new StripeConnectService();
                $accountId = $partner->gateway_account_id ?? $partner->stripe_account_id;
                $params = $this->partnerRouteParams($partner);

                $link = $stripeService->createAccountLink(
                    $accountId,
                    route('stripe_connect.refresh', $params),
                    route('stripe_connect.return', $params)
                );

                return redirect()->away($link['url']);
            } catch (\Exception $e) {
                Log::error('Stripe Connect onboarding refresh failed', [
                    'partner_type' => $partner instanceof Store ? 'store' : 'delivery_man',
                    'partner_id' => $partner->id ?? null,
                    'account_id' => $partner->gateway_account_id ?? $partner->stripe_account_id ?? null,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return redirect()->route('home');
    }

    /**
     * Resolve a partner (Store or DeliveryMan) from the request.
     */
    private function resolvePartner(Request $request): ?object
    {
        if ($request->filled('store_id')) {
            return Store::find($request->input('store_id'));
        }

        if ($request->filled('delivery_man_id')) {
            return DeliveryMan::find($request->input('delivery_man_id'));
        }

        if ($request->filled('account_id')) {
            $store = Store::where('gateway_account_id', $request->input('account_id'))
                ->orWhere('stripe_account_id', $request->input('account_id'))
                ->first();

            if ($store) {
                return $store;
            }

            return DeliveryMan::where('gateway_account_id', $request->input('account_id'))
                ->orWhere('stripe_account_id', $request->input('account_id'))
                ->first();
        }

        return null;
    }

    /**
     * Build route parameters for callbacks based on partner type.
     */
    private function partnerRouteParams(object $partner): array
    {
        if ($partner instanceof Store) {
            return ['store_id' => $partner->id];
        }

        return ['delivery_man_id' => $partner->id];
    }
}
