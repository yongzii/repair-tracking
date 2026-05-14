<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SparePart extends Model
{
    protected $fillable = [
        'shop_id',
        'name',
        'part_number',
        'category',
        'unit',
        'stock_quantity',
        'reorder_level',
        'unit_cost',
        'is_active',
        'description',
    ];

    protected $casts = [
        'unit_cost' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function stockMovements()
    {
        return $this->hasMany(StockMovement::class);
    }

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function jobOrderParts()
    {
        return $this->hasMany(JobOrderPart::class);
    }
}
