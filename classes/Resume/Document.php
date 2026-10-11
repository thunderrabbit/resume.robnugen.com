<?php

declare(strict_types=1);

namespace Resume;

/**
 * One résumé, ready to render. Empty sections are already gone.
 */
final class Document
{
    /**
     * @param list<ContactRow>      $contacts in print order
     * @param list<RenderedSection> $sections
     */
    public function __construct(
        public readonly array $contacts,
        public readonly array $sections,
        public readonly Query $query,
    ) {
    }
}
