<?php

declare(strict_types=1);

namespace Resume;

/**
 * Every résumé row, unfiltered. Builder decides what to show.
 */
final class ResumeData
{
    /**
     * @param list<SectionRow> $sections
     * @param list<EntryRow>   $entries
     * @param list<TextRow>    $texts
     * @param list<ContactRow> $contacts
     * @param list<string>     $known_tags every tag slug, sorted
     */
    public function __construct(
        public readonly array $sections,
        public readonly array $entries,
        public readonly array $texts,
        public readonly array $contacts,
        public readonly array $known_tags,
    ) {
    }
}
