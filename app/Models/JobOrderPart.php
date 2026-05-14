<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JobOrderPart extends Model
{
    protected $fillable = [
        'shop_id',
        'job_order_id',
        'spare_part_id',
        'part_name',
        'quantity',
        'unit_cost',
        'total_cost',
        'notes',
    ];

    protected $casts = [
        'unit_cost' => 'decimal:2',
        'total_cost' => 'decimal:2',
    ];

    public function jobOrder()
    {
        return $this->belongsTo(JobOrder::class);
    }

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function sparePart()
    {
        return $this->belongsTo(SparePart::class);
    }
}
