<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Shop extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'email',
        'phone',
        'address',
        'logo_path',
        'primary_color',
        'default_warranty_days',
        'subscription_plan',
        'trial_ends_at',
        'is_active',
    ];

    protected $casts = [
        'trial_ends_at' => 'datetime',
        'is_active' => 'boolean',
        'default_warranty_days' => 'integer',
    ];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function technicians()
    {
        return $this->hasMany(Technician::class);
    }

    public function jobOrders()
    {
        return $this->hasMany(JobOrder::class);
    }

    public function spareParts()
    {
        return $this->hasMany(SparePart::class);
    }

    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class);
    }

    public function statusHistories()
    {
        return $this->hasMany(StatusHistory::class);
    }

    public function jobOrderParts()
    {
        return $this->hasMany(JobOrderPart::class);
    }

    public function jobOrderPayments()
    {
        return $this->hasMany(JobOrderPayment::class);
    }

    public function stockMovements()
    {
        return $this->hasMany(StockMovement::class);
    }

    public function appointments()
    {
        return $this->hasMany(Appointment::class);
    }
}
