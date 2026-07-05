<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PartnerSubscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'partner_type',
        'partner_id',
        'plan',
        'amount',
        'currency_code',
        'starts_at',
        'ends_at',
        'status',
        'payment_reference',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'amount' => 'float',
    ];

    public function scopeActive($query)
    {
        return $query->where('status', 'active')
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>=', now());
    }
}
