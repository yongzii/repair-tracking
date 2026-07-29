<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionRequest extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'shop_id',
        'requested_by_id',
        'reviewed_by_id',
        'plan_key',
        'plan_name',
        'amount',
        'duration_months',
        'payment_method_key',
        'payment_method_name',
        'reference_number',
        'proof_path',
        'status',
        'reviewed_at',
        'admin_notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'duration_months' => 'integer',
        'reviewed_at' => 'datetime',
    ];

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    public function reviewedBy()
    {
        return $this->belongsTo(User::class, 'reviewed_by_id');
    }

    public function proofUrl(): ?string
    {
        return $this->proof_path ? '/storage/' . ltrim(str_replace('\\', '/', $this->proof_path), '/') : null;
    }
}
