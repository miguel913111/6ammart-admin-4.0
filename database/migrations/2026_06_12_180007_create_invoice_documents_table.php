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
        Schema::create('invoice_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id');
            $table->string('document_type', 50); // store, delivery, platform
            $table->string('nif', 50)->nullable();
            $table->string('series', 50)->nullable();
            $table->string('external_id', 100)->nullable();
            $table->string('reference_number', 100)->nullable();
            $table->string('file_path', 255)->nullable();
            $table->string('status', 50)->default('pending'); // pending, issued, failed
            $table->text('error_message')->nullable();
            $table->unsignedTinyInteger('retry_count')->default(0);
            $table->timestamps();

            $table->index(['order_id', 'document_type']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_documents');
    }
};
