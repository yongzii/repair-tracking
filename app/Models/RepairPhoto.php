<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RepairPhoto extends Model
{
    public const TYPE_BEFORE = 'BEFORE';
    public const TYPE_DURING = 'DURING';
    public const TYPE_AFTER = 'AFTER';
    public const TYPE_EVIDENCE = 'EVIDENCE';

    protected $fillable = [
        'shop_id',
        'job_order_id',
        'photo_type',
        'photo_path',
        'original_name',
        'mime_type',
        'file_size',
        'description',
        'visible_to_customer',
        'uploaded_by',
        'uploaded_by_role',
    ];

    protected $casts = [
        'visible_to_customer' => 'boolean',
    ];

    public function jobOrder()
    {
        return $this->belongsTo(JobOrder::class);
    }

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public static function types(): array
    {
        return [
            self::TYPE_BEFORE,
            self::TYPE_DURING,
            self::TYPE_AFTER,
            self::TYPE_EVIDENCE,
        ];
    }
}
