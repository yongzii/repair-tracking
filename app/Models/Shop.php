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
        'description',
        'business_hours',
        'logo_path',
        'default_warranty_days',
        'claim_terms',
        'warranty_terms',
        'notification_settings',
        'subscription_plan',
        'trial_ends_at',
        'is_active',
    ];

    protected $casts = [
        'trial_ends_at' => 'datetime',
        'is_active' => 'boolean',
        'default_warranty_days' => 'integer',
        'business_hours' => 'array',
        'notification_settings' => 'array',
    ];

    public function logoUrl(): ?string
    {
        return $this->logo_path ? '/storage/' . ltrim(str_replace('\\', '/', $this->logo_path), '/') : null;
    }

    public function hasActiveAccess(): bool
    {
        return $this->is_active && $this->trial_ends_at && $this->trial_ends_at->isFuture();
    }

    public function isInCloseOutMode(): bool
    {
        return $this->is_active && $this->trial_ends_at && $this->trial_ends_at->isPast();
    }

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

    public function jobOrderPartOrders()
    {
        return $this->hasMany(JobOrderPartOrder::class);
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

    public function serviceCategories()
    {
        return $this->hasMany(ServiceCategory::class);
    }

    public function serviceItems()
    {
        return $this->hasMany(ServiceItem::class);
    }

    public function subscriptionPayments()
    {
        return $this->hasMany(PlatformSubscriptionPayment::class);
    }

    public function subscriptionRequests()
    {
        return $this->hasMany(SubscriptionRequest::class);
    }
}
