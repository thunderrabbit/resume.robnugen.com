<?php

declare(strict_types=1);

namespace Resume;

final class ContactRow
{
    /** @param list<string> $tags */
    public function __construct(
        public readonly string $kind,
        public readonly string $value,
        public readonly ?string $valid_from,
        public readonly ?string $valid_until,
        public readonly int $priority,
        public readonly bool $is_private,
        public readonly array $tags,
    ) {
    }
}
