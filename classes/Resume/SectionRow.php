<?php

declare(strict_types=1);

namespace Resume;

final class SectionRow
{
    public function __construct(
        public readonly string $slug,
        public readonly string $heading,
        public readonly int $default_order,
    ) {
    }
}
