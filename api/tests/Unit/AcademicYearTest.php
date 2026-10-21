<?php

namespace Tests\Unit;

use App\Support\AcademicYear;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AcademicYearTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_academic_year_uses_the_august_boundary_and_updates_with_the_calendar(): void
    {
        Carbon::setTestNow('2026-07-31 23:59:59');
        $this->assertSame('2025-2026', AcademicYear::current());
        $this->assertTrue(AcademicYear::isCurrent('2025-2026'));

        Carbon::setTestNow('2026-08-01 00:00:00');
        $this->assertSame('2026-2027', AcademicYear::current());

        Carbon::setTestNow('2028-08-01 00:00:00');
        $this->assertSame('2028-2029', AcademicYear::current());
        $this->assertTrue(AcademicYear::isCurrent('2028-2029'));
        $this->assertFalse(AcademicYear::isCurrent('2027-2028'));
    }

    public function test_calendar_year_is_dynamic_and_future_years_are_detected(): void
    {
        Carbon::setTestNow('2026-10-03 12:00:00');
        $this->assertSame(2026, AcademicYear::currentCalendarYear());
        $this->assertFalse(AcademicYear::isFutureCalendarYear('2026-05-01'));
        $this->assertFalse(AcademicYear::isFutureCalendarYear('2026-12-31'));
        $this->assertTrue(AcademicYear::isFutureCalendarYear('2027-01-01'));
        $this->assertTrue(AcademicYear::isFutureCalendarYear('2028-09-10'));

        Carbon::setTestNow('2027-01-01 00:00:00');
        $this->assertSame(2027, AcademicYear::currentCalendarYear());
        $this->assertFalse(AcademicYear::isFutureCalendarYear('2027-01-01'));
        $this->assertTrue(AcademicYear::isFutureCalendarYear('2028-01-01'));
    }
}
