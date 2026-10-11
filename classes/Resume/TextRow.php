<?php

declare(strict_types=1);

namespace Resume;

final class TextRow
{
    /** @param list<string> $tags */
    public function __construct(
        public readonly int $entry_text_id,
        public readonly int $entry_id,
        public readonly string $field,
        public readonly string $body,
        public readonly string $written_on,
        public readonly ?string $retired_on,
        public readonly int $priority,
        public readonly int $sort_order,
        public readonly array $tags,
    ) {
    }
}
