<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Technician extends Model
{
    protected $fillable = [
        'shop_id',
        'name',
        'email',
        'phone',
        'specialties',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'specialties' => 'array',
    ];

    public function jobOrders()
    {
        return $this->hasMany(JobOrder::class);
    }

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function user()
    {
        return $this->hasOne(User::class);
    }

    // Get count of jobs currently assigned
    public function currentJobsCount()
    {
        return $this->jobOrders()->whereNotIn('current_status', ['PICKED_UP', 'RTO_RELEASED'])->count();
    }

    // Get count of completed jobs
    public function completedJobsCount()
    {
        return $this->jobOrders()->whereIn('current_status', ['PICKED_UP', 'RTO_RELEASED'])->count();
    }

    // Get average rating
    public function averageRating()
    {
        $ratings = $this->jobOrders()->whereNotNull('technician_rating')->pluck('technician_rating');
        return $ratings->count() > 0 ? round($ratings->avg(), 1) : 0;
    }
}
