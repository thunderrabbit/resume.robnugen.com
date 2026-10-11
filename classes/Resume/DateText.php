<?php

declare(strict_types=1);

namespace Resume;

/**
 * Dates as a résumé prints them, months spelled out: "14 Jun 2007",
 * "Jun 2007", "Fall 2002", "2007", and ranges such as "Aug 1999 – Jun 2002".
 */
final class DateText
{
    private const SEASONS = [
        1 => 'Winter', 2 => 'Winter', 3 => 'Spring', 4 => 'Spring', 5 => 'Spring', 6 => 'Summer',
        7 => 'Summer', 8 => 'Summer', 9 => 'Fall', 10 => 'Fall', 11 => 'Fall', 12 => 'Winter',
    ];

    public static function one(?string $date, ?string $precision): string
    {
        if ($date === null || !Query::isDate($date)) {
            return '';
        }
        $when = new \DateTimeImmutable($date);
        return match ($precision) {
            'day' => $when->format('j M Y'),
            'season' => self::SEASONS[(int) $when->format('n')] . ' ' . $when->format('Y'),
            'year' => $when->format('Y'),
            default => $when->format('M Y'),
        };
    }

    /**
     * $ongoing wins over any end date. When start and end print the same,
     * as for a one-day talk, the date prints once.
     */
    public static function range(
        ?string $start,
        ?string $start_precision,
        ?string $end,
        ?string $end_precision,
        bool $ongoing,
    ): string {
        $from = self::one($start, $start_precision);
        if ($from === '') {
            return '';
        }
        if ($ongoing) {
            return $from . ' – Present';
        }
        $to = self::one($end, $end_precision);
        return ($to === '' || $to === $from) ? $from : $from . ' – ' . $to;
    }
}
