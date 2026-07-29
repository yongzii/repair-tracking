<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Appointment extends Model
{
    protected $fillable = [
        'shop_id',
        'confirmation_code',
        'customer_name',
        'customer_email',
        'customer_phone',
        'booking_type',
        'device_type',
        'service_category_id',
        'service_item_id',
        'brand',
        'model',
        'concern',
        'photo_path',
        'photo_original_name',
        'preferred_at',
        'scheduled_at',
        'status',
        'staff_note',
        'customer_response',
        'recommended_action',
        'converted_job_order_id',
        'responded_at',
        'responded_by',
    ];

    protected $casts = [
        'preferred_at' => 'datetime',
        'scheduled_at' => 'datetime',
        'responded_at' => 'datetime',
    ];

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function convertedJobOrder()
    {
        return $this->belongsTo(JobOrder::class, 'converted_job_order_id');
    }

    public function serviceCategory()
    {
        return $this->belongsTo(ServiceCategory::class);
    }

    public function serviceItem()
    {
        return $this->belongsTo(ServiceItem::class);
    }
}
