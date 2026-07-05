<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class ConsolidatedInvoiceMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Order $order,
        public Collection $documents
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Faturas do seu pedido #' . $this->order->id,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.consolidated-invoice',
            with: [
                'order' => $this->order,
                'documents' => $this->documents,
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $attachments = [];

        foreach ($this->documents as $document) {
            if (empty($document->file_path) || !Storage::disk('local')->exists($document->file_path)) {
                continue;
            }

            $label = match ($document->document_type) {
                'store' => 'fatura_restaurante',
                'delivery' => 'fatura_entrega',
                'platform' => 'fatura_taxa_servico',
                default => 'fatura',
            };

            $attachments[] = Attachment::fromStorageDisk('local', $document->file_path)
                ->as("{$label}_{$this->order->id}.pdf")
                ->withMime('application/pdf');
        }

        return $attachments;
    }
}
