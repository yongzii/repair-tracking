<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

class JobOrder extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'shop_id',
        'job_order_number',
        'service_type',
        'warranty_parent_job_order_id',
        'customer_name',
        'customer_contact',
        'customer_email',
        'customer_phone',
        'device_type',
        'service_category_id',
        'service_item_id',
        'brand',
        'model',
        'serial_number',
        'problem_description',
        'received_by',
        'included_accessories',
        'repair_approved_at',
        'repair_approved_by_customer',
        'repair_approval_recorded_by',
        'repair_approval_notes',
        'current_status',
        'priority',
        'estimated_cost',
        'parts_cost',
        'labor_cost',
        'total_cost',
        'payment_status',
        'amount_paid',
        'payment_date',
        'payment_notes',
        'warranty_days',
        'warranty_details',
        'service_performed',
        'internal_notes',
        'repeat_customer_count',
        'estimated_completion',
        'picked_up_at',
        'picked_up_by',
        'pickup_override_by',
        'pickup_override_reason',
        'technician_id',
        'technician_rating',
    ];

    protected $casts = [
        'picked_up_at' => 'datetime',
        'repair_approved_at' => 'datetime',
        'payment_date' => 'date',
        'estimated_completion' => 'datetime',
        'parts_cost' => 'decimal:2',
        'labor_cost' => 'decimal:2',
        'total_cost' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'estimated_cost' => 'decimal:2',
    ];

    public function statusHistories()
    {
        return $this->hasMany(StatusHistory::class);
    }

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function parts()
    {
        return $this->hasMany(JobOrderPart::class);
    }

    public function payments()
    {
        return $this->hasMany(JobOrderPayment::class);
    }

    public function repairPhotos()
    {
        return $this->hasMany(RepairPhoto::class);
    }

    public function customerNotifications()
    {
        return $this->hasMany(CustomerNotification::class);
    }

    public function warrantyAssessments()
    {
        return $this->hasMany(WarrantyAssessment::class);
    }

    public function warrantyParentJobOrder()
    {
        return $this->belongsTo(JobOrder::class, 'warranty_parent_job_order_id');
    }

    public function warrantyServiceJobOrders()
    {
        return $this->hasMany(JobOrder::class, 'warranty_parent_job_order_id');
    }

    public function technician()
    {
        return $this->belongsTo(Technician::class);
    }

    public function serviceCategory()
    {
        return $this->belongsTo(ServiceCategory::class);
    }

    public function serviceItem()
    {
        return $this->belongsTo(ServiceItem::class);
    }

    public function getTimelineAttribute()
    {
        return $this->statusHistories()->orderBy('created_at', 'asc')->get();
    }

    // Calculate balance amount
    public function getBalanceAttribute()
    {
        $balance = (float) $this->total_cost - (float) $this->amount_paid;
        return $balance > 0 ? $balance : 0;
    }

    // Check if warranty is active
    public function isUnderWarranty()
    {
        if (!$this->picked_up_at) {
            return false;
        }
        $expiryDate = $this->picked_up_at->addDays($this->warranty_days ?? 30);
        return now()->lessThanOrEqualTo($expiryDate);
    }

    public function partsTotal(): float
    {
        return (float) $this->parts()->sum('total_cost');
    }

    public function repairHasStarted(): bool
    {
        if (in_array($this->current_status, ['IN_PROGRESS', 'READY_FOR_PICKUP', 'PICKED_UP'], true)) {
            return true;
        }

        return $this->statusHistories()->where('status', 'IN_PROGRESS')->exists();
    }
}

