<?php

namespace App\Console\Commands;

use App\Jobs\ProcessRyftPaymentJob;
use App\Models\Order;
use App\Services\PaymentSplitCalculator;
use App\Services\RyftService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TestRyftPayment extends Command
{
    protected $signature = 'test:ryft-payment {order_id? : ID da order a testar}';

    protected $description = 'Simula um pagamento Ryft completo (6amMart)';

    public function handle(): int
    {
        $orderId = $this->argument('order_id');

        if (!$orderId) {
            $this->warn('Uso: php artisan test:ryft-payment {order_id}');
            return self::FAILURE;
        }

        $order = Order::with(['store', 'delivery_man', 'customer'])->find($orderId);

        if (!$order) {
            $this->error("Order #{$orderId} não encontrada.");
            return self::FAILURE;
        }

        $this->info("Order #{$order->id} encontrada. Valor: {$order->order_amount} €");

        $ryftService = new RyftService();

        // 1. Criar sub-contas para loja e entregador se necessário
        $store = $order->store;
        if ($store && empty($store->ryft_sub_account_id)) {
            $this->info('A criar sub-conta Ryft para a loja...');
            $account = $ryftService->createConnectedAccount($store);
            $ryftService->savePartnerAccount($store, $account);
            $this->info("Conta loja criada: {$account['id']}");
        }

        $deliveryMan = $order->delivery_man;
        if ($deliveryMan && empty($deliveryMan->ryft_sub_account_id)) {
            $this->info('A criar sub-conta Ryft para o entregador...');
            $account = $ryftService->createConnectedAccount($deliveryMan);
            $ryftService->savePartnerAccount($deliveryMan, $account);
            $this->info("Conta entregador criada: {$account['id']}");
        }

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

        // 2. Criar PaymentSession na Ryft
        $this->info('A criar PaymentSession na Ryft...');
        $session = $ryftService->createPaymentSession($order, $split);

        $this->info("PaymentSession criado: {$session['id']}");

        $order->payment_method = 'ryft';
        $order->payment_session_id = $session['id'];
        $order->payment_session_client_token = $session['client_token'] ?? null;
        $order->payment_session_status = $session['status'] ?? 'requires_payment_method';
        $order->payment_split_payload = $split;
        $order->save();

        // 3. Simular webhook
        $this->info('A simular webhook PaymentSession.captured...');
        $webhookPayload = [
            'id' => 'evt_test_' . uniqid(),
            'event_type' => 'PaymentSession.captured',
            'type' => 'PaymentSession.captured',
            'data' => [
                'id' => $session['id'],
                'status' => 'captured',
                'amount' => (int) round($split['total'] * 100),
                'currency' => 'eur',
                'metadata' => [
                    'order_id' => (string) $order->id,
                    'platform' => 'sixammart',
                ],
            ],
        ];

        $response = \Illuminate\Support\Facades\Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->post(url('/webhooks/ryft'), $webhookPayload);

        if ($response->failed()) {
            $this->error('Webhook falhou: ' . $response->body());
            Log::error('TestRyftPayment: webhook failed', ['body' => $response->body()]);
            return self::FAILURE;
        }

        $this->info('Webhook aceite: ' . $response->body());

        // 4. Processar fila
        $this->info('A processar jobs da fila...');
        $this->call('queue:work', [
            '--stop-when-empty' => true,
        ]);

        // 5. Verificar estado final
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

        return self::SUCCESS;
    }
}
