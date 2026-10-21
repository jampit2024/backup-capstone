<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;

final class AcademicYear
{
    public const START_MONTH = 8;
    public const OUTSIDE_CURRENT_YEAR_MESSAGE = 'This schedule is not in the current academic year and cannot be downloaded.';

    public static function forDate(DateTimeInterface|string $date): string
    {
        $date = Carbon::parse($date);
        $startYear = $date->month >= self::START_MONTH
            ? $date->year
            : $date->year - 1;

        return $startYear.'-'.($startYear + 1);
    }

    public static function current(): string
    {
        return self::forDate(now());
    }

    public static function isCurrent(?string $academicYear): bool
    {
        return $academicYear !== null && $academicYear === self::current();
    }

    public static function currentCalendarYear(): int
    {
        return (int) now()->year;
    }

    public static function isFutureCalendarYear(DateTimeInterface|string $date): bool
    {
        return (int) Carbon::parse($date)->year > self::currentCalendarYear();
    }
}
