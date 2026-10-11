<?php

declare(strict_types=1);

namespace Resume;

/**
 * Turns raw résumé rows into one Document for one Query.
 *
 * Every choice the spec describes lives here, in PHP, so it is identical on
 * MySQL and in the Unit suite: which entries appear, which wording each gets,
 * and which contact details show.
 */
final class Builder
{
    /** Contact kinds that show at most once. 'url' may repeat. */
    private const SINGLE_CONTACT_KINDS = ['name', 'location', 'phone', 'whatsapp', 'email', 'relocation'];

    /** The order contact details print in. */
    private const CONTACT_ORDER = ['name', 'location', 'relocation', 'email', 'phone', 'whatsapp', 'url'];

    public function build(ResumeData $data, Query $query): Document
    {
        /** @var array<int, list<TextRow>> $texts_by_entry */
        $texts_by_entry = [];
        foreach ($data->texts as $text) {
            $texts_by_entry[$text->entry_id][] = $text;
        }

        /** @var array<int, list<EntryRow>> $children */
        $children = [];
        /** @var array<string, list<EntryRow>> $top */
        $top = [];
        foreach ($data->entries as $entry) {
            if ($entry->started_on !== null && $entry->started_on > $query->as_of) {
                continue;
            }
            if ($entry->parent_entry_id === null) {
                if ($this->matches($entry, $query, false)) {
                    $top[$entry->section_slug][] = $entry;
                }
            } elseif ($this->matches($entry, $query, true)) {
                // A child whose parent is left out is never reached, so it goes too.
                $children[$entry->parent_entry_id][] = $entry;
            }
        }

        $sections = [];
        foreach ($this->orderedSections($data->sections, $query) as $section) {
            $entries = $top[$section->slug] ?? [];
            if ($entries === []) {
                continue;
            }
            $rendered = [];
            foreach ($this->sorted($entries) as $entry) {
                $rendered[] = $this->render($entry, $children, $texts_by_entry, $query);
            }
            $sections[] = new RenderedSection($section->slug, $section->heading, $rendered);
        }

        return new Document($this->contacts($data->contacts, $query), $sections, $query);
    }

    /**
     * No tags requested: the default résumé. Tags requested: entries carrying
     * at least one of them. An untagged child goes wherever its parent goes.
     */
    private function matches(EntryRow $entry, Query $query, bool $is_child): bool
    {
        if ($query->tags === []) {
            return $entry->include_by_default;
        }
        if ($is_child && $entry->tags === []) {
            return true;
        }
        return array_intersect($entry->tags, $query->tags) !== [];
    }

    /**
     * @param array<int, list<EntryRow>> $children
     * @param array<int, list<TextRow>>  $texts_by_entry
     */
    private function render(EntryRow $entry, array $children, array $texts_by_entry, Query $query): RenderedEntry
    {
        $texts = $texts_by_entry[$entry->entry_id] ?? [];
        $ongoing = $entry->started_on !== null
            && ($entry->ended_on === null || $entry->ended_on > $query->as_of);

        $rendered_children = [];
        foreach ($this->sorted($children[$entry->entry_id] ?? []) as $child) {
            $rendered_children[] = $this->render($child, $children, $texts_by_entry, $query);
        }

        return new RenderedEntry(
            slug: $entry->slug,
            title: $this->pickOne($texts, 'title', $query),
            org_name: $this->pickOne($texts, 'org_name', $query),
            summary: $this->pickOne($texts, 'summary', $query),
            url_label: $this->pickOne($texts, 'url_label', $query),
            bullets: $this->pickBullets($texts, $query),
            location: $entry->location,
            dates: DateText::range(
                $entry->started_on,
                $entry->started_precision,
                $entry->ended_on,
                $entry->ended_precision,
                $ongoing
            ),
            skills: $entry->skills,
            links: $entry->links,
            children: $rendered_children,
        );
    }

    /**
     * The best live wording for a one-per-entry field. Prefer the most
     * requested tags matched, then untagged, then priority, then newest.
     *
     * @param list<TextRow> $texts
     */
    private function pickOne(array $texts, string $field, Query $query): ?string
    {
        $candidates = array_values(array_filter($texts, static fn (TextRow $t): bool => $t->field === $field));
        if ($candidates === []) {
            return null;
        }
        $best = null;
        foreach ($candidates as $text) {
            if (!self::isLive($text, $query->wording_as_of)) {
                continue;
            }
            if ($best === null || ($this->rank($text, $query) <=> $this->rank($best, $query)) > 0) {
                $best = $text;
            }
        }
        return ($best ?? $this->nearest($candidates, $query->wording_as_of))->body;
    }

    /** @return array{int, int, int, string, int} bigger is better */
    private function rank(TextRow $text, Query $query): array
    {
        $matches = count(array_intersect($text->tags, $query->tags));
        $tier = $matches > 0 ? 2 : ($text->tags === [] ? 1 : 0);
        return [$tier, $matches, $text->priority, $text->written_on, $text->entry_text_id];
    }

    /**
     * Nothing was in use on $date. Take the last wording written before it,
     * else the first written after it, so the field never goes blank.
     *
     * @param non-empty-list<TextRow> $candidates
     */
    private function nearest(array $candidates, string $date): TextRow
    {
        $before = null;
        $first = $candidates[0];
        foreach ($candidates as $text) {
            $key = [$text->written_on, $text->entry_text_id];
            if (
                $text->written_on <= $date
                && ($before === null || ($key <=> [$before->written_on, $before->entry_text_id]) > 0)
            ) {
                $before = $text;
            }
            if (($key <=> [$first->written_on, $first->entry_text_id]) < 0) {
                $first = $text;
            }
        }
        return $before ?? $first;
    }

    /**
     * Bullets are many, not one: every live bullet that is untagged or
     * matches a requested tag, in sort order. They never fall back.
     *
     * @param list<TextRow> $texts
     * @return list<string>
     */
    private function pickBullets(array $texts, Query $query): array
    {
        $bullets = array_values(array_filter(
            $texts,
            static fn (TextRow $t): bool => $t->field === 'bullet'
                && self::isLive($t, $query->wording_as_of)
                && ($t->tags === [] || array_intersect($t->tags, $query->tags) !== [])
        ));
        usort(
            $bullets,
            static fn (TextRow $a, TextRow $b): int
                => [$a->sort_order, $a->entry_text_id] <=> [$b->sort_order, $b->entry_text_id]
        );
        return array_map(static fn (TextRow $t): string => $t->body, $bullets);
    }

    private static function isLive(TextRow $text, string $date): bool
    {
        return $text->written_on <= $date && ($text->retired_on === null || $text->retired_on > $date);
    }

    /**
     * @param list<ContactRow> $contacts
     * @return list<ContactRow>
     */
    private function contacts(array $contacts, Query $query): array
    {
        $eligible = array_values(array_filter(
            $contacts,
            static fn (ContactRow $c): bool => !$c->is_private
                && ($c->valid_from === null || $c->valid_from <= $query->as_of)
                && ($c->valid_until === null || $c->valid_until > $query->as_of)
                && ($c->tags === [] || array_intersect($c->tags, $query->tags) !== [])
        ));
        usort($eligible, static function (ContactRow $a, ContactRow $b) use ($query): int {
            $by_kind = self::kindOrder($a->kind) <=> self::kindOrder($b->kind);
            if ($by_kind !== 0) {
                return $by_kind;
            }
            return [count(array_intersect($b->tags, $query->tags)), $b->priority]
                <=> [count(array_intersect($a->tags, $query->tags)), $a->priority];
        });

        $seen = [];
        $shown = [];
        foreach ($eligible as $contact) {
            if (in_array($contact->kind, self::SINGLE_CONTACT_KINDS, true)) {
                if (isset($seen[$contact->kind])) {
                    continue;
                }
                $seen[$contact->kind] = true;
            }
            $shown[] = $contact;
        }
        return $shown;
    }

    private static function kindOrder(string $kind): int
    {
        $at = array_search($kind, self::CONTACT_ORDER, true);
        return $at === false ? count(self::CONTACT_ORDER) : $at;
    }

    /**
     * @param list<SectionRow> $sections
     * @return list<SectionRow>
     */
    private function orderedSections(array $sections, Query $query): array
    {
        if ($query->section_slugs === null) {
            usort(
                $sections,
                static fn (SectionRow $a, SectionRow $b): int
                    => [$a->default_order, $a->slug] <=> [$b->default_order, $b->slug]
            );
            return $sections;
        }
        $by_slug = [];
        foreach ($sections as $section) {
            $by_slug[$section->slug] = $section;
        }
        $ordered = [];
        foreach ($query->section_slugs as $slug) {
            if (isset($by_slug[$slug])) {
                $ordered[] = $by_slug[$slug];
            }
        }
        return $ordered;
    }

    /**
     * sort_hint first, then newest first. Undated entries (a summary, a
     * language) count as newest, so they lead.
     *
     * @param list<EntryRow> $entries
     * @return list<EntryRow>
     */
    private function sorted(array $entries): array
    {
        usort(
            $entries,
            static fn (EntryRow $a, EntryRow $b): int
                => [$a->sort_hint, $b->started_on ?? '9999-12-31', $a->slug]
                <=> [$b->sort_hint, $a->started_on ?? '9999-12-31', $b->slug]
        );
        return $entries;
    }
}
