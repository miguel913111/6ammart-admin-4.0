<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('partner_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('partner_type', 50); // store, delivery_man
            $table->unsignedBigInteger('partner_id');
            $table->string('plan', 50)->default('monthly'); // monthly, yearly
            $table->decimal('amount', 24, 3)->default(0);
            $table->string('currency_code', 10)->default('EUR');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('status', 50)->default('active'); // active, cancelled, expired
            $table->string('payment_reference', 100)->nullable();
            $table->timestamps();

            $table->index(['partner_type', 'partner_id']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('partner_subscriptions');
    }
};
