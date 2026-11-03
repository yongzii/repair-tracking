<?php

namespace Tests\Unit;

use App\Support\ManilaDateTime;
use Carbon\Carbon;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class ManilaDateTimeTest extends TestCase
{
    public function test_it_formats_utc_instants_in_manila_time(): void
    {
        $instant = new DateTimeImmutable('2026-10-30T15:50:33+00:00');

        $this->assertSame('Oct 30, 2026 11:50 PM', ManilaDateTime::format($instant));
    }

    public function test_it_handles_midnight_and_date_boundary_conversion(): void
    {
        $instant = new DateTimeImmutable('2026-10-30T16:00:00+00:00');

        $this->assertSame('Oct 31, 2026 12:00 AM', ManilaDateTime::format($instant));
    }

    public function test_it_returns_null_for_null_input(): void
    {
        $this->assertNull(ManilaDateTime::toManila(null));
        $this->assertNull(ManilaDateTime::format(null));
    }

    public function test_it_does_not_modify_the_original_carbon_instance(): void
    {
        $instant = Carbon::parse('2026-10-30T15:50:33+00:00');
        $originalTimezone = $instant->getTimezone()->getName();
        $originalValue = $instant->toISOString();

        $manila = ManilaDateTime::toManila($instant);

        $this->assertSame($originalTimezone, $instant->getTimezone()->getName());
        $this->assertSame($originalValue, $instant->toISOString());
        $this->assertSame('Asia/Manila', $manila?->getTimezone()->getName());
        $this->assertNotSame($instant, $manila);
    }

    public function test_it_does_not_accept_date_only_strings_as_utc_instants(): void
    {
        $this->expectException(\TypeError::class);

        ManilaDateTime::format('2026-10-31');
    }
}
