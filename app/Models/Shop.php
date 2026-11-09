<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class Shop extends Model
{
    public const FREE_TRIAL_DURATION_HOURS = 168;

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
        'free_trial_eligible',
        'free_trial_started_at',
        'free_trial_ends_at',
        'free_trial_used_at',
        'is_active',
    ];

    protected $casts = [
        'trial_ends_at' => 'datetime',
        'free_trial_eligible' => 'boolean',
        'free_trial_started_at' => 'datetime',
        'free_trial_ends_at' => 'datetime',
        'free_trial_used_at' => 'datetime',
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
        return $this->hasActivePaidAccess();
    }

    public function hasActivePaidAccess(): bool
    {
        return $this->is_active && $this->trial_ends_at && $this->trial_ends_at->isFuture();
    }

    public function isInCloseOutMode(): bool
    {
        return $this->is_active && $this->trial_ends_at && $this->trial_ends_at->isPast();
    }

    public function canUsePaidCloseOut(): bool
    {
        return $this->isInCloseOutMode()
            && !$this->isFreeTrialActive()
            && !$this->isFreeTrialExpired();
    }

    public function hasOperationalAccess(): bool
    {
        return $this->is_active
            && ($this->hasActivePaidAccess() || $this->isFreeTrialActive());
    }

    public function canPerformBusinessWrites(): bool
    {
        return $this->is_active
            && ($this->hasOperationalAccess()
                || (!$this->isFreeTrialExpired() && $this->canUsePaidCloseOut()));
    }

    public function canStartNewWork(): bool
    {
        return $this->hasOperationalAccess();
    }

    public function scopeWithPublicOperationalAccess(Builder $query): Builder
    {
        $now = now();

        return $query
            ->where('is_active', true)
            ->where(function (Builder $query) use ($now) {
                $query
                    ->where(fn (Builder $paidQuery) => $paidQuery
                        ->whereNotNull('trial_ends_at')
                        ->where('trial_ends_at', '>', $now))
                    ->orWhere(fn (Builder $freeTrialQuery) => $freeTrialQuery
                        ->whereNotNull('free_trial_started_at')
                        ->whereNotNull('free_trial_ends_at')
                        ->where('free_trial_ends_at', '>', $now));
            });
    }

    public function accessState(): array
    {
        $hasActivePaidAccess = $this->hasActivePaidAccess();

        return [
            'access_until' => $this->trial_ends_at,
            'access_until_formatted' => $this->trial_ends_at?->format('M d, Y'),
            'has_active_access' => $hasActivePaidAccess,
            'is_close_out_mode' => $this->canUsePaidCloseOut(),
            'has_active_paid_access' => $hasActivePaidAccess,
            'has_operational_access' => $this->hasOperationalAccess(),
            'free_trial_eligible' => $this->isEligibleForFreeTrial(),
            'free_trial_used' => $this->hasUsedFreeTrial(),
            'free_trial_active' => !$hasActivePaidAccess && $this->isFreeTrialActive(),
            'free_trial_expired' => $this->isFreeTrialExpired(),
            'free_trial_started_at' => $this->free_trial_started_at,
            'free_trial_ends_at' => $this->free_trial_ends_at,
            'free_trial_used_at' => $this->free_trial_used_at,
            'paid_access_until' => $this->trial_ends_at,
        ];
    }

    public function isEligibleForFreeTrial(): bool
    {
        return $this->is_active
            && $this->free_trial_eligible
            && !$this->hasUsedFreeTrial()
            && !$this->hasActiveAccess();
    }

    public function hasUsedFreeTrial(): bool
    {
        return $this->free_trial_used_at !== null
            || $this->free_trial_started_at !== null
            || $this->free_trial_ends_at !== null;
    }

    public function isFreeTrialActive(): bool
    {
        return $this->is_active
            && $this->free_trial_started_at !== null
            && $this->free_trial_ends_at !== null
            && $this->free_trial_ends_at->isFuture();
    }

    public function isFreeTrialExpired(): bool
    {
        return $this->hasUsedFreeTrial()
            && ($this->free_trial_ends_at === null || $this->free_trial_ends_at->lte(now()));
    }

    public function freeTrialExpirationAt(): ?Carbon
    {
        return $this->free_trial_ends_at;
    }

    public function activateFreeTrial(): bool
    {
        if (!$this->isEligibleForFreeTrial()) {
            return false;
        }

        $startedAt = now();

        $this->forceFill([
            'free_trial_eligible' => false,
            'free_trial_started_at' => $startedAt,
            'free_trial_ends_at' => $startedAt->copy()->addHours(self::FREE_TRIAL_DURATION_HOURS),
            'free_trial_used_at' => $startedAt,
        ])->save();

        return true;
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
