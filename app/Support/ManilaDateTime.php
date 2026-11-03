<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

final class ManilaDateTime
{
    private const TIMEZONE = 'Asia/Manila';

    /**
     * Converts an actual instant; date-only values and Manila wall-clock values are not instants.
     */
    public static function toManila(?DateTimeInterface $instant): ?CarbonImmutable
    {
        if ($instant === null) {
            return null;
        }

        return CarbonImmutable::instance($instant)->setTimezone(self::TIMEZONE);
    }

    public static function format(
        ?DateTimeInterface $instant,
        string $format = 'M d, Y h:i A'
    ): ?string {
        return self::toManila($instant)?->format($format);
    }
}
