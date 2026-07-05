<?php

namespace App\Console\Commands;

use App\Jobs\ProcessStripeConnectPaymentJob;
use App\Models\Order;
use App\Services\PaymentSplitCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Stripe\PaymentIntent;
use Stripe\PaymentMethod;
use Stripe\Stripe;

class TestStripePayment extends Command
{
    protected $signature = 'test:stripe-payment {order_id? : ID da order a testar}';

    protected $description = 'Simula um pagamento Stripe Connect completo (sem transferências reais)';

    public function handle(): int
    {
        $orderId = $this->argument('order_id');

        if (!$orderId) {
            $this->warn('Uso: php artisan test:stripe-payment {order_id}');
            $this->warn('Podes criar uma order primeiro ou usar uma existente (ex: 6).');
            return self::FAILURE;
        }

        $order = Order::with(['store', 'delivery_man', 'customer'])->find($orderId);

        if (!$order) {
            $this->error("Order #{$orderId} não encontrada.");
            return self::FAILURE;
        }

        $this->info("Order #{$order->id} encontrada. Valor: {$order->order_amount} €");

        Stripe::setApiKey(config('services.stripe_connect.secret_key'));

        $split = PaymentSplitCalculator::forSixamMart($order);

        $this->info('Split calculado:');
        $this->table(
            ['Entidade', 'Valor (€)'],
            [
                ['Total cliente', $split['total']],
                ['Store gross', $split['store_gross']],
                ['Delivery gross', $split['delivery_gross']],
                ['Taxa plataforma (bruto)', $split['platform_fee_brutto']],
                ['Taxa plataforma (base)', $split['platform_fee_base']],
                ['IVA plataforma', $split['platform_fee_vat']],
                ['Store net', $split['store_net']],
                ['Delivery net', $split['delivery_net']],
            ]
        );

        // 1. Criar PaymentIntent
        $this->info('A criar PaymentIntent na Stripe...');
        $paymentIntent = PaymentIntent::create([
            'amount' => (int) round($split['total'] * 100),
            'currency' => 'eur',
            'automatic_payment_methods' => [
                'enabled' => true,
                'allow_redirects' => 'never',
            ],
            'transfer_group' => "order_{$order->id}",
            'metadata' => [
                'order_id' => $order->id,
                'platform' => 'sixammart',
            ],
        ]);

        $this->info("PaymentIntent criado: {$paymentIntent->id}");

        // Guardar IDs na order para referência
        $order->payment_method = 'stripe_connect';
        $order->payment_session_id = $paymentIntent->id;
        $order->payment_session_client_token = $paymentIntent->client_secret;
        $order->payment_session_status = $paymentIntent->status;
        $order->save();

        // 2. Criar PaymentMethod de teste usando token visa
        $this->info('A criar PaymentMethod de teste (tok_visa)...');
        $paymentMethod = PaymentMethod::create([
            'type' => 'card',
            'card' => [
                'token' => 'tok_visa',
            ],
        ]);

        $this->info("PaymentMethod criado: {$paymentMethod->id}");

        // 3. Confirmar PaymentIntent
        $this->info('A confirmar pagamento...');
        $confirmed = PaymentIntent::retrieve($paymentIntent->id);
        $confirmed->confirm(['payment_method' => $paymentMethod->id]);

        $this->info("Estado do PaymentIntent: {$confirmed->status}");

        if ($confirmed->status !== 'succeeded') {
            $this->warn('O pagamento não ficou em succeeded imediatamente.');
            $this->warn('Verifica no Stripe Dashboard ou aguarda webhook.');
        }

        // 4. Simular webhook payment_intent.succeeded
        $this->info('A enviar webhook payment_intent.succeeded para http://127.0.0.1:8000/webhooks/stripe-connect ...');

        $webhookPayload = [
            'id' => 'evt_test_' . uniqid(),
            'object' => 'event',
            'api_version' => '2024-06-20',
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => $paymentIntent->id,
                    'object' => 'payment_intent',
                    'amount' => $confirmed->amount,
                    'currency' => $confirmed->currency,
                    'status' => 'succeeded',
                    'transfer_group' => "order_{$order->id}",
                    'metadata' => [
                        'order_id' => (string) $order->id,
                        'platform' => 'sixammart',
                    ],
                ],
            ],
        ];

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->post('http://127.0.0.1:8000/webhooks/stripe-connect', $webhookPayload);

        if ($response->failed()) {
            $this->error('Webhook falhou: ' . $response->body());
            Log::error('TestStripePayment: webhook failed', ['body' => $response->body()]);
            return self::FAILURE;
        }

        $this->info('Webhook aceite: ' . $response->body());

        // 5. Processar fila
        $this->info('A processar jobs da fila...');
        $this->call('queue:work', [
            '--stop-when-empty' => true,
        ]);

        // 6. Verificar estado final
        $order->refresh();
        $transaction = \Illuminate\Support\Facades\DB::table('order_transactions')
            ->where('order_id', $order->id)
            ->first();
        $invoices = \Illuminate\Support\Facades\DB::table('invoice_documents')
            ->where('order_id', $order->id)
            ->get();

        $this->newLine();
        $this->info('=== RESULTADO FINAL ===');
        $this->info('Order payment_status: ' . $order->payment_status);
        $this->info('Order payment_split_status: ' . $order->payment_split_status);
        $this->info('Order payment_session_status: ' . $order->payment_session_status);

        if ($transaction) {
            $this->info('OrderTransaction: id=' . $transaction->id . ' | amount=' . $transaction->order_amount . ' | status=' . $transaction->status);
        } else {
            $this->warn('OrderTransaction: NÃO CRIADA');
        }

        $this->info('Faturas geradas: ' . $invoices->count());
        foreach ($invoices as $invoice) {
            $this->info(" - {$invoice->document_type}: {$invoice->external_id} ({$invoice->status})");
        }

        $failedJobs = \Illuminate\Support\Facades\DB::table('failed_jobs')->count();
        $this->info('Failed jobs: ' . $failedJobs);

        if ($failedJobs > 0) {
            $this->warn('Há jobs falhados. Corre: php artisan queue:failed');
        }

        $this->newLine();
        $this->info('Verifica o Mailtrap para confirmar que o email chegou.');
        $this->info('URL: https://mailtrap.io/inboxes/4710065/messages');

        return self::SUCCESS;
    }
}
