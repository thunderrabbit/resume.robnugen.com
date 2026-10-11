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

    /**
     * Builds a Query from GET parameters. Anything unparseable falls back to
     * $today, and unknown tags are dropped, so a hand-edited URL never errors.
     *
     * @param array<mixed> $get
     * @param list<string> $known_tags
     */
    public static function fromRequest(array $get, string $today, array $known_tags): self
    {
        $tags = [];
        $raw_tags = $get['tags'] ?? [];
        if (is_array($raw_tags)) {
            foreach ($raw_tags as $tag) {
                if (is_string($tag) && in_array($tag, $known_tags, true) && !in_array($tag, $tags, true)) {
                    $tags[] = $tag;
                }
            }
        }
        return new self(
            as_of: self::dateOr($get['as_of'] ?? null, $today),
            wording_as_of: self::dateOr($get['wording_as_of'] ?? null, $today),
            tags: $tags,
        );
    }

    private static function dateOr(mixed $value, string $fallback): string
    {
        return is_string($value) && self::isDate($value) ? $value : $fallback;
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
