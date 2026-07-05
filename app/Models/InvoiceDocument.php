<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InvoiceDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'document_type',
        'nif',
        'series',
        'external_id',
        'reference_number',
        'file_path',
        'status',
        'error_message',
        'retry_count',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
