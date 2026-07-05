<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PaytmController;
use App\Http\Controllers\LiqPayController;
use App\Http\Controllers\PaymobController;
use App\Http\Controllers\PaytabsController;
use App\Http\Controllers\FirebaseController;
use App\Http\Controllers\PaystackController;
use App\Http\Controllers\RazorPayController;
use App\Http\Controllers\SenangPayController;
use App\Http\Controllers\MercadoPagoController;
use App\Http\Controllers\BkashPaymentController;
use App\Http\Controllers\FlutterwaveV3Controller;
use App\Http\Controllers\PaypalPaymentController;
use App\Http\Controllers\StripePaymentController;
use App\Http\Controllers\SslCommerzPaymentController;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Http\Controllers\RiderRegistrationController;
use App\Http\Controllers\StripeConnectOnboardingController;
use App\Models\Order;
use App\Services\PaymentSplitCalculator;
use App\Models\Store;
use App\CentralLogics\Helpers;
use Stripe\Stripe;
use Stripe\PaymentIntent;
use Illuminate\Support\Facades\Artisan;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/


Route::post('/subscribeToTopic', [FirebaseController::class, 'subscribeToTopic']);

// Debug FCM routes (migrated from V3.8)
Route::post('/debug/setup-fcm', function () {
    $serviceFilePath = base_path('nexo-sixammart-prod-firebase-adminsdk-fbsvc-314c8ae993.json');
    if (!file_exists($serviceFilePath)) {
        return response()->json(['error' => 'Service file not found'], 404);
    }
    $content = json_decode(file_get_contents($serviceFilePath), true);
    if (!$content) {
        return response()->json(['error' => 'Invalid JSON'], 400);
    }

    $projectId = $content['project_id'] ?? null;

    DB::table('business_settings')->updateOrInsert(
        ['key' => 'push_notification_service_file_content'],
        ['value' => json_encode($content), 'updated_at' => now(), 'created_at' => now()]
    );

    if ($projectId) {
        DB::table('business_settings')->updateOrInsert(
            ['key' => 'fcm_project_id'],
            ['value' => $projectId, 'updated_at' => now(), 'created_at' => now()]
        );
    }

    Cache::forget('business_settings_all_data');

    return response()->json([
        'message' => 'FCM service file configured',
        'project_id' => $projectId,
        'current_config' => Helpers::get_business_settings('push_notification_service_file_content'),
    ]);
})->name('debug.setup-fcm')->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class]);

Route::get('/debug/vendor-push/{store_id}', function ($store_id) {
    $store = Store::with('vendor')->find($store_id);
    if (!$store) {
        return response()->json(['error' => 'Store not found'], 404);
    }

    $pushStatus = Helpers::getNotificationStatusData('store', 'store_order_notification', 'push_notification_status', $store_id);

    $data = [
        'title' => 'DEBUG: Test push',
        'description' => 'This is a test push notification from backend debug route',
        'order_id' => 0,
        'module_id' => $store->module_id ?? '',
        'order_type' => 'delivery',
        'image' => '',
        'type' => 'new_order',
        'order_status' => 'pending',
    ];

    $topic = "store_panel_{$store_id}_message";
    $helpersResult = Helpers::send_push_notif_to_topic($data, $topic, 'new_order', url('/') . '/vendor-panel/order/list/all');

    // Manual FCM call to capture raw response
    $config = Helpers::get_business_settings('push_notification_service_file_content');
    $key = (array)$config;
    $manualResult = null;
    if (data_get($key, 'project_id')) {
        $url = 'https://fcm.googleapis.com/v1/projects/' . $key['project_id'] . '/messages:send';
        $headers = [
            'Authorization' => 'Bearer ' . Helpers::getAccessToken($key),
            'Content-Type' => 'application/json',
        ];
        $messagePayload = [
            "topic" => $topic,
            "data" => [
                "title" => (string)$data['title'],
                "body" => (string)$data['description'],
                "order_id" => (string)$data['order_id'],
                "order_type" => (string)$data['order_type'],
                "type" => (string)$data['type'],
                "image" => (string)$data['image'],
                "module_id" => (string)$data['module_id'],
                "click_action" => url('/') . '/vendor-panel/order/list/all',
                "sound" => "notification.wav",
                "order_status" => (string)$data['order_status'],
            ],
            "apns" => [
                "payload" => [
                    "aps" => [
                        "sound" => "notification.wav",
                        "content-available" => 1
                    ]
                ]
            ]
        ];
        try {
            $response = Http::withHeaders($headers)->post($url, ['message' => $messagePayload]);
            $manualResult = [
                'status' => $response->status(),
                'body' => $response->body(),
                'successful' => $response->successful(),
            ];
        } catch (\Exception $e) {
            $manualResult = [
                'status' => 0,
                'body' => $e->getMessage(),
                'successful' => false,
            ];
        }
    } else {
        $manualResult = ['status' => 0, 'body' => 'No FCM project_id configured', 'successful' => false];
    }

    return response()->json([
        'store_id' => $store_id,
        'vendor_id' => $store->vendor_id,
        'vendor_fcm_token' => $store->vendor->firebase_token ?? null,
        'order_confirmation_model' => config('order_confirmation_model'),
        'sub_self_delivery' => $store->sub_self_delivery,
        'push_notification_status' => $pushStatus,
        'topic' => $topic,
        'helpers_result' => $helpersResult,
        'manual_fcm_response' => $manualResult,
    ]);
})->name('debug.vendor-push')->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class]);

Route::get('/debug/vendor-push-device/{store_id}', function ($store_id) {
    $store = Store::with('vendor')->find($store_id);
    if (!$store || !$store->vendor || !$store->vendor->firebase_token) {
        return response()->json(['error' => 'Store/vendor/token not found'], 404);
    }

    $token = $store->vendor->firebase_token;

    $data = [
        'title' => 'DEBUG: Direct token push',
        'description' => 'This is a direct device token test push',
        'order_id' => 0,
        'module_id' => $store->module_id ?? '',
        'order_type' => 'delivery',
        'image' => '',
        'type' => 'new_order',
        'order_status' => 'pending',
    ];

    $config = Helpers::get_business_settings('push_notification_service_file_content');
    $key = (array)$config;
    $manualResult = null;
    if (data_get($key, 'project_id')) {
        $url = 'https://fcm.googleapis.com/v1/projects/' . $key['project_id'] . '/messages:send';
        $headers = [
            'Authorization' => 'Bearer ' . Helpers::getAccessToken($key),
            'Content-Type' => 'application/json',
        ];
        $messagePayload = [
            "token" => $token,
            "data" => [
                "title" => (string)$data['title'],
                "body" => (string)$data['description'],
                "order_id" => (string)$data['order_id'],
                "order_type" => (string)$data['order_type'],
                "type" => (string)$data['type'],
                "image" => (string)$data['image'],
                "module_id" => (string)$data['module_id'],
                "click_action" => url('/') . '/vendor-panel/order/list/all',
                "sound" => "notification.wav",
                "order_status" => (string)$data['order_status'],
            ],
            "apns" => [
                "payload" => [
                    "aps" => [
                        "sound" => "notification.wav",
                        "content-available" => 1
                    ]
                ]
            ]
        ];
        try {
            $response = Http::withHeaders($headers)->post($url, ['message' => $messagePayload]);
            $manualResult = [
                'status' => $response->status(),
                'body' => $response->body(),
                'successful' => $response->successful(),
            ];
        } catch (\Exception $e) {
            $manualResult = [
                'status' => 0,
                'body' => $e->getMessage(),
                'successful' => false,
            ];
        }
    } else {
        $manualResult = ['status' => 0, 'body' => 'No FCM project_id configured', 'successful' => false];
    }

    return response()->json([
        'store_id' => $store_id,
        'vendor_fcm_token' => $token,
        'direct_token_push_result' => $manualResult,
    ]);
})->name('debug.vendor-push-device')->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class]);

Route::get('/debug/check-token-topics/{token}', function ($token) {
    $config = Helpers::get_business_settings('push_notification_service_file_content');
    $key = (array)$config;
    if (!data_get($key, 'project_id')) {
        return response()->json(['error' => 'No FCM project_id configured'], 500);
    }

    $accessToken = Helpers::getAccessToken($key);
    try {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $accessToken,
        ])->get('https://iid.googleapis.com/iid/info/' . $token . '?details=true');

        return response()->json([
            'token' => $token,
            'status' => $response->status(),
            'body' => $response->json(),
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'token' => $token,
            'error' => $e->getMessage(),
        ], 500);
    }
})->name('debug.check-token-topics')->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class]);

// Stripe Connect onboarding callbacks (used by mobile apps and partner payment account flows)
Route::get('partner/payment-account/stripe_connect/return', [StripeConnectOnboardingController::class, 'return'])->name('stripe_connect.return');
Route::get('partner/payment-account/stripe_connect/refresh', [StripeConnectOnboardingController::class, 'refresh'])->name('stripe_connect.refresh');

Route::get('/', 'HomeController@index')->name('home');
Route::get('maintenance-mode', 'HomeController@maintenanceMode')->name('maintenance_mode');
Route::get('lang/{locale}', 'HomeController@lang')->name('lang');
Route::get('terms-and-conditions', 'HomeController@terms_and_conditions')->name('terms-and-conditions');
Route::get('about-us', 'HomeController@about_us')->name('about-us');
Route::get('contact-us', 'HomeController@contact_us')->name('contact-us');
Route::post('send-message', 'HomeController@send_message')->name('send-message');
Route::get('privacy-policy', 'HomeController@privacy_policy')->name('privacy-policy');
Route::get('cancelation', 'HomeController@cancelation')->name('cancelation');
Route::get('refund', 'HomeController@refund_policy')->name('refund');
Route::get('shipping-policy', 'HomeController@shipping_policy')->name('shipping-policy');
Route::post('newsletter/subscribe', 'NewsletterController@newsLetterSubscribe')->name('newsletter.subscribe');
Route::get('subscription-invoice/{id}', 'HomeController@subscription_invoice')->name('subscription_invoice');
Route::get('order-invoice/{id}', 'HomeController@order_invoice')->name('order_invoice');
Route::get('deliveryman-earning-report-invoice/{id}', 'HomeController@earningReportInvoice')->name('delivery_earning_invoice')->middleware('localization');
Route::get('activation-check', 'HomeController@getActivationCheckView')->name('system.activation-check');
Route::post('activation-check', 'HomeController@activationCheck');

Route::get('login/{tab}', 'LoginController@login')->name('login');
Route::post('login_submit', 'LoginController@submit')->name('login_post')->middleware('actch');
Route::get('logout', 'LoginController@logout')->name('logout');
Route::get('/reload-captcha', 'LoginController@reloadCaptcha')->name('reload-captcha');
Route::post('/reset-password', 'LoginController@reset_password_request')->name('reset-password')->middleware('throttle:3,60');
Route::post('/vendor-reset-password', 'LoginController@vendor_reset_password_request')->name('vendor-reset-password')->middleware('throttle:3,60');
Route::get('/password-reset', 'LoginController@reset_password')->name('change-password');
Route::post('verify-otp', 'LoginController@verify_token')->name('verify-otp');
Route::post('reset-password-submit', 'LoginController@reset_password_submit')->name('reset-password-submit');
Route::get('otp-resent', 'LoginController@otp_resent')->name('otp_resent');

Route::get('authentication-failed', function () {
    $errors = [];
    array_push($errors, ['code' => 'auth-001', 'message' => 'Unauthenticated.']);
    return response()->json([
        'errors' => $errors,
    ], 401);
})->name('authentication-failed');

Route::group(['prefix' => 'payment-mobile'], function () {
    Route::get('/', 'PaymentController@payment')->name('payment-mobile');
    Route::get('set-payment-method/{name}', 'PaymentController@set_payment_method')->name('set-payment-method');
});

Route::get('payment-success', 'PaymentController@success')->name('payment-success');
Route::get('payment-fail', 'PaymentController@fail')->name('payment-fail');
Route::get('payment-cancel', 'PaymentController@cancel')->name('payment-cancel');

// Webhooks for Ryft and MangoPay
Route::post('webhooks/ryft', \App\Http\Controllers\Webhook\RyftWebhookController::class)
    ->name('webhooks.ryft')
    ->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class]);

Route::post('webhooks/mangopay', \App\Http\Controllers\Webhook\MangoPayWebhookController::class)
    ->name('webhooks.mangopay')
    ->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class]);

Route::post('webhooks/stripe-connect', \App\Http\Controllers\Webhook\StripeConnectWebhookController::class)
    ->name('webhooks.stripe_connect')
    ->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class]);

Route::match(['get', 'post'], 'webhooks/eupago', \App\Http\Controllers\Webhook\EuPagoWebhookController::class)
    ->name('webhooks.eupago')
    ->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class]);

$is_published = 0;
try {
$full_data = include('Modules/Gateways/Addon/info.php');
$is_published = $full_data['is_published'] == 1 ? 1 : 0;
} catch (\Exception $exception) {}

if (!$is_published) {
    Route::group(['prefix' => 'payment'], function () {

        //SSLCOMMERZ
        Route::group(['prefix' => 'sslcommerz', 'as' => 'sslcommerz.'], function () {
            Route::get('pay', [SslCommerzPaymentController::class, 'index'])->name('pay');
            Route::post('success', [SslCommerzPaymentController::class, 'success'])
                ->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class]);
            Route::post('failed', [SslCommerzPaymentController::class, 'failed'])
                ->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class]);
            Route::post('canceled', [SslCommerzPaymentController::class, 'canceled'])
                ->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class]);
        });

        //STRIPE
        Route::group(['prefix' => 'stripe', 'as' => 'stripe.'], function () {
            Route::get('pay', [StripePaymentController::class, 'index'])->name('pay');
            Route::get('token', [StripePaymentController::class, 'payment_process_3d'])->name('token');
            Route::get('success', [StripePaymentController::class, 'success'])->name('success');
            Route::get('canceled', [StripePaymentController::class, 'canceled'])
                ->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class]);
        });

      //RAZOR-PAY
      Route::group(['prefix' => 'razor-pay', 'as' => 'razor-pay.'], function () {
        Route::get('pay', [RazorPayController::class, 'index']);
        Route::post('payment', [RazorPayController::class, 'payment'])->name('payment')
            ->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class]);
        Route::post('callback', [RazorPayController::class, 'callback'])->name('callback')
            ->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class]);
        Route::any('cancel', [RazorPayController::class, 'cancel'])->name('cancel')
            ->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class]);

        Route::any('create-order', [RazorPayController::class, 'createOrder'])->name('create-order')
            ->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class]);
        Route::any('verify-payment', [RazorPayController::class, 'verifyPayment'])->name('verify-payment')
            ->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class]);
    });

        //PAYPAL
        Route::group(['prefix' => 'paypal', 'as' => 'paypal.'], function () {
            Route::get('pay', [PaypalPaymentController::class, 'payment']);
            Route::any('success', [PaypalPaymentController::class, 'success'])->name('success')
                ->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class]);;
            Route::any('cancel', [PaypalPaymentController::class, 'cancel'])->name('cancel')
                ->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class]);;
        });

        //SENANG-PAY
        Route::group(['prefix' => 'senang-pay', 'as' => 'senang-pay.'], function () {
            Route::get('pay', [SenangPayController::class, 'index']);
            Route::any('callback', [SenangPayController::class, 'return_senang_pay']);
        });

        //PAYTM
        Route::group(['prefix' => 'paytm', 'as' => 'paytm.'], function () {
            Route::get('pay', [PaytmController::class, 'payment']);
            Route::any('response', [PaytmController::class, 'callback'])->name('response')
            ->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class]);
        });

        //FLUTTERWAVE
        Route::group(['prefix' => 'flutterwave-v3', 'as' => 'flutterwave-v3.'], function () {
            Route::get('pay', [FlutterwaveV3Controller::class, 'initialize'])->name('pay');
            Route::get('callback', [FlutterwaveV3Controller::class, 'callback'])->name('callback');
        });

        //PAYSTACK
        Route::group(['prefix' => 'paystack', 'as' => 'paystack.'], function () {
            Route::get('pay', [PaystackController::class, 'index'])->name('pay');
            Route::get('callback', [PaystackController::class, 'handleGatewayCallback'])->name('callback');
            Route::get('cancel', [PaystackController::class, 'cancel'])->name('cancel');
        });

        //BKASH
        Route::group(['prefix' => 'bkash', 'as' => 'bkash.'], function () {
            // Payment Routes for bKash
            Route::get('make-payment', [BkashPaymentController::class, 'make_tokenize_payment'])->name('make-payment');
            Route::any('callback', [BkashPaymentController::class, 'callback'])->name('callback');

            // Refund Routes for bKash
            // Route::get('refund', 'BkashRefundController@index')->name('bkash-refund');
            // Route::post('refund', 'BkashRefundController@refund')->name('bkash-refund');
        });

        //Liqpay
        Route::group(['prefix' => 'liqpay', 'as' => 'liqpay.'], function () {
            Route::get('payment', [LiqPayController::class, 'payment'])->name('payment');
            Route::any('callback', [LiqPayController::class, 'callback'])->name('callback');
        });

        //MERCADOPAGO
          Route::group(['prefix' => 'mercadopago', 'as' => 'mercadopago.'], function () {
            Route::get('pay', [MercadoPagoController::class, 'index'])->name('index');
            Route::post('make-payment', [MercadoPagoController::class, 'make_payment'])->name('make_payment')->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class]);;
            Route::any('callback', [MercadoPagoController::class, 'callback'])->name('callback')->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class]);;
        });

        //PAYMOB
        Route::group(['prefix' => 'paymob', 'as' => 'paymob.'], function () {
            Route::any('pay', [PaymobController::class, 'credit'])->name('pay');
            Route::any('callback', [PaymobController::class, 'callback'])->name('callback');
        });

        //PAYTABS
        Route::group(['prefix' => 'paytabs', 'as' => 'paytabs.'], function () {
            Route::any('pay', [PaytabsController::class, 'payment'])->name('pay');
            Route::any('callback', [PaytabsController::class, 'callback'])->name('callback');
            Route::any('response', [PaytabsController::class, 'response'])->name('response');
        });
    });
}


Route::get('/test', function () {
    Artisan::call('optimize:clear');
dd('Hello tester');
});

Route::get('module-test', function () {
});

//Restaurant Registration
Route::group(['prefix' => 'vendor', 'as' => 'restaurant.'], function () {
    Route::get('apply', 'VendorController@create')->name('create');
    Route::post('apply', 'VendorController@store')->name('store');
    Route::get('get-all-modules', 'VendorController@get_all_modules')->name('get-all-modules');
    Route::get('get-module-type', 'VendorController@get_modules_type')->name('get-module-type');
    Route::get('check-module-type', 'VendorController@check_module_type')->name('check-module-type');

    Route::get('back', 'VendorController@back')->name('back');
    Route::post('business-plan', 'VendorController@business_plan')->name('business_plan');
    Route::get('business-plan', 'VendorController@secondStep')->name('secondStep');
    Route::post('payment', 'VendorController@payment')->name('payment');
    Route::get('final-step', 'VendorController@final_step')->name('final_step');
    Route::get('stripe-onboarding/return', 'VendorController@stripeOnboardingReturn')->name('stripe_onboarding_return');
    Route::get('stripe-onboarding/refresh', 'VendorController@stripeOnboardingRefresh')->name('stripe_onboarding_refresh');
});

//Rider Registration
Route::group(['prefix' => 'rider', 'as' => 'rider.'], function () {
    Route::get('apply', [RiderRegistrationController::class, 'create'])->name('create');
    Route::post('apply', [RiderRegistrationController::class, 'store'])->name('store');
});

//Deliveryman Registration
Route::group(['prefix' => 'deliveryman', 'as' => 'deliveryman.'], function () {
    Route::get('apply', 'DeliveryManController@create')->name('create');
    Route::post('apply', 'DeliveryManController@store')->name('store');

});

Route::get('/image-proxy', function () {
    $url = request('url');
    if (!$url) {
        abort(400, 'Missing url parameter');
    }

    $response = Http::withHeaders([
        'User-Agent' => 'Laravel-Image-Proxy'
    ])->get($url);

    return response($response->body(), $response->status())
        ->header('Content-Type', $response->header('Content-Type'))
        ->header('Access-Control-Allow-Origin', '*');
});
