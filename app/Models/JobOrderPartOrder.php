<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JobOrderPartOrder extends Model
{
    public const DECISION_PENDING = 'pending';
    public const DECISION_APPROVED = 'approved';
    public const DECISION_REJECTED = 'rejected';

    public const STATUS_AWAITING_APPROVAL = 'awaiting_approval';
    public const STATUS_AWAITING_DOWNPAYMENT = 'awaiting_downpayment';
    public const STATUS_READY_TO_ORDER = 'ready_to_order';
    public const STATUS_ORDERED = 'ordered';
    public const STATUS_RECEIVED = 'received';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'shop_id',
        'job_order_id',
        'idempotency_key',
        'idempotency_payload_hash',
        'requested_by_user_id',
        'customer_decision_recorded_by_user_id',
        'received_by_user_id',
        'issued_by_user_id',
        'cancelled_by_user_id',
        'inventory_spare_part_id',
        'part_name',
        'quantity',
        'reason',
        'estimated_unit_price',
        'customer_decision',
        'customer_decision_at',
        'customer_decision_note',
        'required_downpayment_amount',
        'status',
        'supplier_name',
        'purchase_reference',
        'expected_arrival_date',
        'received_at',
        'issued_at',
        'cancelled_at',
        'cancellation_reason',
    ];

    protected $casts = [
        'estimated_unit_price' => 'decimal:2',
        'required_downpayment_amount' => 'decimal:2',
        'expected_arrival_date' => 'date',
        'customer_decision_at' => 'datetime',
        'received_at' => 'datetime',
        'issued_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function jobOrder()
    {
        return $this->belongsTo(JobOrder::class)->withTrashed();
    }

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function customerDecisionRecordedBy()
    {
        return $this->belongsTo(User::class, 'customer_decision_recorded_by_user_id');
    }

    public function receivedBy()
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }

    public function issuedBy()
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }

    public function cancelledBy()
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    public function inventoryPart()
    {
        return $this->belongsTo(SparePart::class, 'inventory_spare_part_id');
    }

    public function issuedJobOrderPart()
    {
        return $this->hasOne(JobOrderPart::class);
    }
}
