<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JobOrderPayment extends Model
{
    protected $fillable = [
        'shop_id',
        'job_order_id',
        'idempotency_key',
        'amount',
        'payment_type',
        'payment_method',
        'reference_number',
        'notes',
        'received_at',
        'received_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'received_at' => 'datetime',
    ];

    public function setPaymentTypeAttribute(?string $value): void
    {
        $this->attributes['payment_type'] = $value === 'DOWN_PAYMENT' ? 'PARTIAL_PAYMENT' : $value;
    }

    public function jobOrder()
    {
        return $this->belongsTo(JobOrder::class);
    }

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }
}
