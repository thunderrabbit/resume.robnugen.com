<?php

declare(strict_types=1);

namespace Resume;

/**
 * One entry with its wording chosen and dates formatted. Strings are raw:
 * templates escape them.
 */
final class RenderedEntry
{
    /**
     * @param list<string> $bullets
     * @param list<string> $skills
     * @param list<array{url: string, kind: string|null}> $links
     * @param list<RenderedEntry> $children
     */
    public function __construct(
        public readonly string $slug,
        public readonly ?string $title,
        public readonly ?string $org_name,
        public readonly ?string $summary,
        public readonly ?string $url_label,
        public readonly array $bullets,
        public readonly ?string $location,
        public readonly string $dates,
        public readonly array $skills,
        public readonly array $links,
        public readonly array $children,
    ) {
    }
}
