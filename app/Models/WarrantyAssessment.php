<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WarrantyAssessment extends Model
{
    public const RESULT_NEEDS_DIAGNOSIS = 'NEEDS_DIAGNOSIS';
    public const RESULT_COVERED = 'COVERED';
    public const RESULT_NOT_COVERED = 'NOT_COVERED';

    protected $fillable = [
        'shop_id',
        'job_order_id',
        'warranty_job_order_id',
        'reported_issue',
        'brought_at',
        'checked_by',
        'result',
        'assessment_notes',
        'created_by',
    ];

    protected $casts = [
        'brought_at' => 'datetime',
    ];

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function jobOrder()
    {
        return $this->belongsTo(JobOrder::class);
    }

    public function warrantyJobOrder()
    {
        return $this->belongsTo(JobOrder::class, 'warranty_job_order_id');
    }

    public static function results(): array
    {
        return [
            self::RESULT_NEEDS_DIAGNOSIS,
            self::RESULT_COVERED,
            self::RESULT_NOT_COVERED,
        ];
    }
}
