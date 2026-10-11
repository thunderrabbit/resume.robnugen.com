<?php

declare(strict_types=1);

namespace Resume;

final class EntryRow
{
    /**
     * @param list<string> $tags
     * @param list<string> $skills skill names, in the entry's own order
     * @param list<array{url: string, kind: string|null}> $links
     */
    public function __construct(
        public readonly int $entry_id,
        public readonly string $slug,
        public readonly string $section_slug,
        public readonly ?int $parent_entry_id,
        public readonly ?string $started_on,
        public readonly ?string $started_precision,
        public readonly ?string $ended_on,
        public readonly ?string $ended_precision,
        public readonly ?string $location,
        public readonly bool $include_by_default,
        public readonly int $sort_hint,
        public readonly array $tags,
        public readonly array $skills,
        public readonly array $links,
    ) {
    }
}
