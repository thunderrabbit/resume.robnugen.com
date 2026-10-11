<?php

declare(strict_types=1);

namespace Resume;

/**
 * Replaces every résumé row with one JSON document.
 *
 * The document is the source of truth and lives in the private data repo.
 * The tables are a copy the site can query. All or nothing: any problem
 * rolls the transaction back and the résumé already live stays live.
 * Prepared statements carry every value, so text may hold any character,
 * semicolons included.
 */
final class Importer
{
    private const PRECISIONS = ['day', 'month', 'season', 'year'];
    private const FIELDS = ['title', 'org_name', 'summary', 'bullet', 'url_label'];
    private const TAG_KINDS = ['audience', 'market'];
    private const CONTACT_KINDS = ['name', 'location', 'phone', 'whatsapp', 'email', 'url', 'relocation'];

    /** @var array<string, int> */
    private array $tag_ids = [];
    /** @var array<string, int> */
    private array $source_ids = [];
    /** @var array<string, int> */
    private array $section_ids = [];
    /** @var array<string, int> */
    private array $org_ids = [];
    /** @var array<string, int> */
    private array $skill_ids = [];
    /** @var array<string, int> */
    private array $entry_ids = [];
    /** @var array<string, int> */
    private array $profile_ids = [];
    /** @var array<string, int> */
    private array $counts = [];

    public function __construct(private \PDO $di_pdo)
    {
    }

    /**
     * @return array<string, int> rows inserted, by table
     */
    public function import(string $json): array
    {
        try {
            $doc = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ImportException('Not valid JSON: ' . $e->getMessage());
        }
        if (!is_array($doc)) {
            throw new ImportException('The document must be a JSON object');
        }

        $this->tag_ids = $this->source_ids = $this->section_ids = $this->org_ids = [];
        $this->skill_ids = $this->entry_ids = $this->profile_ids = $this->counts = [];

        $this->di_pdo->beginTransaction();
        try {
            $this->clear();
            $this->importTags($doc);
            $this->importSources($doc);
            $this->importSections($doc);
            $this->importOrganizations($doc);
            $this->importSkills($doc);
            $this->importEntries($doc);
            $this->importContacts($doc);
            $this->importProfiles($doc);
            $this->di_pdo->commit();
        } catch (\Throwable $e) {
            $this->di_pdo->rollBack();
            throw $e;
        }
        return $this->counts;
    }

    private function clear(): void
    {
        // entries points at itself, so cut those links before deleting.
        $this->di_pdo->exec('UPDATE entries SET parent_entry_id = NULL');
        foreach (Tables::DELETE_ORDER as $table) {
            $this->di_pdo->exec("DELETE FROM $table");
        }
    }

    /** @param array<mixed> $doc */
    private function importTags(array $doc): void
    {
        foreach (self::objects($doc, 'tags', 'tags') as $i => $tag) {
            $where = "tags[$i]";
            $slug = self::str($tag, 'slug', $where, 40);
            self::unique($this->tag_ids, $slug, $where);
            $this->tag_ids[$slug] = $this->insert('tags', [
                'tag_slug' => $slug,
                'tag_kind' => self::oneOf($tag, 'kind', self::TAG_KINDS, $where),
            ]);
        }
    }

    /** @param array<mixed> $doc */
    private function importSources(array $doc): void
    {
        foreach (self::objects($doc, 'sources', 'sources') as $i => $source) {
            $where = "sources[$i]";
            $key = self::str($source, 'key', $where);
            self::unique($this->source_ids, $key, $where);
            $this->source_ids[$key] = $this->insert('sources', [
                'captured_on' => self::date($source, 'captured_on', $where),
                'source_title' => self::str($source, 'title', $where, 255),
                'history_path' => self::optStr($source, 'history_path', $where, 255),
                'origin' => self::optStr($source, 'origin', $where, 255),
            ]);
        }
    }

    /** @param array<mixed> $doc */
    private function importSections(array $doc): void
    {
        foreach (self::objects($doc, 'sections', 'sections') as $i => $section) {
            $where = "sections[$i]";
            $slug = self::str($section, 'slug', $where, 40);
            self::unique($this->section_ids, $slug, $where);
            $this->section_ids[$slug] = $this->insert('sections', [
                'section_slug' => $slug,
                'heading' => self::str($section, 'heading', $where, 100),
                'default_order' => self::int($section, 'order', $i * 10, $where),
            ]);
        }
    }

    /** @param array<mixed> $doc */
    private function importOrganizations(array $doc): void
    {
        foreach (self::objects($doc, 'organizations', 'organizations') as $i => $org) {
            $where = "organizations[$i]";
            $slug = self::str($org, 'slug', $where, 60);
            self::unique($this->org_ids, $slug, $where);
            $this->org_ids[$slug] = $this->insert('organizations', [
                'org_slug' => $slug,
                'city' => self::optStr($org, 'city', $where, 100),
                'country' => self::optStr($org, 'country', $where, 100),
                'org_url' => self::optStr($org, 'url', $where, 255),
            ]);
        }
    }

    /** @param array<mixed> $doc */
    private function importSkills(array $doc): void
    {
        foreach (self::objects($doc, 'skills', 'skills') as $i => $skill) {
            $where = "skills[$i]";
            $name = self::str($skill, 'name', $where, 100);
            self::unique($this->skill_ids, $name, $where);
            $this->skill_ids[$name] = $this->insert('skills', [
                'skill_name' => $name,
                'skill_category' => self::optStr($skill, 'category', $where, 40),
            ]);
        }
    }

    /** @param array<mixed> $doc */
    private function importEntries(array $doc): void
    {
        /** @var array<string, string> $parents child slug => parent slug */
        $parents = [];
        foreach (self::objects($doc, 'entries', 'entries') as $i => $entry) {
            $where = "entries[$i]";
            $slug = self::str($entry, 'slug', $where, 80);
            self::unique($this->entry_ids, $slug, $where);

            $started_on = self::optDate($entry, 'started_on', $where);
            $ended_on = self::optDate($entry, 'ended_on', $where);
            if ($started_on !== null && $ended_on !== null && $ended_on < $started_on) {
                throw new ImportException("$where: ends before it starts");
            }
            $org = self::optStr($entry, 'organization', $where);
            $source = self::optStr($entry, 'source', $where);

            $started_precision = self::optOneOf($entry, 'started_precision', self::PRECISIONS, $where)
                ?? ($started_on === null ? null : 'month');

            $entry_id = $this->insert('entries', [
                'section_id' => self::ref($this->section_ids, self::str($entry, 'section', $where), 'section', $where),
                'organization_id' => $org === null ? null : self::ref($this->org_ids, $org, 'organization', $where),
                'entry_slug' => $slug,
                'started_on' => $started_on,
                'started_precision' => $started_precision,
                'ended_on' => $ended_on,
                // A talk given as "day" precision ends at day precision too.
                'ended_precision' => self::optOneOf($entry, 'ended_precision', self::PRECISIONS, $where)
                    ?? ($ended_on === null ? null : ($started_precision ?? 'month')),
                'location_text' => self::optStr($entry, 'location', $where, 150),
                'include_by_default' => self::bool($entry, 'include_by_default', true, $where) ? 1 : 0,
                'sort_hint' => self::int($entry, 'sort_hint', 0, $where),
                'private_note' => self::optStr($entry, 'private_note', $where),
                'source_id' => $source === null ? null : self::ref($this->source_ids, $source, 'source', $where),
            ]);
            $this->entry_ids[$slug] = $entry_id;

            $parent = self::optStr($entry, 'parent', $where);
            if ($parent !== null) {
                $parents[$slug] = $parent;
            }
            foreach (array_values(array_unique(self::strings($entry, 'tags', $where))) as $tag) {
                $this->insert('entry_tags', [
                    'entry_id' => $entry_id,
                    'tag_id' => self::ref($this->tag_ids, $tag, 'tag', $where),
                ]);
            }
            foreach (array_values(array_unique(self::strings($entry, 'skills', $where))) as $n => $skill) {
                $this->insert('entry_skills', [
                    'entry_id' => $entry_id,
                    'skill_id' => self::ref($this->skill_ids, $skill, 'skill', $where),
                    'sort_order' => $n,
                ]);
            }
            foreach (self::objects($entry, 'links', "$where.links") as $j => $link) {
                $this->insert('entry_links', [
                    'entry_id' => $entry_id,
                    'url' => self::str($link, 'url', "$where.links[$j]", 500),
                    'link_kind' => self::optStr($link, 'kind', "$where.links[$j]", 40),
                ]);
            }
            foreach (self::objects($entry, 'texts', "$where.texts") as $j => $text) {
                $this->importText($entry_id, $text, $j, "$where.texts[$j]");
            }
        }
        $this->linkParents($parents);
    }

    /** @param array<mixed> $text */
    private function importText(int $entry_id, array $text, int $position, string $where): void
    {
        $written_on = self::date($text, 'written_on', $where);
        $retired_on = self::optDate($text, 'retired_on', $where);
        if ($retired_on !== null && $retired_on <= $written_on) {
            throw new ImportException("$where: retired before it was written");
        }
        $source = self::optStr($text, 'source', $where);
        $text_id = $this->insert('entry_texts', [
            'entry_id' => $entry_id,
            'field' => self::oneOf($text, 'field', self::FIELDS, $where),
            'body' => self::str($text, 'body', $where),
            'written_on' => $written_on,
            'retired_on' => $retired_on,
            'priority' => self::int($text, 'priority', 0, $where),
            'sort_order' => self::int($text, 'sort_order', $position, $where),
            'private_note' => self::optStr($text, 'private_note', $where),
            'source_id' => $source === null ? null : self::ref($this->source_ids, $source, 'source', $where),
        ]);
        foreach (array_values(array_unique(self::strings($text, 'tags', $where))) as $tag) {
            $this->insert('entry_text_tags', [
                'entry_text_id' => $text_id,
                'tag_id' => self::ref($this->tag_ids, $tag, 'tag', $where),
            ]);
        }
    }

    /** @param array<string, string> $parents child slug => parent slug */
    private function linkParents(array $parents): void
    {
        $update = $this->di_pdo->prepare('UPDATE entries SET parent_entry_id = ? WHERE entry_id = ?');
        foreach ($parents as $child => $parent) {
            $parent_id = self::ref($this->entry_ids, $parent, 'parent entry', "entry '$child'");
            $update->execute([$parent_id, $this->entry_ids[$child]]);
        }
        foreach (array_keys($parents) as $start) {
            $seen = [$start => true];
            $at = $start;
            while (isset($parents[$at])) {
                $at = $parents[$at];
                if (isset($seen[$at])) {
                    throw new ImportException("entry '$start' is its own ancestor");
                }
                $seen[$at] = true;
            }
        }
    }

    /** @param array<mixed> $doc */
    private function importContacts(array $doc): void
    {
        foreach (self::objects($doc, 'contact_points', 'contact_points') as $i => $contact) {
            $where = "contact_points[$i]";
            $contact_id = $this->insert('contact_points', [
                'contact_kind' => self::oneOf($contact, 'kind', self::CONTACT_KINDS, $where),
                'contact_value' => self::str($contact, 'value', $where, 255),
                'valid_from' => self::optDate($contact, 'valid_from', $where),
                'valid_until' => self::optDate($contact, 'valid_until', $where),
                'priority' => self::int($contact, 'priority', 0, $where),
                'is_private' => self::bool($contact, 'is_private', false, $where) ? 1 : 0,
            ]);
            foreach (array_values(array_unique(self::strings($contact, 'tags', $where))) as $tag) {
                $this->insert('contact_point_tags', [
                    'contact_point_id' => $contact_id,
                    'tag_id' => self::ref($this->tag_ids, $tag, 'tag', $where),
                ]);
            }
        }
    }

    /** @param array<mixed> $doc */
    private function importProfiles(array $doc): void
    {
        $public = 0;
        foreach (self::objects($doc, 'profiles', 'profiles') as $i => $profile) {
            $where = "profiles[$i]";
            $slug = self::str($profile, 'slug', $where, 60);
            self::unique($this->profile_ids, $slug, $where);
            $is_public = self::bool($profile, 'is_public', false, $where);
            $public += $is_public ? 1 : 0;
            $profile_id = $this->insert('profiles', [
                'profile_slug' => $slug,
                'profile_title' => self::str($profile, 'title', $where, 150),
                'as_of' => self::optDate($profile, 'as_of', $where),
                'wording_as_of' => self::optDate($profile, 'wording_as_of', $where),
                'is_public' => $is_public ? 1 : 0,
            ]);
            $this->profile_ids[$slug] = $profile_id;
            foreach (array_values(array_unique(self::strings($profile, 'tags', $where))) as $tag) {
                $this->insert('profile_tags', [
                    'profile_id' => $profile_id,
                    'tag_id' => self::ref($this->tag_ids, $tag, 'tag', $where),
                ]);
            }
            foreach (array_values(array_unique(self::strings($profile, 'sections', $where))) as $n => $section) {
                $this->insert('profile_sections', [
                    'profile_id' => $profile_id,
                    'section_id' => self::ref($this->section_ids, $section, 'section', $where),
                    'section_order' => $n,
                ]);
            }
        }
        if ($public > 1) {
            throw new ImportException("profiles: only one profile may be public, found $public");
        }
    }

    /** @param array<string, string|int|null> $row */
    private function insert(string $table, array $row): int
    {
        $columns = array_keys($row);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', array_fill(0, count($columns), '?'))
        );
        $this->di_pdo->prepare($sql)->execute(array_values($row));
        $this->counts[$table] = ($this->counts[$table] ?? 0) + 1;
        return (int) $this->di_pdo->lastInsertId();
    }

    /**
     * @param array<mixed> $a
     * @return list<array<mixed>>
     */
    private static function objects(array $a, string $key, string $where): array
    {
        $value = $a[$key] ?? [];
        if (!is_array($value) || !array_is_list($value)) {
            throw new ImportException("$where must be a list");
        }
        $objects = [];
        foreach ($value as $i => $item) {
            if (!is_array($item)) {
                throw new ImportException("{$where}[$i] must be an object");
            }
            $objects[] = $item;
        }
        return $objects;
    }

    /**
     * @param array<mixed> $a
     * @return list<string>
     */
    private static function strings(array $a, string $key, string $where): array
    {
        $value = $a[$key] ?? [];
        if (!is_array($value) || !array_is_list($value)) {
            throw new ImportException("$where: '$key' must be a list of strings");
        }
        $strings = [];
        foreach ($value as $item) {
            if (!is_string($item) || $item === '') {
                throw new ImportException("$where: '$key' must be a list of strings");
            }
            $strings[] = $item;
        }
        return $strings;
    }

    /**
     * $max is the column's VARCHAR length, so --check refuses what MySQL would.
     *
     * @param array<mixed> $a
     */
    private static function str(array $a, string $key, string $where, int $max = 0): string
    {
        $value = $a[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new ImportException("$where: '$key' must be a non-empty string");
        }
        return self::fits($value, $key, $where, $max);
    }

    private static function fits(string $value, string $key, string $where, int $max): string
    {
        if ($max > 0 && mb_strlen($value) > $max) {
            throw new ImportException("$where: '$key' must be at most $max characters");
        }
        return $value;
    }

    /** @param array<mixed> $a */
    private static function optStr(array $a, string $key, string $where, int $max = 0): ?string
    {
        $value = $a[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new ImportException("$where: '$key' must be a string or null");
        }
        return self::fits($value, $key, $where, $max);
    }

    /** @param array<mixed> $a */
    private static function date(array $a, string $key, string $where): string
    {
        $value = self::str($a, $key, $where);
        if (!Query::isDate($value)) {
            throw new ImportException("$where: '$key' must be a real date as YYYY-MM-DD, not '$value'");
        }
        return $value;
    }

    /** @param array<mixed> $a */
    private static function optDate(array $a, string $key, string $where): ?string
    {
        $value = self::optStr($a, $key, $where);
        if ($value !== null && !Query::isDate($value)) {
            throw new ImportException("$where: '$key' must be a real date as YYYY-MM-DD, not '$value'");
        }
        return $value;
    }

    /** @param array<mixed> $a */
    private static function int(array $a, string $key, int $default, string $where): int
    {
        $value = $a[$key] ?? $default;
        if (!is_int($value)) {
            throw new ImportException("$where: '$key' must be a whole number");
        }
        return $value;
    }

    /** @param array<mixed> $a */
    private static function bool(array $a, string $key, bool $default, string $where): bool
    {
        $value = $a[$key] ?? $default;
        if (!is_bool($value)) {
            throw new ImportException("$where: '$key' must be true or false");
        }
        return $value;
    }

    /**
     * @param array<mixed> $a
     * @param list<string> $allowed
     */
    private static function oneOf(array $a, string $key, array $allowed, string $where): string
    {
        $value = self::str($a, $key, $where);
        if (!in_array($value, $allowed, true)) {
            throw new ImportException("$where: '$key' must be one of " . implode(', ', $allowed));
        }
        return $value;
    }

    /**
     * @param array<mixed> $a
     * @param list<string> $allowed
     */
    private static function optOneOf(array $a, string $key, array $allowed, string $where): ?string
    {
        return self::optStr($a, $key, $where) === null ? null : self::oneOf($a, $key, $allowed, $where);
    }

    /**
     * MySQL's unique keys ignore case and trailing spaces, so this does too:
     * otherwise --check would pass a document the real import then refuses.
     *
     * @param array<string, int> $ids
     */
    private static function unique(array $ids, string $slug, string $where): void
    {
        $normal = mb_strtolower(rtrim($slug));
        foreach (array_keys($ids) as $taken) {
            if (mb_strtolower(rtrim((string) $taken)) === $normal) {
                throw new ImportException("$where: duplicate '$slug'");
            }
        }
    }

    /** @param array<string, int> $ids */
    private static function ref(array $ids, string $slug, string $what, string $where): int
    {
        if (!isset($ids[$slug])) {
            throw new ImportException("$where: unknown $what '$slug'");
        }
        return $ids[$slug];
    }
}
