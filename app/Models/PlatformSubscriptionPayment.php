<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformSubscriptionPayment extends Model
{
    protected $fillable = [
        'shop_id',
        'amount',
        'payment_method',
        'reference_number',
        'covered_from',
        'covered_until',
        'notes',
        'received_at',
        'received_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'covered_from' => 'date',
        'covered_until' => 'date',
        'received_at' => 'datetime',
    ];

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }
}
