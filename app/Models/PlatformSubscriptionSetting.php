<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformSubscriptionSetting extends Model
{
    protected $fillable = [
        'plans',
        'payment_methods',
        'instructions',
    ];

    protected $casts = [
        'plans' => 'array',
        'payment_methods' => 'array',
    ];

    public static function defaults(): array
    {
        return [
            'plans' => [
                ['key' => 'monthly', 'name' => 'Monthly', 'amount' => 1500, 'duration_months' => 1],
                ['key' => 'quarterly', 'name' => 'Quarterly', 'amount' => 4000, 'duration_months' => 3],
                ['key' => 'yearly', 'name' => 'Yearly', 'amount' => 15000, 'duration_months' => 12],
            ],
            'payment_methods' => [
                ['key' => 'gcash', 'name' => 'GCash', 'account_name' => 'Platform Admin', 'account_number' => 'Set this in Super Admin', 'instructions' => 'Send payment, then upload proof.'],
                ['key' => 'bank', 'name' => 'Bank Transfer', 'account_name' => 'Platform Admin', 'account_number' => 'Set this in Super Admin', 'instructions' => 'Transfer payment, then upload proof.'],
            ],
            'instructions' => 'Choose a plan, pay using one of the available methods, then upload proof of payment for verification.',
        ];
    }

    public static function current(): array
    {
        $settings = self::query()->first();
        $defaults = self::defaults();

        if (!$settings) {
            return $defaults;
        }

        return [
            'plans' => $settings->plans ?: $defaults['plans'],
            'payment_methods' => $settings->payment_methods ?: $defaults['payment_methods'],
            'instructions' => $settings->instructions ?: $defaults['instructions'],
        ];
    }
}
