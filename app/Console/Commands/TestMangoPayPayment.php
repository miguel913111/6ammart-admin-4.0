<?php

namespace App\Console\Commands;

use App\Jobs\ProcessMangoPayPaymentJob;
use App\Models\Order;
use App\Services\MangoPayService;
use App\Services\PaymentSplitCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TestMangoPayPayment extends Command
{
    protected $signature = 'test:mangopay-payment {order_id? : ID da order a testar}';

    protected $description = 'Simula um pagamento MangoPay completo (6amMart)';

    public function handle(): int
    {
        $orderId = $this->argument('order_id');

        if (!$orderId) {
            $this->warn('Uso: php artisan test:mangopay-payment {order_id}');
            return self::FAILURE;
        }

        $order = Order::with(['store', 'delivery_man', 'customer'])->find($orderId);

        if (!$order) {
            $this->error("Order #{$orderId} não encontrada.");
            return self::FAILURE;
        }

        $this->info("Order #{$order->id} encontrada. Valor: {$order->order_amount} €");

        $mangoPayService = new MangoPayService();

        // 1. Criar contas MangoPay para loja e entregador se necessário
        $store = $order->store;
        if ($store && empty($store->mangopay_user_id)) {
            $this->info('A criar conta MangoPay + wallet para a loja...');
            $mangoPayService->createPartnerAccount($store);
            $this->info("Conta loja criada: user={$store->mangopay_user_id} wallet={$store->mangopay_wallet_id}");
        }

        $deliveryMan = $order->delivery_man;
        if ($deliveryMan && empty($deliveryMan->mangopay_user_id)) {
            $this->info('A criar conta MangoPay + wallet para o entregador...');
            $mangoPayService->createPartnerAccount($deliveryMan);
            $this->info("Conta entregador criada: user={$deliveryMan->mangopay_user_id} wallet={$deliveryMan->mangopay_wallet_id}");
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

        // 2. Criar PayIn na MangoPay
        $this->info('A criar PayIn na MangoPay...');
        $session = $mangoPayService->createPaymentSession($order, $split);

        $this->info("PayIn criado: {$session['id']}");

        $order->payment_method = 'mangopay';
        $order->mangopay_payin_id = $session['id'];
        $order->payment_session_client_token = $session['client_token'] ?? null;
        $order->payment_session_status = $session['status'] ?? 'CREATED';
        $order->payment_split_payload = $split;
        $order->save();

        // 3. Simular webhook
        $this->info('A simular webhook PAYIN_NORMAL_SUCCEEDED...');
        $webhookPayload = [
            'EventType' => 'PAYIN_NORMAL_SUCCEEDED',
            'ResourceId' => $session['id'],
            'Date' => now()->timestamp,
            'Tag' => 'order_' . $order->id,
        ];

        $response = \Illuminate\Support\Facades\Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->post(url('/webhooks/mangopay'), $webhookPayload);

        if ($response->failed()) {
            $this->error('Webhook falhou: ' . $response->body());
            Log::error('TestMangoPayPayment: webhook failed', ['body' => $response->body()]);
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
