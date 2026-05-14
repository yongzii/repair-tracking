<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    protected $fillable = [
        'shop_id',
        'spare_part_id',
        'job_order_id',
        'job_order_part_id',
        'type',
        'quantity_change',
        'balance_after',
        'note',
        'created_by',
    ];

    protected $casts = [
        'quantity_change' => 'integer',
        'balance_after' => 'integer',
    ];

    public function sparePart()
    {
        return $this->belongsTo(SparePart::class);
    }

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function jobOrder()
    {
        return $this->belongsTo(JobOrder::class);
    }

    public function jobOrderPart()
    {
        return $this->belongsTo(JobOrderPart::class);
    }
}
