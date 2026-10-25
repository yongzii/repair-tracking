<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JobOrderRefund extends Model
{
    public const STATUS_PENDING = 'PENDING';

    public const STATUS_ISSUED = 'ISSUED';

    protected $fillable = [
        'shop_id',
        'job_order_id',
        'job_order_payment_id',
        'amount',
        'status',
        'reason',
        'refund_method',
        'reference_number',
        'reconciliation_reason',
        'initiated_by',
        'issued_by',
        'initiated_at',
        'issued_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'initiated_at' => 'datetime',
        'issued_at' => 'datetime',
    ];

    public function jobOrder()
    {
        return $this->belongsTo(JobOrder::class);
    }

    public function payment()
    {
        return $this->belongsTo(JobOrderPayment::class, 'job_order_payment_id');
    }
}
