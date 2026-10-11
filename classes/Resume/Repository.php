<?php

declare(strict_types=1);

namespace Resume;

/**
 * Reads the résumé tables into plain rows. Choosing among them is
 * Builder's job, so every query here is a simple portable SELECT that runs
 * unchanged on MySQL and on the Unit suite's in-memory SQLite.
 */
final class Repository
{
    /** MySQL: table does not exist. */
    private const SQLSTATE_NO_TABLE = '42S02';

    public function __construct(private \PDO $di_pdo)
    {
    }

    public function load(): ResumeData
    {
        $entry_tags = $this->namesBy(
            'SELECT et.entry_id AS owner_id, t.tag_slug AS name
               FROM entry_tags et JOIN tags t ON t.tag_id = et.tag_id
              ORDER BY t.tag_slug'
        );
        $text_tags = $this->namesBy(
            'SELECT tt.entry_text_id AS owner_id, t.tag_slug AS name
               FROM entry_text_tags tt JOIN tags t ON t.tag_id = tt.tag_id
              ORDER BY t.tag_slug'
        );
        $contact_tags = $this->namesBy(
            'SELECT ct.contact_point_id AS owner_id, t.tag_slug AS name
               FROM contact_point_tags ct JOIN tags t ON t.tag_id = ct.tag_id
              ORDER BY t.tag_slug'
        );
        $skills = $this->namesBy(
            'SELECT es.entry_id AS owner_id, s.skill_name AS name
               FROM entry_skills es JOIN skills s ON s.skill_id = es.skill_id
              ORDER BY es.sort_order, s.skill_name'
        );

        /** @var array<int, list<array{url: string, kind: string|null}>> $links */
        $links = [];
        foreach ($this->rows('SELECT entry_id, url, link_kind FROM entry_links ORDER BY entry_link_id') as $row) {
            $links[self::int($row, 'entry_id')][] = [
                'url' => self::str($row, 'url'),
                'kind' => self::optStr($row, 'link_kind'),
            ];
        }

        $sections = [];
        foreach ($this->rows('SELECT section_slug, heading, default_order FROM sections') as $row) {
            $sections[] = new SectionRow(
                self::str($row, 'section_slug'),
                self::str($row, 'heading'),
                self::int($row, 'default_order'),
            );
        }

        $entries = [];
        $entry_rows = $this->rows(
            'SELECT e.entry_id, e.entry_slug, s.section_slug, e.parent_entry_id,
                    e.started_on, e.started_precision, e.ended_on, e.ended_precision,
                    COALESCE(e.location_text, o.city) AS location,
                    e.include_by_default, e.sort_hint
               FROM entries e
               JOIN sections s ON s.section_id = e.section_id
               LEFT JOIN organizations o ON o.organization_id = e.organization_id
              ORDER BY e.entry_id'
        );
        foreach ($entry_rows as $row) {
            $entry_id = self::int($row, 'entry_id');
            $entries[] = new EntryRow(
                entry_id: $entry_id,
                slug: self::str($row, 'entry_slug'),
                section_slug: self::str($row, 'section_slug'),
                parent_entry_id: self::optInt($row, 'parent_entry_id'),
                started_on: self::optStr($row, 'started_on'),
                started_precision: self::optStr($row, 'started_precision'),
                ended_on: self::optStr($row, 'ended_on'),
                ended_precision: self::optStr($row, 'ended_precision'),
                location: self::optStr($row, 'location'),
                include_by_default: self::int($row, 'include_by_default') === 1,
                sort_hint: self::int($row, 'sort_hint'),
                tags: $entry_tags[$entry_id] ?? [],
                skills: $skills[$entry_id] ?? [],
                links: $links[$entry_id] ?? [],
            );
        }

        $texts = [];
        $text_rows = $this->rows(
            'SELECT entry_text_id, entry_id, field, body, written_on, retired_on, priority, sort_order
               FROM entry_texts ORDER BY entry_text_id'
        );
        foreach ($text_rows as $row) {
            $text_id = self::int($row, 'entry_text_id');
            $texts[] = new TextRow(
                entry_text_id: $text_id,
                entry_id: self::int($row, 'entry_id'),
                field: self::str($row, 'field'),
                body: self::str($row, 'body'),
                written_on: self::str($row, 'written_on'),
                retired_on: self::optStr($row, 'retired_on'),
                priority: self::int($row, 'priority'),
                sort_order: self::int($row, 'sort_order'),
                tags: $text_tags[$text_id] ?? [],
            );
        }

        $contacts = [];
        $contact_rows = $this->rows(
            'SELECT contact_point_id, contact_kind, contact_value, valid_from, valid_until, priority, is_private
               FROM contact_points ORDER BY contact_point_id'
        );
        foreach ($contact_rows as $row) {
            $contacts[] = new ContactRow(
                kind: self::str($row, 'contact_kind'),
                value: self::str($row, 'contact_value'),
                valid_from: self::optStr($row, 'valid_from'),
                valid_until: self::optStr($row, 'valid_until'),
                priority: self::int($row, 'priority'),
                is_private: self::int($row, 'is_private') === 1,
                tags: $contact_tags[self::int($row, 'contact_point_id')] ?? [],
            );
        }

        $known_tags = array_map(
            static fn (array $row): string => self::str($row, 'tag_slug'),
            $this->rows('SELECT tag_slug FROM tags ORDER BY tag_slug')
        );

        return new ResumeData($sections, $entries, $texts, $contacts, $known_tags);
    }

    /**
     * The public profile's Query, or null when nothing is published yet:
     * no tables (migration not applied), no import, or no public profile.
     */
    public function publicQuery(string $today): ?Query
    {
        try {
            $rows = $this->rows(
                'SELECT profile_id, as_of, wording_as_of FROM profiles WHERE is_public = 1 ORDER BY profile_id'
            );
        } catch (\PDOException $e) {
            if ($this->isMissingTable($e)) {
                return null;
            }
            throw $e;
        }
        return $rows === [] ? null : $this->queryFor($rows[0], $today);
    }

    public function profileQuery(string $slug, string $today): ?Query
    {
        $rows = $this->rows(
            'SELECT profile_id, as_of, wording_as_of FROM profiles WHERE profile_slug = ?',
            [$slug]
        );
        return $rows === [] ? null : $this->queryFor($rows[0], $today);
    }

    /** @return list<array{slug: string, title: string}> */
    public function profiles(): array
    {
        return array_map(
            static fn (array $row): array => [
                'slug' => self::str($row, 'profile_slug'),
                'title' => self::str($row, 'profile_title'),
            ],
            $this->rows('SELECT profile_slug, profile_title FROM profiles ORDER BY profile_id')
        );
    }

    /** @param array<mixed> $profile */
    private function queryFor(array $profile, string $today): Query
    {
        $profile_id = self::int($profile, 'profile_id');
        $tags = array_map(
            static fn (array $row): string => self::str($row, 'tag_slug'),
            $this->rows(
                'SELECT t.tag_slug FROM profile_tags pt JOIN tags t ON t.tag_id = pt.tag_id
                  WHERE pt.profile_id = ? ORDER BY t.tag_slug',
                [$profile_id]
            )
        );
        $sections = array_map(
            static fn (array $row): string => self::str($row, 'section_slug'),
            $this->rows(
                'SELECT s.section_slug FROM profile_sections ps JOIN sections s ON s.section_id = ps.section_id
                  WHERE ps.profile_id = ? ORDER BY ps.section_order',
                [$profile_id]
            )
        );
        return new Query(
            as_of: self::optStr($profile, 'as_of') ?? $today,
            wording_as_of: self::optStr($profile, 'wording_as_of') ?? $today,
            tags: $tags,
            section_slugs: $sections === [] ? null : $sections,
        );
    }

    /**
     * Groups "owner_id, name" rows into owner_id => names, keeping row order.
     *
     * @return array<int, list<string>>
     */
    private function namesBy(string $sql): array
    {
        $names = [];
        foreach ($this->rows($sql) as $row) {
            $names[self::int($row, 'owner_id')][] = self::str($row, 'name');
        }
        return $names;
    }

    /**
     * @param list<string|int> $params
     * @return list<array<mixed>>
     */
    private function rows(string $sql, array $params = []): array
    {
        $stmt = $this->di_pdo->prepare($sql);
        $stmt->execute($params);
        $rows = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /** @param array<mixed> $row */
    private static function str(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        return is_string($value) ? $value : (is_int($value) ? (string) $value : '');
    }

    /** @param array<mixed> $row */
    private static function optStr(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;
        return is_string($value) ? $value : null;
    }

    /** @param array<mixed> $row */
    private static function int(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        return is_int($value) ? $value : (is_string($value) && is_numeric($value) ? (int) $value : 0);
    }

    /** @param array<mixed> $row */
    private static function optInt(array $row, string $key): ?int
    {
        return ($row[$key] ?? null) === null ? null : self::int($row, $key);
    }

    private function isMissingTable(\PDOException $e): bool
    {
        // MySQL reports SQLSTATE 42S02. SQLite says "no such table" with HY000.
        return $e->getCode() === self::SQLSTATE_NO_TABLE
            || str_contains($e->getMessage(), 'no such table');
    }
}
