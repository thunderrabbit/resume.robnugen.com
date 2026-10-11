<?php

declare(strict_types=1);

namespace Resume;

final class RenderedSection
{
    /** @param list<RenderedEntry> $entries */
    public function __construct(
        public readonly string $slug,
        public readonly string $heading,
        public readonly array $entries,
    ) {
    }
}
