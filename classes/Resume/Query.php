<?php

declare(strict_types=1);

namespace Resume;

/**
 * What to render: which moment, which wording, which audience.
 *
 * Dates are 'Y-m-d' strings. ISO dates compare correctly as strings, which
 * keeps every comparison identical in PHP, MySQL and SQLite.
 */
final class Query
{
    /**
     * @param list<string>      $tags          requested tag slugs, empty = the default résumé
     * @param list<string>|null $section_slugs sections in render order, null = all, by default order
     */
    public function __construct(
        public readonly string $as_of,
        public readonly string $wording_as_of,
        public readonly array $tags = [],
        public readonly ?array $section_slugs = null,
    ) {
    }

    /** A real calendar date written as YYYY-MM-DD. */
    public static function isDate(mixed $value): bool
    {
        if (!is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
            return false;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
}
