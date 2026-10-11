<?php

declare(strict_types=1);

namespace Resume;

/**
 * What to render. Task 5 adds the properties and fromRequest().
 */
final class Query
{
    /** A real calendar date written as YYYY-MM-DD. */
    public static function isDate(mixed $value): bool
    {
        if (!is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
            return false;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
}
