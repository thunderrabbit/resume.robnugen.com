# Résumé Builder Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** resume.robnugen.com shows Rob's résumé, built from a database, to everyone, offers it as a downloadable PDF, and lets Rob (logged in) tailor it by audience, market and date.

**Architecture:**
- **Source of truth:** the résumé data lives as one JSON document in the private repo `../resume_private/`. A CLI importer replaces the MySQL résumé tables with it in a single transaction. The site's code is public and holds no personal data.
- **Rendering:** `Resume\Repository` reads rows with portable SQL. `Resume\Builder` makes every choice in plain PHP (which entries, which wording, which contacts), so the same logic runs on MySQL in production and on in-memory SQLite in the Unit suite. Templates render the result, and a print stylesheet makes the browser's "Save as PDF" look right.
- **The public PDF:** headless Chrome on Rob's machine prints the public page and rsyncs the file up.

**Tech Stack:** PHP 8.2 (server) / 8.3 (test runner), MySQL (InnoDB, utf8mb4), PDO, Codeception Unit suite, PHPStan level 10, phpcs PSR-12, headless Google Chrome (local only).

**Spec:** `docs/superpowers/specs/2026-10-11-resume-database-design.md`

## Global Constraints

- The server runs PHP 8.2.30, so use no 8.3-only syntax: no typed class constants, no `#[\Override]`, no `json_validate()`.
- Every PHP file in scope starts with `declare(strict_types=1);`. Code is PSR-12 and passes PHPStan level 10 with no baseline. Lines stay at or under 120 characters.
- One class per file. `Resume\Foo` lives at `classes/Resume/Foo.php`.
- Every primary key column name is unique across the whole project (`entry_id`, `entry_text_id`, …), never a bare `id`.
- No semicolons inside SQL comments: `executeMultipleSQL()` splits on them.
- **No personal data in this repo.** No real résumé JSON, no seed SQL, no dumps, no generated PDFs. Tests and examples use invented people (Pat Example).
- SQL in `classes/` stays portable: no `NOW()`, no `DATE_SUB`, no MySQL-only functions. Dates travel as `'Y-m-d'` strings and are compared as strings.
- Unit tests touch no MySQL, network or session. In-memory SQLite is allowed.
- Displayed dates spell the month: `14 Jun 2007`, `Jun 2007`, `Fall 2002`, `2007`. Never all-numeric.
- **Commits:** subject ≤ 50 characters, imperative, capitalized, no period. Use multiple `-m` flags and never a heredoc. End with the two attribution `-m` lines shown in each task.
- **Gates before every commit:** `~/work/rob/standards-mcp/run-unit.sh "$PWD"`, `~/work/rob/standards-mcp/run-phpcs.sh "$PWD"` and `~/work/rob/standards-mcp/run-phpstan.sh "$PWD"`. All three must pass. The pre-commit hook re-checks phpcs and PHPStan.

## Review Focus

1. **Résumé text holding HTML or ampersands** ("R&D", `<b>`, quotes) is shown as literal text, never as markup. *Pinned in Task 7 (`testEscapesEveryDatabaseString`).*
2. **The public page loaded before the migration is applied, or before the first import,** says "No résumé is published yet." It does not throw a PDO fatal. *Pinned in Task 4 (`testPublicQueryIsNullBeforeTablesExist`) and Task 7 (`testNullDocumentSaysNotPublished`).*
3. **A rebuild whose `wording_as_of` predates every wording** (e.g. 2005, for an entry first written up in 2022) still shows a title, taken from the nearest wording, rather than a blank. *Pinned in Task 6 (`testWordingBeforeAnyTextFallsBackToNearest`).*
4. **Point-in-time events and as-of views:** a talk reads "18 Sep 2021", not "18 Sep 2021 – Present". A job that ended after `as_of` reads "– Present" in that view. *Pinned in Task 5 (`testSameStartAndEndPrintsOnce`) and Task 6 (`testPointEventIsNotOngoing`, `testEndedAfterAsOfShowsPresent`).*
5. **A hand-edited tailor URL** (`as_of=2021-02-30`, `tags=speaking` as a string, unknown tags) falls back to defaults and never errors. *Pinned in Task 5 (`QueryTest`).*

---

## File Structure

| File | Responsibility |
|---|---|
| `db_schemas/03_resume/create_resume_tables.sql` | MySQL DDL for the 16 résumé tables (one admin click) |
| `classes/Resume/Tables.php` | Canonical table list, in delete order |
| `classes/Resume/SqliteSchema.php` | The same tables for in-memory SQLite (tests, `--check`) |
| `classes/Resume/ImportException.php` | A document the importer refuses |
| `classes/Resume/Importer.php` | JSON document → tables, all or nothing |
| `classes/Resume/SectionRow.php`, `EntryRow.php`, `TextRow.php`, `ContactRow.php`, `ResumeData.php` | Raw rows as read from the DB |
| `classes/Resume/Repository.php` | Portable SELECTs → `ResumeData`, profiles → `Query` |
| `classes/Resume/Query.php` | What to render: as_of, wording_as_of, tags, sections |
| `classes/Resume/DateText.php` | Dates + precision → "Aug 1999 – Jun 2002" |
| `classes/Resume/RenderedEntry.php`, `RenderedSection.php`, `Document.php` | Builder output, ready for templates |
| `classes/Resume/Builder.php` | Every selection rule in the spec |
| `classes/Resume/PublishedPdf.php` | Name and URL of the uploaded PDF |
| `templates/layout/resume.tpl.php` | Page shell for résumé pages, with nav for Rob only |
| `templates/resume/document.tpl.php`, `entry.tpl.php`, `tailor_form.tpl.php` | Rendering |
| `wwwroot/css/resume.css` | Screen + print styles |
| `wwwroot/index.php` | Public résumé (replaces the template's welcome page) |
| `wwwroot/tailor/index.php` | Rob's click-clack page |
| `bin/import_resume.php` | CLI: stdin JSON → DB, or `--check` into SQLite |
| `bin/publish_pdf.sh` | Local: headless Chrome → PDF → rsync |
| `Tests/Unit/ResumeFixture.php` | Invented example document, shared by tests |
| `Tests/Unit/Resume*Test.php` | Tests per class |

---

### Task 1: Open the bubble and make the gates runnable

**Files:**
- Modify: `classes/Version/Site.php` (SEMVER)
- Modify: `Tests/Unit/_bootstrap.php` (autoload `Tests\Unit\*` helpers)

**Interfaces:**
- Produces: branch `resume-builder`, BEGIN commit, `vendor/` populated, hooks enabled, and a test autoloader for `Tests\Unit\ResumeFixture`.

- [ ] **Step 1: Branch from the current tip**

```bash
cd ~/work/rob/jobhunt/resume.robnugen.com
git switch -c resume-builder
git config core.hooksPath .githooks
docker run --rm -e COMPOSER_HOME=/tmp/composer -v "$PWD":/app -w /app composer:2 composer install --no-interaction
```

- [ ] **Step 2: BEGIN commit, which is only the version bump**

In `classes/Version/Site.php`, change `public const SEMVER = '0.1.0';` to `public const SEMVER = '0.2.0';`.

```bash
git add classes/Version/Site.php
git commit -m "BEGIN resume builder" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>" -m "Claude-Session: https://claude.ai/code/session_01RpuvXy513YWEEMR3uzrQ6g"
```

- [ ] **Step 3: Let the Unit suite autoload helper classes in `Tests/Unit/`**

Append to `Tests/Unit/_bootstrap.php`, before the `if (!class_exists(\Config\Config::class))` block:

```php
// Shared test helpers such as Tests\Unit\ResumeFixture live beside the tests.
// Codeception loads *Test.php files itself, so only helpers come through here.
spl_autoload_register(function ($class) use ($projectRoot) {
    $prefix = 'Tests\\Unit\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = $projectRoot . '/Tests/Unit/' . substr($class, strlen($prefix)) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});
```

- [ ] **Step 4: Run all three gates on the untouched suite**

Run: `~/work/rob/standards-mcp/run-unit.sh "$PWD" && ~/work/rob/standards-mcp/run-phpcs.sh "$PWD" && ~/work/rob/standards-mcp/run-phpstan.sh "$PWD"`
Expected: all pass. The existing tests are unaffected.

- [ ] **Step 5: Commit**

```bash
git add Tests/Unit/_bootstrap.php
git commit -m "Autoload shared helpers in the Unit suite" -m "Resume tests share one invented fixture document. A class beside the tests keeps it out of every test file without a require that phpcs flags as a side effect." -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>" -m "Claude-Session: https://claude.ai/code/session_01RpuvXy513YWEEMR3uzrQ6g"
```

---

### Task 2: Schema, for MySQL and SQLite, kept in sync by a test

**Files:**
- Create: `db_schemas/03_resume/create_resume_tables.sql`
- Create: `classes/Resume/Tables.php`
- Create: `classes/Resume/SqliteSchema.php`
- Test: `Tests/Unit/ResumeSchemaFilesTest.php`

**Interfaces:**
- Produces: `\Resume\Tables::DELETE_ORDER` (list of 16 table names, children first) and `\Resume\SqliteSchema::create(\PDO $pdo): void`.

- [ ] **Step 1: Write the failing test**

`Tests/Unit/ResumeSchemaFilesTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;

/**
 * The résumé tables are written twice, once for MySQL (the migration) and
 * once for SQLite (tests and --check). These tests fail the moment the two
 * drift apart, or the migration breaks a rule executeMultipleSQL() relies on.
 */
class ResumeSchemaFilesTest extends Unit
{
    private const MIGRATION = '03_resume/create_resume_tables.sql';

    private function sql(): string
    {
        $sql = file_get_contents(dirname(__DIR__, 2) . '/db_schemas/' . self::MIGRATION);
        $this->assertIsString($sql);
        return $sql;
    }

    /** @return list<string> */
    private function createdTables(string $sql): array
    {
        preg_match_all('/CREATE TABLE `?(\w+)`?/', $sql, $m);
        return array_values($m[1]);
    }

    public function testMigrationPathHasTheShapeTheAdminPageAccepts(): void
    {
        $this->assertMatchesRegularExpression('#^[0-9]{2}_[a-zA-Z0-9_-]+/[a-zA-Z0-9_-]+\.sql$#', self::MIGRATION);
    }

    public function testNoSemicolonsInComments(): void
    {
        foreach (explode("\n", $this->sql()) as $n => $line) {
            $comment = strstr($line, '--');
            $this->assertFalse(
                $comment !== false && str_contains($comment, ';'),
                'line ' . ($n + 1) . ' has a semicolon in a comment'
            );
        }
    }

    public function testEveryReferenceTargetIsCreatedAboveIt(): void
    {
        $created = [];
        foreach (preg_split('/;\s*\n/', $this->sql()) ?: [] as $statement) {
            if (preg_match('/CREATE TABLE `?(\w+)`?/', $statement, $m) !== 1) {
                continue;
            }
            preg_match_all('/REFERENCES `?(\w+)`?/', $statement, $refs);
            foreach ($refs[1] as $target) {
                $this->assertTrue(
                    $target === $m[1] || in_array($target, $created, true),
                    "{$m[1]} references $target before it exists"
                );
            }
            $created[] = $m[1];
        }
    }

    public function testMysqlTablesMatchTheCanonicalList(): void
    {
        $expected = \Resume\Tables::DELETE_ORDER;
        $actual = $this->createdTables($this->sql());
        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual);
    }

    public function testSqliteTablesMatchTheCanonicalList(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        \Resume\SqliteSchema::create($pdo);
        $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'");
        $this->assertNotFalse($stmt);
        $actual = array_map(static fn (mixed $n): string => is_string($n) ? $n : '', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        $expected = \Resume\Tables::DELETE_ORDER;
        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual);
    }

    public function testPrimaryKeyNamesAreUniqueAcrossTheProject(): void
    {
        $names = [];
        foreach (glob(dirname(__DIR__, 2) . '/db_schemas/*/*.sql') ?: [] as $file) {
            $sql = (string) file_get_contents($file);
            preg_match_all('/`?(\w+_id)`?\s+INT[^,\n]*PRIMARY KEY/', $sql, $m);
            foreach ($m[1] as $name) {
                $this->assertNotContains($name, $names, "$name is a primary key twice");
                $names[] = $name;
            }
        }
        $this->assertContains('entry_text_id', $names);
    }
}
```

- [ ] **Step 2: Run the test and confirm it fails**

Run: `~/work/rob/standards-mcp/run-unit.sh "$PWD"`
Expected: FAIL. The migration file is missing and `Resume\Tables` doesn't exist ("You call that a file?").

- [ ] **Step 3: Write `classes/Resume/Tables.php`**

```php
<?php

declare(strict_types=1);

namespace Resume;

/**
 * Every résumé table, in the one place both schemas and the importer agree on.
 */
final class Tables
{
    /**
     * Children before parents: the order Importer empties them in, so no
     * foreign key ever points at a row that is already gone.
     */
    public const DELETE_ORDER = [
        'profile_sections',
        'profile_tags',
        'profiles',
        'contact_point_tags',
        'contact_points',
        'entry_text_tags',
        'entry_texts',
        'entry_links',
        'entry_skills',
        'entry_tags',
        'entries',
        'skills',
        'organizations',
        'sections',
        'tags',
        'sources',
    ];
}
```

- [ ] **Step 4: Write `db_schemas/03_resume/create_resume_tables.sql`**

```sql
-- Resume data. Data only, no layout. See docs/superpowers/specs/2026-10-11-resume-database-design.md
-- Keep semicolons out of these comments. executeMultipleSQL() splits on them.
-- Every REFERENCES target is created above the table that points at it.

CREATE TABLE `sources` (
  `source_id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  -- when that version of the resume was written
  `captured_on` DATE NOT NULL,
  `source_title` VARCHAR(255) NOT NULL,
  -- path inside the private data repo, never this one
  `history_path` VARCHAR(255) DEFAULT NULL,
  `origin` VARCHAR(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `sections` (
  `section_id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `section_slug` VARCHAR(40) NOT NULL,
  `heading` VARCHAR(100) NOT NULL,
  `default_order` SMALLINT NOT NULL,
  UNIQUE KEY `section_slug` (`section_slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `organizations` (
  `organization_id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `org_slug` VARCHAR(60) NOT NULL,
  `city` VARCHAR(100) DEFAULT NULL,
  `country` VARCHAR(100) DEFAULT NULL,
  `org_url` VARCHAR(255) DEFAULT NULL,
  UNIQUE KEY `org_slug` (`org_slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `tags` (
  `tag_id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `tag_slug` VARCHAR(40) NOT NULL,
  `tag_kind` ENUM('audience', 'market') NOT NULL,
  UNIQUE KEY `tag_slug` (`tag_slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `skills` (
  `skill_id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `skill_name` VARCHAR(100) NOT NULL,
  `skill_category` VARCHAR(40) DEFAULT NULL,
  UNIQUE KEY `skill_name` (`skill_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `entries` (
  `entry_id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `section_id` INT UNSIGNED NOT NULL,
  `organization_id` INT UNSIGNED DEFAULT NULL,
  `parent_entry_id` INT UNSIGNED DEFAULT NULL,
  `entry_slug` VARCHAR(80) NOT NULL,
  -- NULL for undated entries such as a summary or a language
  `started_on` DATE DEFAULT NULL,
  `started_precision` ENUM('day', 'month', 'season', 'year') DEFAULT NULL,
  -- NULL means ongoing. A one-day event sets this equal to started_on.
  `ended_on` DATE DEFAULT NULL,
  `ended_precision` ENUM('day', 'month', 'season', 'year') DEFAULT NULL,
  `location_text` VARCHAR(150) DEFAULT NULL,
  `include_by_default` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_hint` SMALLINT NOT NULL DEFAULT 0,
  -- never rendered
  `private_note` TEXT DEFAULT NULL,
  `source_id` INT UNSIGNED DEFAULT NULL,
  UNIQUE KEY `entry_slug` (`entry_slug`),
  CONSTRAINT `fk_entries_section_id` FOREIGN KEY (`section_id`) REFERENCES `sections` (`section_id`),
  CONSTRAINT `fk_entries_organization_id`
    FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`organization_id`),
  CONSTRAINT `fk_entries_parent_entry_id` FOREIGN KEY (`parent_entry_id`) REFERENCES `entries` (`entry_id`),
  CONSTRAINT `fk_entries_source_id` FOREIGN KEY (`source_id`) REFERENCES `sources` (`source_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `entry_texts` (
  `entry_text_id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `entry_id` INT UNSIGNED NOT NULL,
  `field` ENUM('title', 'org_name', 'summary', 'bullet', 'url_label') NOT NULL,
  `body` TEXT NOT NULL,
  `written_on` DATE NOT NULL,
  -- NULL means still in use
  `retired_on` DATE DEFAULT NULL,
  `priority` SMALLINT NOT NULL DEFAULT 0,
  `sort_order` SMALLINT NOT NULL DEFAULT 0,
  `private_note` TEXT DEFAULT NULL,
  `source_id` INT UNSIGNED DEFAULT NULL,
  CONSTRAINT `fk_entry_texts_entry_id` FOREIGN KEY (`entry_id`) REFERENCES `entries` (`entry_id`),
  CONSTRAINT `fk_entry_texts_source_id` FOREIGN KEY (`source_id`) REFERENCES `sources` (`source_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `entry_links` (
  `entry_link_id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `entry_id` INT UNSIGNED NOT NULL,
  `url` VARCHAR(500) NOT NULL,
  `link_kind` VARCHAR(40) DEFAULT NULL,
  CONSTRAINT `fk_entry_links_entry_id` FOREIGN KEY (`entry_id`) REFERENCES `entries` (`entry_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `entry_skills` (
  `entry_id` INT UNSIGNED NOT NULL,
  `skill_id` INT UNSIGNED NOT NULL,
  `sort_order` SMALLINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`entry_id`, `skill_id`),
  CONSTRAINT `fk_entry_skills_entry_id` FOREIGN KEY (`entry_id`) REFERENCES `entries` (`entry_id`),
  CONSTRAINT `fk_entry_skills_skill_id` FOREIGN KEY (`skill_id`) REFERENCES `skills` (`skill_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `entry_tags` (
  `entry_id` INT UNSIGNED NOT NULL,
  `tag_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`entry_id`, `tag_id`),
  CONSTRAINT `fk_entry_tags_entry_id` FOREIGN KEY (`entry_id`) REFERENCES `entries` (`entry_id`),
  CONSTRAINT `fk_entry_tags_tag_id` FOREIGN KEY (`tag_id`) REFERENCES `tags` (`tag_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `entry_text_tags` (
  `entry_text_id` INT UNSIGNED NOT NULL,
  `tag_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`entry_text_id`, `tag_id`),
  CONSTRAINT `fk_entry_text_tags_entry_text_id`
    FOREIGN KEY (`entry_text_id`) REFERENCES `entry_texts` (`entry_text_id`),
  CONSTRAINT `fk_entry_text_tags_tag_id` FOREIGN KEY (`tag_id`) REFERENCES `tags` (`tag_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `contact_points` (
  `contact_point_id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `contact_kind` ENUM('name', 'location', 'phone', 'whatsapp', 'email', 'url', 'relocation') NOT NULL,
  `contact_value` VARCHAR(255) NOT NULL,
  `valid_from` DATE DEFAULT NULL,
  `valid_until` DATE DEFAULT NULL,
  `priority` SMALLINT NOT NULL DEFAULT 0,
  `is_private` TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `contact_point_tags` (
  `contact_point_id` INT UNSIGNED NOT NULL,
  `tag_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`contact_point_id`, `tag_id`),
  CONSTRAINT `fk_contact_point_tags_contact_point_id`
    FOREIGN KEY (`contact_point_id`) REFERENCES `contact_points` (`contact_point_id`),
  CONSTRAINT `fk_contact_point_tags_tag_id` FOREIGN KEY (`tag_id`) REFERENCES `tags` (`tag_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `profiles` (
  `profile_id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `profile_slug` VARCHAR(60) NOT NULL,
  `profile_title` VARCHAR(150) NOT NULL,
  -- NULL means today, for both dates
  `as_of` DATE DEFAULT NULL,
  `wording_as_of` DATE DEFAULT NULL,
  -- at most one row, enforced by the importer, serves the public page
  `is_public` TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY `profile_slug` (`profile_slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `profile_tags` (
  `profile_id` INT UNSIGNED NOT NULL,
  `tag_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`profile_id`, `tag_id`),
  CONSTRAINT `fk_profile_tags_profile_id` FOREIGN KEY (`profile_id`) REFERENCES `profiles` (`profile_id`),
  CONSTRAINT `fk_profile_tags_tag_id` FOREIGN KEY (`tag_id`) REFERENCES `tags` (`tag_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `profile_sections` (
  `profile_id` INT UNSIGNED NOT NULL,
  `section_id` INT UNSIGNED NOT NULL,
  `section_order` SMALLINT NOT NULL,
  PRIMARY KEY (`profile_id`, `section_id`),
  CONSTRAINT `fk_profile_sections_profile_id` FOREIGN KEY (`profile_id`) REFERENCES `profiles` (`profile_id`),
  CONSTRAINT `fk_profile_sections_section_id` FOREIGN KEY (`section_id`) REFERENCES `sections` (`section_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 5: Write `classes/Resume/SqliteSchema.php`**

```php
<?php

declare(strict_types=1);

namespace Resume;

/**
 * The résumé tables for an in-memory SQLite: the Unit suite's database and
 * `bin/import_resume.php --check`. Same table and column names as
 * db_schemas/03_resume/create_resume_tables.sql, which ResumeSchemaFilesTest
 * keeps honest. SQLite has no ENUM, so the importer validates those values.
 */
final class SqliteSchema
{
    private const STATEMENTS = [
        'CREATE TABLE sources (
            source_id INTEGER PRIMARY KEY AUTOINCREMENT, captured_on TEXT NOT NULL,
            source_title TEXT NOT NULL, history_path TEXT, origin TEXT)',
        'CREATE TABLE sections (
            section_id INTEGER PRIMARY KEY AUTOINCREMENT, section_slug TEXT NOT NULL UNIQUE,
            heading TEXT NOT NULL, default_order INTEGER NOT NULL)',
        'CREATE TABLE organizations (
            organization_id INTEGER PRIMARY KEY AUTOINCREMENT, org_slug TEXT NOT NULL UNIQUE,
            city TEXT, country TEXT, org_url TEXT)',
        'CREATE TABLE tags (
            tag_id INTEGER PRIMARY KEY AUTOINCREMENT, tag_slug TEXT NOT NULL UNIQUE,
            tag_kind TEXT NOT NULL)',
        'CREATE TABLE skills (
            skill_id INTEGER PRIMARY KEY AUTOINCREMENT, skill_name TEXT NOT NULL UNIQUE,
            skill_category TEXT)',
        'CREATE TABLE entries (
            entry_id INTEGER PRIMARY KEY AUTOINCREMENT, section_id INTEGER NOT NULL,
            organization_id INTEGER, parent_entry_id INTEGER, entry_slug TEXT NOT NULL UNIQUE,
            started_on TEXT, started_precision TEXT, ended_on TEXT, ended_precision TEXT,
            location_text TEXT, include_by_default INTEGER NOT NULL DEFAULT 1,
            sort_hint INTEGER NOT NULL DEFAULT 0, private_note TEXT, source_id INTEGER)',
        'CREATE TABLE entry_texts (
            entry_text_id INTEGER PRIMARY KEY AUTOINCREMENT, entry_id INTEGER NOT NULL,
            field TEXT NOT NULL, body TEXT NOT NULL, written_on TEXT NOT NULL, retired_on TEXT,
            priority INTEGER NOT NULL DEFAULT 0, sort_order INTEGER NOT NULL DEFAULT 0,
            private_note TEXT, source_id INTEGER)',
        'CREATE TABLE entry_links (
            entry_link_id INTEGER PRIMARY KEY AUTOINCREMENT, entry_id INTEGER NOT NULL,
            url TEXT NOT NULL, link_kind TEXT)',
        'CREATE TABLE entry_skills (
            entry_id INTEGER NOT NULL, skill_id INTEGER NOT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (entry_id, skill_id))',
        'CREATE TABLE entry_tags (
            entry_id INTEGER NOT NULL, tag_id INTEGER NOT NULL, PRIMARY KEY (entry_id, tag_id))',
        'CREATE TABLE entry_text_tags (
            entry_text_id INTEGER NOT NULL, tag_id INTEGER NOT NULL,
            PRIMARY KEY (entry_text_id, tag_id))',
        'CREATE TABLE contact_points (
            contact_point_id INTEGER PRIMARY KEY AUTOINCREMENT, contact_kind TEXT NOT NULL,
            contact_value TEXT NOT NULL, valid_from TEXT, valid_until TEXT,
            priority INTEGER NOT NULL DEFAULT 0, is_private INTEGER NOT NULL DEFAULT 0)',
        'CREATE TABLE contact_point_tags (
            contact_point_id INTEGER NOT NULL, tag_id INTEGER NOT NULL,
            PRIMARY KEY (contact_point_id, tag_id))',
        'CREATE TABLE profiles (
            profile_id INTEGER PRIMARY KEY AUTOINCREMENT, profile_slug TEXT NOT NULL UNIQUE,
            profile_title TEXT NOT NULL, as_of TEXT, wording_as_of TEXT,
            is_public INTEGER NOT NULL DEFAULT 0)',
        'CREATE TABLE profile_tags (
            profile_id INTEGER NOT NULL, tag_id INTEGER NOT NULL, PRIMARY KEY (profile_id, tag_id))',
        'CREATE TABLE profile_sections (
            profile_id INTEGER NOT NULL, section_id INTEGER NOT NULL,
            section_order INTEGER NOT NULL, PRIMARY KEY (profile_id, section_id))',
    ];

    public static function create(\PDO $pdo): void
    {
        foreach (self::STATEMENTS as $sql) {
            $pdo->exec($sql);
        }
    }
}
```

- [ ] **Step 6: Run the tests and confirm they pass**

Run: `~/work/rob/standards-mcp/run-unit.sh "$PWD"`
Expected: PASS, with all six `ResumeSchemaFilesTest` tests green.

- [ ] **Step 7: Run phpcs and PHPStan, then commit**

```bash
~/work/rob/standards-mcp/run-phpcs.sh "$PWD" && ~/work/rob/standards-mcp/run-phpstan.sh "$PWD"
git add db_schemas/03_resume classes/Resume/Tables.php classes/Resume/SqliteSchema.php Tests/Unit/ResumeSchemaFilesTest.php
git commit -m "Add the resume tables for MySQL and SQLite" -m "One migration file so the admin page applies it in one click. The SQLite copy lets the Unit suite and the importer's --check run with no server, and a test fails if the two ever drift apart." -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>" -m "Claude-Session: https://claude.ai/code/session_01RpuvXy513YWEEMR3uzrQ6g"
```

---

### Task 3: Importer (JSON document → tables, all or nothing)

**Files:**
- Create: `classes/Resume/ImportException.php`
- Create: `classes/Resume/Importer.php`
- Create: `Tests/Unit/ResumeFixture.php`
- Test: `Tests/Unit/ResumeImporterTest.php`

**Interfaces:**
- Consumes: `\Resume\Tables::DELETE_ORDER`, `\Resume\SqliteSchema::create()`. Also `\Resume\Query::isDate()` from Task 5. **This task creates `classes/Resume/Query.php` with only `isDate()`.** Task 5 adds the rest of that class.
- Produces: `\Resume\Importer::__construct(\PDO $di_pdo)` and `import(string $json): array<string,int>` (rows inserted per table), which throws `\Resume\ImportException`. Also `\Tests\Unit\ResumeFixture::doc(): array<string,mixed>`, `::json(): string` and `::loadedPdo(): \PDO`.

**The JSON format** (also documented in CLAUDE.md, Task 9):

| Key | Fields |
|---|---|
| `tags[]` | `slug`, `kind` (`audience` or `market`) |
| `sources[]` | `key`, `captured_on`, `title`, `history_path?`, `origin?` |
| `sections[]` | `slug`, `heading`, `order?` |
| `organizations[]` | `slug`, `city?`, `country?`, `url?` |
| `skills[]` | `name`, `category?` |
| `entries[]` | `slug`, `section`, `organization?`, `parent?`, `started_on?`, `started_precision?`, `ended_on?`, `ended_precision?`, `location?`, `include_by_default?` (true), `sort_hint?`, `private_note?`, `source?`, `tags?`, `skills?`, `links[]?` (`url`, `kind?`), `texts[]` |
| `entries[].texts[]` | `field` (`title`, `org_name`, `summary`, `bullet` or `url_label`), `body`, `written_on`, `retired_on?`, `priority?`, `sort_order?`, `tags?`, `source?`, `private_note?` |
| `contact_points[]` | `kind`, `value`, `valid_from?`, `valid_until?`, `priority?`, `is_private?`, `tags?` |
| `profiles[]` | `slug`, `title`, `as_of?`, `wording_as_of?`, `is_public?`, `tags?`, `sections?` |

References use slugs (or `key` for sources), never numeric ids.

- [ ] **Step 1: Write the fixture, using invented data only**

`Tests/Unit/ResumeFixture.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

/**
 * An invented résumé exercising every rule in the spec. Pat Example is not a
 * real person, and nothing personal belongs in this repo.
 */
final class ResumeFixture
{
    /** @return array<string, mixed> */
    public static function doc(): array
    {
        return [
            'tags' => [
                ['slug' => 'programming', 'kind' => 'audience'],
                ['slug' => 'speaking', 'kind' => 'audience'],
                ['slug' => 'market-au', 'kind' => 'market'],
            ],
            'sources' => [
                ['key' => 'v2005', 'captured_on' => '2005-03-03', 'title' => 'Old version'],
            ],
            'sections' => [
                ['slug' => 'experience', 'heading' => 'Experience', 'order' => 10],
                ['slug' => 'speaking', 'heading' => 'Speaking', 'order' => 20],
                ['slug' => 'education', 'heading' => 'Education', 'order' => 30],
            ],
            'organizations' => [
                ['slug' => 'acme', 'city' => 'Springfield'],
                ['slug' => 'uni', 'city' => 'Shelbyville'],
            ],
            'skills' => [
                ['name' => 'PHP', 'category' => 'language'],
                ['name' => 'SQL', 'category' => 'data'],
            ],
            'entries' => [
                [
                    'slug' => 'acme',
                    'section' => 'experience',
                    'organization' => 'acme',
                    'started_on' => '2010-04-01',
                    'ended_on' => '2020-06-01',
                    'tags' => ['programming'],
                    'skills' => ['PHP', 'SQL', 'PHP'],
                    'source' => 'v2005',
                    'private_note' => 'secret reason',
                    'texts' => [
                        ['field' => 'org_name', 'body' => 'Acme & Sons', 'written_on' => '2012-01-01'],
                        [
                            'field' => 'title',
                            'body' => 'Programmer',
                            'written_on' => '2012-01-01',
                            'retired_on' => '2022-01-01',
                        ],
                        ['field' => 'title', 'body' => 'Senior Engineer', 'written_on' => '2022-01-01'],
                        [
                            'field' => 'title',
                            'body' => 'Speaker-facing title',
                            'written_on' => '2022-01-01',
                            'tags' => ['speaking'],
                        ],
                        [
                            'field' => 'bullet',
                            'body' => 'Cut report time; from days to hours',
                            'written_on' => '2012-01-01',
                            'sort_order' => 1,
                        ],
                        [
                            'field' => 'bullet',
                            'body' => 'Wrote the <b>SQL</b>',
                            'written_on' => '2012-01-01',
                            'sort_order' => 0,
                        ],
                    ],
                ],
                [
                    'slug' => 'talk',
                    'section' => 'speaking',
                    'started_on' => '2021-09-18',
                    'started_precision' => 'day',
                    'ended_on' => '2021-09-18',
                    'ended_precision' => 'day',
                    'tags' => ['speaking'],
                    'include_by_default' => false,
                    'links' => [['url' => 'https://example.com/talk', 'kind' => 'video']],
                    'texts' => [['field' => 'title', 'body' => 'Happy Feet', 'written_on' => '2021-09-18']],
                ],
                [
                    'slug' => 'uni',
                    'section' => 'education',
                    'organization' => 'uni',
                    'started_on' => '1990-09-01',
                    'ended_on' => '1995-05-01',
                    'texts' => [
                        ['field' => 'title', 'body' => 'B.S. Computer Science', 'written_on' => '2005-03-03'],
                    ],
                ],
                [
                    'slug' => 'uni-recycling',
                    'section' => 'education',
                    'parent' => 'uni',
                    'started_on' => '1993-10-01',
                    'ended_on' => '1995-05-01',
                    'texts' => [['field' => 'title', 'body' => 'Recycling Chair', 'written_on' => '2005-03-03']],
                ],
            ],
            'contact_points' => [
                ['kind' => 'name', 'value' => 'Pat Example'],
                ['kind' => 'email', 'value' => 'pat@example.com'],
                ['kind' => 'phone', 'value' => '555-0100', 'valid_until' => '2010-01-01'],
                ['kind' => 'phone', 'value' => '555-0199', 'valid_from' => '2010-01-01'],
                ['kind' => 'whatsapp', 'value' => '+1 555 0199', 'tags' => ['market-au']],
                ['kind' => 'location', 'value' => '1 Secret Lane', 'is_private' => true],
                ['kind' => 'location', 'value' => 'Springfield'],
            ],
            'profiles' => [
                ['slug' => 'public', 'title' => 'Public', 'is_public' => true],
                [
                    'slug' => 'talks',
                    'title' => 'Speaking',
                    'tags' => ['speaking'],
                    'sections' => ['speaking', 'experience'],
                ],
            ],
        ];
    }

    public static function json(): string
    {
        return json_encode(self::doc(), JSON_THROW_ON_ERROR);
    }

    public static function emptyPdo(): \PDO
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        \Resume\SqliteSchema::create($pdo);
        return $pdo;
    }

    public static function loadedPdo(): \PDO
    {
        $pdo = self::emptyPdo();
        (new \Resume\Importer($pdo))->import(self::json());
        return $pdo;
    }
}
```

- [ ] **Step 2: Write the failing tests**

`Tests/Unit/ResumeImporterTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;

class ResumeImporterTest extends Unit
{
    private function count(\PDO $pdo, string $sql): int
    {
        $stmt = $pdo->query($sql);
        $value = $stmt === false ? false : $stmt->fetchColumn();
        return is_int($value) ? $value : -1;
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $edit
     */
    private function importEdited(\PDO $pdo, callable $edit): void
    {
        (new \Resume\Importer($pdo))->import(json_encode($edit(ResumeFixture::doc()), JSON_THROW_ON_ERROR));
    }

    public function testImportsEveryTable(): void
    {
        $counts = (new \Resume\Importer(ResumeFixture::emptyPdo()))->import(ResumeFixture::json());
        $this->assertSame(4, $counts['entries']);
        $this->assertSame(9, $counts['entry_texts']);
        $this->assertSame(7, $counts['contact_points']);
        $this->assertSame(2, $counts['profiles']);
        $this->assertSame(2, $counts['entry_skills'], 'the duplicate PHP is dropped');
    }

    public function testReimportReplacesInsteadOfAppending(): void
    {
        $pdo = ResumeFixture::loadedPdo();
        (new \Resume\Importer($pdo))->import(ResumeFixture::json());
        $this->assertSame(4, $this->count($pdo, 'SELECT COUNT(*) FROM entries'));
        $this->assertSame(9, $this->count($pdo, 'SELECT COUNT(*) FROM entry_texts'));
    }

    public function testParentIsLinkedBySlug(): void
    {
        $pdo = ResumeFixture::loadedPdo();
        $this->assertSame(
            $this->count($pdo, "SELECT entry_id FROM entries WHERE entry_slug = 'uni'"),
            $this->count($pdo, "SELECT parent_entry_id FROM entries WHERE entry_slug = 'uni-recycling'")
        );
    }

    public function testTextWithSemicolonsSurvivesWhole(): void
    {
        $pdo = ResumeFixture::loadedPdo();
        $this->assertSame(1, $this->count(
            $pdo,
            "SELECT COUNT(*) FROM entry_texts WHERE body = 'Cut report time; from days to hours'"
        ));
    }

    public function testBadDocumentIsRejectedAndOldDataKept(): void
    {
        $pdo = ResumeFixture::loadedPdo();
        try {
            $this->importEdited($pdo, static function (array $doc): array {
                $doc['entries'][0]['section'] = 'nope';
                return $doc;
            });
            $this->fail('expected ImportException');
        } catch (\Resume\ImportException $e) {
            $this->assertStringContainsString("entries[0]: unknown section 'nope'", $e->getMessage());
        }
        $this->assertSame(4, $this->count($pdo, 'SELECT COUNT(*) FROM entries'), 'rolled back');
    }

    public function testInvalidJsonIsRejected(): void
    {
        $this->expectException(\Resume\ImportException::class);
        (new \Resume\Importer(ResumeFixture::emptyPdo()))->import('{not json');
    }

    public function testImpossibleDateIsRejected(): void
    {
        $this->expectExceptionMessage("entries[1]: 'started_on' must be a real date");
        $this->importEdited(ResumeFixture::emptyPdo(), static function (array $doc): array {
            $doc['entries'][1]['started_on'] = '2021-02-30';
            return $doc;
        });
    }

    public function testDuplicateSlugIsRejected(): void
    {
        $this->expectExceptionMessage("entries[1]: duplicate 'acme'");
        $this->importEdited(ResumeFixture::emptyPdo(), static function (array $doc): array {
            $doc['entries'][1]['slug'] = 'acme';
            return $doc;
        });
    }

    public function testTwoPublicProfilesAreRejected(): void
    {
        $this->expectExceptionMessage('only one profile may be public');
        $this->importEdited(ResumeFixture::emptyPdo(), static function (array $doc): array {
            $doc['profiles'][1]['is_public'] = true;
            return $doc;
        });
    }

    public function testParentCycleIsRejected(): void
    {
        $this->expectExceptionMessage('is its own ancestor');
        $this->importEdited(ResumeFixture::emptyPdo(), static function (array $doc): array {
            $doc['entries'][2]['parent'] = 'uni-recycling';
            return $doc;
        });
    }

    public function testRetiredBeforeWrittenIsRejected(): void
    {
        $this->expectExceptionMessage("entries[0].texts[1]: retired before it was written");
        $this->importEdited(ResumeFixture::emptyPdo(), static function (array $doc): array {
            $doc['entries'][0]['texts'][1]['retired_on'] = '2011-01-01';
            return $doc;
        });
    }
}
```

PHPStan note: `$doc['entries'][0]['section']` on `array<string, mixed>` is "Cannot access offset on mixed" at level 10. If PHPStan flags the closures, annotate each with `/** @param array<string, mixed> $doc */` and narrow it with `assert(is_array($doc['entries']) && is_array($doc['entries'][0]))` before writing. Don't add a baseline.

- [ ] **Step 3: Run the tests and confirm they fail**

Run: `~/work/rob/standards-mcp/run-unit.sh "$PWD"`
Expected: FAIL, because `Resume\Importer` doesn't exist.

- [ ] **Step 4: Write `classes/Resume/ImportException.php`**

```php
<?php

declare(strict_types=1);

namespace Resume;

/**
 * The importer refused the document. The message names where, e.g.
 * "entries[3].texts[0]: 'written_on' must be a real date".
 */
final class ImportException extends \RuntimeException
{
}
```

- [ ] **Step 5: Write `classes/Resume/Query.php` with only `isDate()` for now**

```php
<?php

declare(strict_types=1);

namespace Resume;

/**
 * What to render. Task 5 adds the properties and fromRequest().
 */
final class Query
{
    /** A real calendar date written as YYYY-MM-DD. */
    public static function isDate(mixed $value): bool
    {
        if (!is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
            return false;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
}
```

- [ ] **Step 6: Write `classes/Resume/Importer.php`**

```php
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
            $slug = self::str($tag, 'slug', $where);
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
                'source_title' => self::str($source, 'title', $where),
                'history_path' => self::optStr($source, 'history_path', $where),
                'origin' => self::optStr($source, 'origin', $where),
            ]);
        }
    }

    /** @param array<mixed> $doc */
    private function importSections(array $doc): void
    {
        foreach (self::objects($doc, 'sections', 'sections') as $i => $section) {
            $where = "sections[$i]";
            $slug = self::str($section, 'slug', $where);
            self::unique($this->section_ids, $slug, $where);
            $this->section_ids[$slug] = $this->insert('sections', [
                'section_slug' => $slug,
                'heading' => self::str($section, 'heading', $where),
                'default_order' => self::int($section, 'order', $i * 10, $where),
            ]);
        }
    }

    /** @param array<mixed> $doc */
    private function importOrganizations(array $doc): void
    {
        foreach (self::objects($doc, 'organizations', 'organizations') as $i => $org) {
            $where = "organizations[$i]";
            $slug = self::str($org, 'slug', $where);
            self::unique($this->org_ids, $slug, $where);
            $this->org_ids[$slug] = $this->insert('organizations', [
                'org_slug' => $slug,
                'city' => self::optStr($org, 'city', $where),
                'country' => self::optStr($org, 'country', $where),
                'org_url' => self::optStr($org, 'url', $where),
            ]);
        }
    }

    /** @param array<mixed> $doc */
    private function importSkills(array $doc): void
    {
        foreach (self::objects($doc, 'skills', 'skills') as $i => $skill) {
            $where = "skills[$i]";
            $name = self::str($skill, 'name', $where);
            self::unique($this->skill_ids, $name, $where);
            $this->skill_ids[$name] = $this->insert('skills', [
                'skill_name' => $name,
                'skill_category' => self::optStr($skill, 'category', $where),
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
            $slug = self::str($entry, 'slug', $where);
            self::unique($this->entry_ids, $slug, $where);

            $started_on = self::optDate($entry, 'started_on', $where);
            $ended_on = self::optDate($entry, 'ended_on', $where);
            if ($started_on !== null && $ended_on !== null && $ended_on < $started_on) {
                throw new ImportException("$where: ends before it starts");
            }
            $org = self::optStr($entry, 'organization', $where);
            $source = self::optStr($entry, 'source', $where);

            $entry_id = $this->insert('entries', [
                'section_id' => self::ref($this->section_ids, self::str($entry, 'section', $where), 'section', $where),
                'organization_id' => $org === null ? null : self::ref($this->org_ids, $org, 'organization', $where),
                'entry_slug' => $slug,
                'started_on' => $started_on,
                'started_precision' => self::optOneOf($entry, 'started_precision', self::PRECISIONS, $where)
                    ?? ($started_on === null ? null : 'month'),
                'ended_on' => $ended_on,
                'ended_precision' => self::optOneOf($entry, 'ended_precision', self::PRECISIONS, $where)
                    ?? ($ended_on === null ? null : 'month'),
                'location_text' => self::optStr($entry, 'location', $where),
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
                    'url' => self::str($link, 'url', "$where.links[$j]"),
                    'link_kind' => self::optStr($link, 'kind', "$where.links[$j]"),
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
                'contact_value' => self::str($contact, 'value', $where),
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
            $slug = self::str($profile, 'slug', $where);
            self::unique($this->profile_ids, $slug, $where);
            $is_public = self::bool($profile, 'is_public', false, $where);
            $public += $is_public ? 1 : 0;
            $profile_id = $this->insert('profiles', [
                'profile_slug' => $slug,
                'profile_title' => self::str($profile, 'title', $where),
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

    /** @param array<mixed> $a */
    private static function str(array $a, string $key, string $where): string
    {
        $value = $a[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new ImportException("$where: '$key' must be a non-empty string");
        }
        return $value;
    }

    /** @param array<mixed> $a */
    private static function optStr(array $a, string $key, string $where): ?string
    {
        $value = $a[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new ImportException("$where: '$key' must be a string or null");
        }
        return $value;
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

    /** @param array<string, int> $ids */
    private static function unique(array $ids, string $slug, string $where): void
    {
        if (isset($ids[$slug])) {
            throw new ImportException("$where: duplicate '$slug'");
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
```

- [ ] **Step 7: Run the tests and confirm they pass**

Run: `~/work/rob/standards-mcp/run-unit.sh "$PWD"`
Expected: PASS, with all 11 importer tests green.

- [ ] **Step 8: Run phpcs and PHPStan, then commit**

```bash
~/work/rob/standards-mcp/run-phpcs.sh "$PWD" && ~/work/rob/standards-mcp/run-phpstan.sh "$PWD"
git add classes/Resume/ImportException.php classes/Resume/Importer.php classes/Resume/Query.php Tests/Unit/ResumeFixture.php Tests/Unit/ResumeImporterTest.php
git commit -m "Import the resume from one JSON document" -m "The JSON in the private repo is the source of truth and the tables are a copy. The whole import is one transaction, so a bad document leaves the live resume untouched, and the error names the exact entry and field." -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>" -m "Claude-Session: https://claude.ai/code/session_01RpuvXy513YWEEMR3uzrQ6g"
```

---

### Task 4: Row classes and Repository

**Files:**
- Create: `classes/Resume/SectionRow.php`, `EntryRow.php`, `TextRow.php`, `ContactRow.php`, `ResumeData.php`, `Repository.php`
- Test: `Tests/Unit/ResumeRepositoryTest.php`

**Interfaces:**
- Consumes: `ResumeFixture::loadedPdo()`, `ResumeFixture::emptyPdo()`, `Query::isDate()`. It also constructs `Query` with named args `as_of`, `wording_as_of`, `tags`, `section_slugs`. Task 5 completes `Query`, so **this task adds Query's constructor** (see Step 3).
- Produces:
  - `Repository::__construct(\PDO $di_pdo)`
  - `load(): ResumeData`
  - `publicQuery(string $today): ?Query`
  - `profileQuery(string $slug, string $today): ?Query`
  - `profiles(): list<array{slug: string, title: string}>`
  - Row classes with the public readonly properties listed in Step 4.

- [ ] **Step 1: Write the failing tests**

`Tests/Unit/ResumeRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;

class ResumeRepositoryTest extends Unit
{
    private function repo(): \Resume\Repository
    {
        return new \Resume\Repository(ResumeFixture::loadedPdo());
    }

    private function entry(\Resume\ResumeData $data, string $slug): \Resume\EntryRow
    {
        foreach ($data->entries as $entry) {
            if ($entry->slug === $slug) {
                return $entry;
            }
        }
        $this->fail("no entry $slug");
    }

    public function testLoadReadsEntriesWithTagsSkillsAndLinks(): void
    {
        $data = $this->repo()->load();
        $this->assertCount(4, $data->entries);
        $acme = $this->entry($data, 'acme');
        $this->assertSame(['programming'], $acme->tags);
        $this->assertSame(['PHP', 'SQL'], $acme->skills);
        $this->assertSame('Springfield', $acme->location, 'falls back to the organization city');
        $this->assertSame('experience', $acme->section_slug);
        $this->assertSame([['url' => 'https://example.com/talk', 'kind' => 'video']], $this->entry($data, 'talk')->links);
        $this->assertFalse($this->entry($data, 'talk')->include_by_default);
        $this->assertSame($this->entry($data, 'uni')->entry_id, $this->entry($data, 'uni-recycling')->parent_entry_id);
    }

    public function testLoadReadsTextsContactsAndTags(): void
    {
        $data = $this->repo()->load();
        $this->assertCount(9, $data->texts);
        $tagged = array_values(array_filter($data->texts, static fn (\Resume\TextRow $t): bool => $t->tags !== []));
        $this->assertCount(1, $tagged);
        $this->assertSame(['speaking'], $tagged[0]->tags);
        $this->assertCount(7, $data->contacts);
        $this->assertSame(['market-au', 'programming', 'speaking'], $data->known_tags);
        $this->assertCount(3, $data->sections);
    }

    public function testPublicQueryDefaultsDatesToToday(): void
    {
        $query = $this->repo()->publicQuery('2026-10-11');
        $this->assertNotNull($query);
        $this->assertSame('2026-10-11', $query->as_of);
        $this->assertSame('2026-10-11', $query->wording_as_of);
        $this->assertSame([], $query->tags);
        $this->assertNull($query->section_slugs);
    }

    public function testProfileQueryCarriesTagsAndSectionOrder(): void
    {
        $query = $this->repo()->profileQuery('talks', '2026-10-11');
        $this->assertNotNull($query);
        $this->assertSame(['speaking'], $query->tags);
        $this->assertSame(['speaking', 'experience'], $query->section_slugs);
        $this->assertNull($this->repo()->profileQuery('nope', '2026-10-11'));
    }

    public function testProfilesAreListed(): void
    {
        $this->assertSame(
            [['slug' => 'public', 'title' => 'Public'], ['slug' => 'talks', 'title' => 'Speaking']],
            $this->repo()->profiles()
        );
    }

    public function testPublicQueryIsNullBeforeTablesExist(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->assertNull((new \Resume\Repository($pdo))->publicQuery('2026-10-11'));
    }

    public function testPublicQueryIsNullBeforeFirstImport(): void
    {
        $this->assertNull((new \Resume\Repository(ResumeFixture::emptyPdo()))->publicQuery('2026-10-11'));
    }
}
```

- [ ] **Step 2: Run the tests and confirm they fail**

Run: `~/work/rob/standards-mcp/run-unit.sh "$PWD"`
Expected: FAIL, because `Resume\Repository` doesn't exist.

- [ ] **Step 3: Give `Query` its constructor**

Replace the class body of `classes/Resume/Query.php` (keep `isDate()` exactly as it is):

```php
/**
 * What to render: which moment, which wording, which audience.
 *
 * Dates are 'Y-m-d' strings. ISO dates compare correctly as strings, which
 * keeps every comparison identical in PHP, MySQL and SQLite.
 */
final class Query
{
    /**
     * @param list<string>      $tags          requested tag slugs, empty = the default résumé
     * @param list<string>|null $section_slugs sections in render order, null = all, by default order
     */
    public function __construct(
        public readonly string $as_of,
        public readonly string $wording_as_of,
        public readonly array $tags = [],
        public readonly ?array $section_slugs = null,
    ) {
    }

    // isDate() unchanged from Task 3
}
```

- [ ] **Step 4: Write the row classes**

`classes/Resume/SectionRow.php`:

```php
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
```

`classes/Resume/EntryRow.php`:

```php
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
```

`classes/Resume/TextRow.php`:

```php
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
```

`classes/Resume/ContactRow.php`:

```php
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
```

`classes/Resume/ResumeData.php`:

```php
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
```

- [ ] **Step 5: Write `classes/Resume/Repository.php`**

```php
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
```

- [ ] **Step 6: Run the tests and confirm they pass**

Run: `~/work/rob/standards-mcp/run-unit.sh "$PWD"`
Expected: PASS, with all 7 repository tests green and the earlier tests still green.

- [ ] **Step 7: Run phpcs and PHPStan, then commit**

```bash
~/work/rob/standards-mcp/run-phpcs.sh "$PWD" && ~/work/rob/standards-mcp/run-phpstan.sh "$PWD"
git add classes/Resume/Query.php classes/Resume/SectionRow.php classes/Resume/EntryRow.php classes/Resume/TextRow.php classes/Resume/ContactRow.php classes/Resume/ResumeData.php classes/Resume/Repository.php Tests/Unit/ResumeRepositoryTest.php
git commit -m "Read the resume tables into plain rows" -m "Plain SELECTs only, so the same code runs on MySQL and on the Unit suite's SQLite. Before the migration or the first import, the public query is null instead of a fatal." -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>" -m "Claude-Session: https://claude.ai/code/session_01RpuvXy513YWEEMR3uzrQ6g"
```

---

### Task 5: DateText and Query::fromRequest

**Files:**
- Create: `classes/Resume/DateText.php`
- Modify: `classes/Resume/Query.php` (add `fromRequest()`)
- Test: `Tests/Unit/ResumeDateTextTest.php`, `Tests/Unit/ResumeQueryTest.php`

**Interfaces:**
- Produces:
  - `DateText::one(?string $date, ?string $precision): string`
  - `DateText::range(?string $start, ?string $start_precision, ?string $end, ?string $end_precision, bool $ongoing): string`
  - `Query::fromRequest(array $get, string $today, list<string> $known_tags): Query`

- [ ] **Step 1: Write the failing tests**

`Tests/Unit/ResumeDateTextTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Resume\DateText;

class ResumeDateTextTest extends Unit
{
    public function testEachPrecision(): void
    {
        $this->assertSame('14 Jun 2007', DateText::one('2007-06-14', 'day'));
        $this->assertSame('Jun 2007', DateText::one('2007-06-14', 'month'));
        $this->assertSame('Jun 2007', DateText::one('2007-06-14', null));
        $this->assertSame('Fall 2002', DateText::one('2002-10-01', 'season'));
        $this->assertSame('Winter 2002', DateText::one('2002-12-01', 'season'));
        $this->assertSame('1995', DateText::one('1995-05-01', 'year'));
        $this->assertSame('', DateText::one(null, 'month'));
        $this->assertSame('', DateText::one('not-a-date', 'month'));
    }

    public function testClosedRange(): void
    {
        $this->assertSame('Aug 1999 – Jun 2002', DateText::range('1999-08-01', 'month', '2002-06-01', 'month', false));
    }

    public function testOngoingRangeSaysPresent(): void
    {
        $this->assertSame('Oct 2006 – Present', DateText::range('2006-10-01', 'month', null, null, true));
        $this->assertSame(
            'Oct 2006 – Present',
            DateText::range('2006-10-01', 'month', '2030-01-01', 'month', true),
            'ongoing wins over an end date after as_of'
        );
    }

    public function testSameStartAndEndPrintsOnce(): void
    {
        $this->assertSame('18 Sep 2021', DateText::range('2021-09-18', 'day', '2021-09-18', 'day', false));
        $this->assertSame('Fall 2002', DateText::range('2002-09-01', 'season', '2002-11-01', 'season', false));
    }

    public function testUndatedIsEmpty(): void
    {
        $this->assertSame('', DateText::range(null, null, null, null, false));
    }
}
```

`Tests/Unit/ResumeQueryTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Resume\Query;

class ResumeQueryTest extends Unit
{
    private const TODAY = '2026-10-11';
    private const KNOWN = ['market-au', 'programming', 'speaking'];

    public function testDefaultsToTodayAndNoTags(): void
    {
        $q = Query::fromRequest([], self::TODAY, self::KNOWN);
        $this->assertSame(self::TODAY, $q->as_of);
        $this->assertSame(self::TODAY, $q->wording_as_of);
        $this->assertSame([], $q->tags);
        $this->assertNull($q->section_slugs);
    }

    public function testValidValuesPassThrough(): void
    {
        $q = Query::fromRequest(
            ['as_of' => '2015-01-01', 'wording_as_of' => '2007-06-14', 'tags' => ['speaking', 'market-au']],
            self::TODAY,
            self::KNOWN
        );
        $this->assertSame('2015-01-01', $q->as_of);
        $this->assertSame('2007-06-14', $q->wording_as_of);
        $this->assertSame(['speaking', 'market-au'], $q->tags);
    }

    public function testJunkFallsBackInsteadOfErroring(): void
    {
        $q = Query::fromRequest(
            ['as_of' => '2021-02-30', 'wording_as_of' => ['x'], 'tags' => 'speaking'],
            self::TODAY,
            self::KNOWN
        );
        $this->assertSame(self::TODAY, $q->as_of, 'impossible date');
        $this->assertSame(self::TODAY, $q->wording_as_of, 'array instead of string');
        $this->assertSame([], $q->tags, 'string instead of array');
    }

    public function testUnknownAndRepeatedTagsAreDropped(): void
    {
        $q = Query::fromRequest(['tags' => ['speaking', 'nope', 'speaking', 7]], self::TODAY, self::KNOWN);
        $this->assertSame(['speaking'], $q->tags);
    }
}
```

- [ ] **Step 2: Run the tests and confirm they fail**

Run: `~/work/rob/standards-mcp/run-unit.sh "$PWD"`
Expected: FAIL, because `Resume\DateText` and `Query::fromRequest` don't exist.

- [ ] **Step 3: Write `classes/Resume/DateText.php`**

```php
<?php

declare(strict_types=1);

namespace Resume;

/**
 * Dates as a résumé prints them, months spelled out: "14 Jun 2007",
 * "Jun 2007", "Fall 2002", "2007", and ranges such as "Aug 1999 – Jun 2002".
 */
final class DateText
{
    private const SEASONS = [
        1 => 'Winter', 2 => 'Winter', 3 => 'Spring', 4 => 'Spring', 5 => 'Spring', 6 => 'Summer',
        7 => 'Summer', 8 => 'Summer', 9 => 'Fall', 10 => 'Fall', 11 => 'Fall', 12 => 'Winter',
    ];

    public static function one(?string $date, ?string $precision): string
    {
        if ($date === null || !Query::isDate($date)) {
            return '';
        }
        $when = new \DateTimeImmutable($date);
        return match ($precision) {
            'day' => $when->format('j M Y'),
            'season' => self::SEASONS[(int) $when->format('n')] . ' ' . $when->format('Y'),
            'year' => $when->format('Y'),
            default => $when->format('M Y'),
        };
    }

    /**
     * $ongoing wins over any end date. When start and end print the same,
     * as for a one-day talk, the date prints once.
     */
    public static function range(
        ?string $start,
        ?string $start_precision,
        ?string $end,
        ?string $end_precision,
        bool $ongoing,
    ): string {
        $from = self::one($start, $start_precision);
        if ($from === '') {
            return '';
        }
        if ($ongoing) {
            return $from . ' – Present';
        }
        $to = self::one($end, $end_precision);
        return ($to === '' || $to === $from) ? $from : $from . ' – ' . $to;
    }
}
```

- [ ] **Step 4: Add `fromRequest()` to `classes/Resume/Query.php`**

Add these methods inside the class, after the constructor:

```php
    /**
     * Builds a Query from GET parameters. Anything unparseable falls back to
     * $today, and unknown tags are dropped, so a hand-edited URL never errors.
     *
     * @param array<mixed> $get
     * @param list<string> $known_tags
     */
    public static function fromRequest(array $get, string $today, array $known_tags): self
    {
        $tags = [];
        $raw_tags = $get['tags'] ?? [];
        if (is_array($raw_tags)) {
            foreach ($raw_tags as $tag) {
                if (is_string($tag) && in_array($tag, $known_tags, true) && !in_array($tag, $tags, true)) {
                    $tags[] = $tag;
                }
            }
        }
        return new self(
            as_of: self::dateOr($get['as_of'] ?? null, $today),
            wording_as_of: self::dateOr($get['wording_as_of'] ?? null, $today),
            tags: $tags,
        );
    }

    private static function dateOr(mixed $value, string $fallback): string
    {
        return is_string($value) && self::isDate($value) ? $value : $fallback;
    }
```

- [ ] **Step 5: Run the tests and confirm they pass**

Run: `~/work/rob/standards-mcp/run-unit.sh "$PWD"`
Expected: PASS.

- [ ] **Step 6: Run phpcs and PHPStan, then commit**

```bash
~/work/rob/standards-mcp/run-phpcs.sh "$PWD" && ~/work/rob/standards-mcp/run-phpstan.sh "$PWD"
git add classes/Resume/DateText.php classes/Resume/Query.php Tests/Unit/ResumeDateTextTest.php Tests/Unit/ResumeQueryTest.php
git commit -m "Format resume dates and parse tailor requests" -m "Months are spelled out, and a one-day talk prints its date once. A hand-edited URL with an impossible date or an unknown tag falls back to the defaults instead of erroring." -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>" -m "Claude-Session: https://claude.ai/code/session_01RpuvXy513YWEEMR3uzrQ6g"
```

---

### Task 6: Builder (every selection rule in the spec)

**Files:**
- Create: `classes/Resume/RenderedEntry.php`, `RenderedSection.php`, `Document.php`, `Builder.php`
- Test: `Tests/Unit/ResumeBuilderTest.php`

**Interfaces:**
- Consumes: `ResumeData`, rows, `Query`, `DateText::range()`, `ResumeFixture::loadedPdo()`, `Repository::load()`.
- Produces:
  - `Builder::build(ResumeData $data, Query $query): Document`
  - `Document` with `contacts` (list<ContactRow>), `sections` (list<RenderedSection>) and `query` (Query)
  - `RenderedSection` with `slug`, `heading` and `entries` (list<RenderedEntry>)
  - `RenderedEntry` with `slug`, `title?`, `org_name?`, `summary?`, `url_label?`, `bullets`, `location?`, `dates`, `skills`, `links`, `children`

- [ ] **Step 1: Write the failing tests**

`Tests/Unit/ResumeBuilderTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Resume\Query;

class ResumeBuilderTest extends Unit
{
    private const TODAY = '2026-10-11';

    /**
     * @param list<string>      $tags
     * @param list<string>|null $sections
     */
    private function build(
        array $tags = [],
        string $as_of = self::TODAY,
        string $wording = self::TODAY,
        ?array $sections = null,
    ): \Resume\Document {
        $data = (new \Resume\Repository(ResumeFixture::loadedPdo()))->load();
        return (new \Resume\Builder())->build($data, new Query($as_of, $wording, $tags, $sections));
    }

    /** @return list<string> */
    private function sectionSlugs(\Resume\Document $doc): array
    {
        return array_map(static fn (\Resume\RenderedSection $s): string => $s->slug, $doc->sections);
    }

    private function entry(\Resume\Document $doc, string $slug): \Resume\RenderedEntry
    {
        foreach ($doc->sections as $section) {
            foreach ($section->entries as $entry) {
                if ($entry->slug === $slug) {
                    return $entry;
                }
            }
        }
        $this->fail("no rendered entry $slug");
    }

    /** @return list<string> */
    private function contactValues(\Resume\Document $doc): array
    {
        return array_map(static fn (\Resume\ContactRow $c): string => $c->value, $doc->contacts);
    }

    public function testDefaultResumeUsesIncludeByDefault(): void
    {
        $this->assertSame(['experience', 'education'], $this->sectionSlugs($this->build()));
    }

    public function testDefaultWordingSkipsTextsTaggedForOtherAudiences(): void
    {
        $acme = $this->entry($this->build(), 'acme');
        $this->assertSame('Senior Engineer', $acme->title);
        $this->assertSame('Acme & Sons', $acme->org_name, 'escaping is the template\'s job');
        $this->assertSame(['Wrote the <b>SQL</b>', 'Cut report time; from days to hours'], $acme->bullets);
        $this->assertSame('Apr 2010 – Jun 2020', $acme->dates);
        $this->assertSame('Springfield', $acme->location);
        $this->assertSame(['PHP', 'SQL'], $acme->skills);
    }

    public function testRequestedTagSelectsEntries(): void
    {
        $doc = $this->build(['speaking']);
        $this->assertSame(['speaking'], $this->sectionSlugs($doc));
    }

    public function testPointEventIsNotOngoing(): void
    {
        $this->assertSame('18 Sep 2021', $this->entry($this->build(['speaking']), 'talk')->dates);
    }

    public function testMatchingTaggedWordingBeatsUntagged(): void
    {
        $this->assertSame('Speaker-facing title', $this->entry($this->build(['programming', 'speaking']), 'acme')->title);
    }

    public function testEndedAfterAsOfShowsPresent(): void
    {
        $this->assertSame('Apr 2010 – Present', $this->entry($this->build([], '2015-01-01'), 'acme')->dates);
    }

    public function testNotYetStartedIsLeftOut(): void
    {
        $this->assertSame(['education'], $this->sectionSlugs($this->build([], '2009-12-31')));
    }

    public function testOldWordingDateGetsOldWording(): void
    {
        $this->assertSame('Programmer', $this->entry($this->build([], self::TODAY, '2015-01-01'), 'acme')->title);
    }

    public function testWordingBeforeAnyTextFallsBackToNearest(): void
    {
        $doc = $this->build([], self::TODAY, '2000-01-01');
        $this->assertSame('Programmer', $this->entry($doc, 'acme')->title, 'oldest wording');
        $this->assertSame('B.S. Computer Science', $this->entry($doc, 'uni')->title);
        $this->assertSame([], $this->entry($doc, 'acme')->bullets, 'bullets never fall back');
    }

    public function testChildRendersUnderItsParentOnly(): void
    {
        $uni = $this->entry($this->build(), 'uni');
        $this->assertCount(1, $uni->children);
        $this->assertSame('Recycling Chair', $uni->children[0]->title);
        $education = $this->build()->sections[1];
        $this->assertCount(1, $education->entries, 'the child is not also top-level');
    }

    public function testDefaultContactsHidePrivateExpiredAndUnrequested(): void
    {
        $this->assertSame(
            ['Pat Example', 'Springfield', 'pat@example.com', '555-0199'],
            $this->contactValues($this->build())
        );
    }

    public function testMarketTagAddsItsContact(): void
    {
        $this->assertContains('+1 555 0199', $this->contactValues($this->build(['market-au'])));
    }

    public function testOldAsOfShowsTheOldPhone(): void
    {
        $values = $this->contactValues($this->build([], '2005-01-01'));
        $this->assertContains('555-0100', $values);
        $this->assertNotContains('555-0199', $values);
    }

    public function testProfileSectionOrderWins(): void
    {
        $doc = $this->build(['speaking', 'programming'], self::TODAY, self::TODAY, ['speaking', 'experience']);
        $this->assertSame(['speaking', 'experience'], $this->sectionSlugs($doc));
    }
}
```

- [ ] **Step 2: Run the tests and confirm they fail**

Run: `~/work/rob/standards-mcp/run-unit.sh "$PWD"`
Expected: FAIL, because `Resume\Builder` doesn't exist.

- [ ] **Step 3: Write the output classes**

`classes/Resume/RenderedEntry.php`:

```php
<?php

declare(strict_types=1);

namespace Resume;

/**
 * One entry with its wording chosen and dates formatted. Strings are raw:
 * templates escape them.
 */
final class RenderedEntry
{
    /**
     * @param list<string> $bullets
     * @param list<string> $skills
     * @param list<array{url: string, kind: string|null}> $links
     * @param list<RenderedEntry> $children
     */
    public function __construct(
        public readonly string $slug,
        public readonly ?string $title,
        public readonly ?string $org_name,
        public readonly ?string $summary,
        public readonly ?string $url_label,
        public readonly array $bullets,
        public readonly ?string $location,
        public readonly string $dates,
        public readonly array $skills,
        public readonly array $links,
        public readonly array $children,
    ) {
    }
}
```

`classes/Resume/RenderedSection.php`:

```php
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
```

`classes/Resume/Document.php`:

```php
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
```

- [ ] **Step 4: Write `classes/Resume/Builder.php`**

```php
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
```

- [ ] **Step 5: Run the tests and confirm they pass**

Run: `~/work/rob/standards-mcp/run-unit.sh "$PWD"`
Expected: PASS, with all 14 builder tests green.

- [ ] **Step 6: Run phpcs and PHPStan, then commit**

```bash
~/work/rob/standards-mcp/run-phpcs.sh "$PWD" && ~/work/rob/standards-mcp/run-phpstan.sh "$PWD"
git add classes/Resume/RenderedEntry.php classes/Resume/RenderedSection.php classes/Resume/Document.php classes/Resume/Builder.php Tests/Unit/ResumeBuilderTest.php
git commit -m "Build a resume for any date and audience" -m "Every rule in the spec lives in one class: which entries appear as of a date, which wording each gets for an audience, and which contact details show. A view older than every wording falls back to the nearest one, so no title goes blank." -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>" -m "Claude-Session: https://claude.ai/code/session_01RpuvXy513YWEEMR3uzrQ6g"
```

---

### Task 7: Templates, print styles, PublishedPdf

**Files:**
- Create: `classes/Resume/PublishedPdf.php`
- Create: `templates/layout/resume.tpl.php`, `templates/resume/document.tpl.php`, `templates/resume/entry.tpl.php`, `templates/resume/tailor_form.tpl.php`
- Create: `wwwroot/css/resume.css`
- Modify: `.gitignore` (add `wwwroot/*.pdf`)
- Test: `Tests/Unit/ResumeTemplateTest.php`

**Interfaces:**
- Consumes: `Document`, `RenderedEntry`, `Query`, `ResumeFixture`.
- Produces:
  - `PublishedPdf::FILENAME` (`'Rob_Nugen_resume.pdf'`) and `PublishedPdf::url(string $wwwroot): ?string`
  - `templates/resume/document.tpl.php`, taking vars `document` (?Document) and `pdf_url` (?string)
  - `templates/resume/tailor_form.tpl.php`, taking vars `query`, `known_tags`, `profiles` and `profile_slug`
  - `templates/layout/resume.tpl.php`, taking vars `page_title`, `username` and `page_content`

- [ ] **Step 1: Write the failing tests**

`Tests/Unit/ResumeTemplateTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Resume\Query;

class ResumeTemplateTest extends Unit
{
    private function template(string $file): \View\Template
    {
        $config = new \Config\Config();
        $config->app_path = dirname(__DIR__, 2);
        $page = new \View\Template($config);
        $page->setTemplate($file);
        return $page;
    }

    /** @param list<string> $tags */
    private function render(array $tags = [], ?string $pdf_url = null): string
    {
        $data = (new \Resume\Repository(ResumeFixture::loadedPdo()))->load();
        $doc = (new \Resume\Builder())->build($data, new Query('2026-10-11', '2026-10-11', $tags));
        $page = $this->template('resume/document.tpl.php');
        $page->set('document', $doc);
        $page->set('pdf_url', $pdf_url);
        return $page->grabTheGoods();
    }

    public function testRendersNameEntriesAndSkills(): void
    {
        $html = $this->render();
        $this->assertStringContainsString('<h1>Pat Example</h1>', $html);
        $this->assertStringContainsString('Senior Engineer', $html);
        $this->assertStringContainsString('Technologies: PHP, SQL', $html);
        $this->assertStringContainsString('Recycling Chair', $html);
        $this->assertStringContainsString('Apr 2010 – Jun 2020', $html);
    }

    public function testEscapesEveryDatabaseString(): void
    {
        $html = $this->render();
        $this->assertStringContainsString('Acme &amp; Sons', $html);
        $this->assertStringContainsString('Wrote the &lt;b&gt;SQL&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>SQL</b>', $html);
    }

    public function testPrivateDataNeverRenders(): void
    {
        $html = $this->render(['programming', 'speaking', 'market-au']);
        $this->assertStringNotContainsString('1 Secret Lane', $html);
        $this->assertStringNotContainsString('secret reason', $html);
    }

    public function testLinksRenderOnlyForHttp(): void
    {
        $this->assertStringContainsString('href="https://example.com/talk"', $this->render(['speaking']));
    }

    public function testPdfLinkOnlyWhenPublished(): void
    {
        $this->assertStringNotContainsString('Download PDF', $this->render());
        $this->assertStringContainsString('href="/r.pdf" download', $this->render([], '/r.pdf'));
    }

    public function testNullDocumentSaysNotPublished(): void
    {
        $page = $this->template('resume/document.tpl.php');
        $page->set('document', null);
        $page->set('pdf_url', null);
        $this->assertStringContainsString('No résumé is published yet.', $page->grabTheGoods());
    }

    public function testLayoutShowsNavOnlyWhenLoggedIn(): void
    {
        $layout = $this->template('layout/resume.tpl.php');
        $layout->set('page_title', 'Résumé');
        $layout->set('username', '');
        $layout->set('page_content', 'x');
        $this->assertStringNotContainsString('/tailor/', $layout->grabTheGoods());

        $layout->set('username', 'rob');
        $this->assertStringContainsString('href="/tailor/"', $layout->grabTheGoods());
    }

    public function testTailorFormReflectsTheQuery(): void
    {
        $form = $this->template('resume/tailor_form.tpl.php');
        $form->set('query', new Query('2015-01-01', '2007-06-14', ['speaking']));
        $form->set('known_tags', ['programming', 'speaking']);
        $form->set('profiles', [['slug' => 'talks', 'title' => 'Speaking']]);
        $form->set('profile_slug', '');
        $html = $form->grabTheGoods();
        $this->assertStringContainsString('value="speaking" checked', $html);
        $this->assertStringNotContainsString('value="programming" checked', $html);
        $this->assertStringContainsString('name="as_of" value="2015-01-01"', $html);
        $this->assertStringContainsString('name="wording_as_of" value="2007-06-14"', $html);
    }

    public function testPublishedPdfUrlOnlyWhenTheFileExists(): void
    {
        $dir = sys_get_temp_dir() . '/resume-pdf-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $this->assertNull(\Resume\PublishedPdf::url($dir));
        touch($dir . '/' . \Resume\PublishedPdf::FILENAME);
        $this->assertSame('/' . \Resume\PublishedPdf::FILENAME, \Resume\PublishedPdf::url($dir));
        unlink($dir . '/' . \Resume\PublishedPdf::FILENAME);
        rmdir($dir);
    }
}
```

- [ ] **Step 2: Run the tests and confirm they fail**

Run: `~/work/rob/standards-mcp/run-unit.sh "$PWD"`
Expected: FAIL, because the templates are missing ("Error loading template") and `PublishedPdf` doesn't exist.

- [ ] **Step 3: Write `classes/Resume/PublishedPdf.php`**

```php
<?php

declare(strict_types=1);

namespace Resume;

/**
 * The public PDF. bin/publish_pdf.sh prints it on Rob's machine and uploads
 * it into wwwroot/ under this name. It is personal data, so it is gitignored.
 */
final class PublishedPdf
{
    /** bin/publish_pdf.sh uses the same name. Change both together. */
    public const FILENAME = 'Rob_Nugen_resume.pdf';

    /** The PDF's URL, or null until one has been published. */
    public static function url(string $wwwroot): ?string
    {
        return is_file($wwwroot . '/' . self::FILENAME) ? '/' . self::FILENAME : null;
    }
}
```

- [ ] **Step 4: Write `templates/layout/resume.tpl.php`**

```php
<?php
/**
 * Page shell for résumé pages: no site menu, so the page prints clean.
 * A logged-in visitor (Rob) gets a small nav that print hides.
 *
 * @var string $page_title
 * @var string $username
 * @var string $page_content
 */
$resume_logged_in = isset($username) && is_string($username) && $username !== '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title ?? 'Résumé') ?></title>
    <link rel="stylesheet" href="/css/<?= \Version\Site::SEMVER ?>/resume.css">
</head>
<body>
    <?php if ($resume_logged_in): ?>
        <nav class="ResumeNav ScreenOnly">
            <a href="/">Public résumé</a> |
            <a href="/tailor/">Tailor</a> |
            <a href="/admin/">Admin</a> |
            <a href="/logout/">Log out</a>
        </nav>
    <?php endif; ?>
    <?= $page_content ?>
</body>
</html>
```

- [ ] **Step 5: Write `templates/resume/document.tpl.php`**

```php
<?php
/**
 * One résumé. Every string from the database is escaped here.
 *
 * @var \Resume\Document|null $document
 * @var string|null $pdf_url
 */
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
?>
<main class="Resume">
<?php if ($document === null || $document->sections === []): ?>
    <p class="ResumeEmpty">No résumé is published yet.</p>
<?php else: ?>
    <header class="ResumeHeader">
        <?php foreach ($document->contacts as $contact): ?>
            <?php if ($contact->kind === 'name'): ?>
                <h1><?= $e($contact->value) ?></h1>
            <?php endif; ?>
        <?php endforeach; ?>
        <p class="ResumeContact">
            <?php foreach ($document->contacts as $contact): ?>
                <?php if ($contact->kind === 'name') {
                    continue;
                } ?>
                <span class="Contact Contact-<?= $e($contact->kind) ?>">
                    <?php if ($contact->kind === 'email'): ?>
                        <a href="mailto:<?= $e($contact->value) ?>"><?= $e($contact->value) ?></a>
                    <?php elseif ($contact->kind === 'url' && preg_match('#^https?://#i', $contact->value) === 1): ?>
                        <a href="<?= $e($contact->value) ?>"><?= $e($contact->value) ?></a>
                    <?php elseif ($contact->kind === 'whatsapp'): ?>
                        WhatsApp: <?= $e($contact->value) ?>
                    <?php else: ?>
                        <?= $e($contact->value) ?>
                    <?php endif; ?>
                </span>
            <?php endforeach; ?>
        </p>
        <?php if (($pdf_url ?? null) !== null): ?>
            <p class="ScreenOnly"><a href="<?= $e($pdf_url) ?>" download>Download PDF</a></p>
        <?php endif; ?>
    </header>
    <?php foreach ($document->sections as $section): ?>
        <section class="ResumeSection">
            <h2><?= $e($section->heading) ?></h2>
            <?php foreach ($section->entries as $entry): ?>
                <?php include __DIR__ . '/entry.tpl.php'; ?>
            <?php endforeach; ?>
        </section>
    <?php endforeach; ?>
<?php endif; ?>
</main>
```

- [ ] **Step 6: Write `templates/resume/entry.tpl.php`**

```php
<?php
/**
 * One entry, then its children. document.tpl.php includes this with $entry
 * and $e set, and it includes itself once for each child.
 *
 * @var \Resume\RenderedEntry $entry
 * @var \Closure(string): string $e
 */
?>
<article class="Entry">
    <div class="EntryHead">
        <span class="EntryOrg"><?= $e($entry->org_name ?? '') ?></span>
        <?php if ($entry->location !== null): ?>
            <span class="EntryLocation"><?= $e($entry->location) ?></span>
        <?php endif; ?>
        <span class="EntryDates"><?= $e($entry->dates) ?></span>
    </div>
    <?php if ($entry->title !== null): ?>
        <div class="EntryTitle"><?= $e($entry->title) ?></div>
    <?php endif; ?>
    <?php if ($entry->summary !== null): ?>
        <p class="EntrySummary"><?= $e($entry->summary) ?></p>
    <?php endif; ?>
    <?php if ($entry->bullets !== []): ?>
        <ul class="EntryBullets">
            <?php foreach ($entry->bullets as $bullet): ?>
                <li><?= $e($bullet) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php if ($entry->skills !== []): ?>
        <p class="EntrySkills">Technologies: <?= $e(implode(', ', $entry->skills)) ?></p>
    <?php endif; ?>
    <?php foreach ($entry->links as $link): ?>
        <?php if (preg_match('#^https?://#i', $link['url']) === 1): ?>
            <p class="EntryLink"><a href="<?= $e($link['url']) ?>"><?= $e($entry->url_label ?? $link['url']) ?></a></p>
        <?php endif; ?>
    <?php endforeach; ?>
    <?php if ($entry->children !== []): ?>
        <div class="EntryChildren">
            <?php foreach ($entry->children as $child): ?>
                <?php
                $entry_stack[] = $entry;
                $entry = $child;
                include __FILE__;
                $entry = array_pop($entry_stack);
                ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</article>
```

- [ ] **Step 7: Write `templates/resume/tailor_form.tpl.php`**

```php
<?php
/**
 * Rob's click-clack form. A plain GET, so every view is a bookmarkable URL
 * and no CSRF token is needed. A chosen preset overrides the boxes.
 *
 * @var \Resume\Query $query
 * @var list<string> $known_tags
 * @var list<array{slug: string, title: string}> $profiles
 * @var string $profile_slug
 */
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
?>
<form class="TailorForm ScreenOnly" method="get" action="/tailor/">
    <fieldset>
        <legend>Preset</legend>
        <select name="profile">
            <option value="">(custom: use the boxes below)</option>
            <?php foreach ($profiles as $profile): ?>
                <option value="<?= $e($profile['slug']) ?>"<?= $profile['slug'] === $profile_slug ? ' selected' : '' ?>>
                    <?= $e($profile['title']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </fieldset>
    <fieldset>
        <legend>Audience and market</legend>
        <?php foreach ($known_tags as $tag): ?>
            <label>
                <input type="checkbox" name="tags[]" value="<?= $e($tag) ?>"<?= in_array($tag, $query->tags, true) ? ' checked' : '' ?>>
                <?= $e($tag) ?>
            </label>
        <?php endforeach; ?>
    </fieldset>
    <fieldset>
        <legend>When</legend>
        <label>What had happened by <input type="date" name="as_of" value="<?= $e($query->as_of) ?>"></label>
        <label>Worded as on <input type="date" name="wording_as_of" value="<?= $e($query->wording_as_of) ?>"></label>
    </fieldset>
    <button type="submit">Show</button>
    <button type="button" onclick="window.print()">Print / save as PDF</button>
</form>
```

- [ ] **Step 8: Write `wwwroot/css/resume.css`**

```css
/*
 * One résumé look for screen and paper. Print drops everything marked
 * .ScreenOnly (nav, the tailor form, the download link).
 */
:root {
    --ink: #1d1d1d;
    --muted: #5a5a5a;
    --rule: #c9c9c9;
    --accent: #0b5394;
    --bg: #ffffff;
}

body {
    margin: 0;
    background: var(--bg);
    color: var(--ink);
    font: 11pt/1.45 Georgia, "Times New Roman", serif;
}

a { color: var(--accent); }

.ResumeNav, .TailorForm {
    max-width: 52rem;
    margin: 1rem auto;
    padding: 0 1rem;
    font-family: system-ui, sans-serif;
    font-size: 10pt;
}

.TailorForm fieldset { border: 1px solid var(--rule); margin: 0 0 .75rem; }
.TailorForm label { margin-right: 1rem; white-space: nowrap; }

.Resume { max-width: 52rem; margin: 0 auto 3rem; padding: 0 1rem; }
.ResumeEmpty { margin-top: 4rem; text-align: center; color: var(--muted); }

.ResumeHeader { text-align: center; border-bottom: 2px solid var(--ink); padding-bottom: .5rem; }
.ResumeHeader h1 { margin: .5rem 0 .25rem; font-size: 22pt; letter-spacing: .04em; }
.ResumeContact { margin: 0; color: var(--muted); }
.Contact + .Contact::before { content: " · "; }

.ResumeSection h2 {
    margin: 1.25rem 0 .5rem;
    font-size: 12pt;
    text-transform: uppercase;
    letter-spacing: .08em;
    border-bottom: 1px solid var(--rule);
}

.Entry { margin: 0 0 .9rem; break-inside: avoid; }
.EntryHead { display: flex; gap: 1rem; align-items: baseline; }
.EntryOrg { font-weight: bold; flex: 1; }
.EntryLocation { color: var(--muted); }
.EntryDates { color: var(--muted); white-space: nowrap; }
.EntryTitle { font-style: italic; }
.EntrySummary { margin: .25rem 0; }
.EntryBullets { margin: .25rem 0 .25rem 1.25rem; padding: 0; }
.EntrySkills, .EntryLink { margin: .2rem 0; font-size: 10pt; color: var(--muted); }
.EntryChildren { margin-left: 1.25rem; }

@media (max-width: 40rem) {
    .EntryHead { flex-wrap: wrap; gap: 0 .75rem; }
}

@page { margin: 15mm; }

@media print {
    .ScreenOnly { display: none !important; }
    body { font-size: 10.5pt; }
    .Resume { max-width: none; padding: 0; margin: 0; }
    a { color: var(--ink); text-decoration: none; }
}
```

- [ ] **Step 9: Ignore published PDFs**

Append to `.gitignore`:

```
# Published resume PDFs hold personal data. bin/publish_pdf.sh uploads them.
wwwroot/*.pdf
```

- [ ] **Step 10: Run the tests and confirm they pass**

Run: `~/work/rob/standards-mcp/run-unit.sh "$PWD"`
Expected: PASS, with all 9 template tests green.

- [ ] **Step 11: Run phpcs and PHPStan, then commit**

```bash
~/work/rob/standards-mcp/run-phpcs.sh "$PWD" && ~/work/rob/standards-mcp/run-phpstan.sh "$PWD"
git add classes/Resume/PublishedPdf.php templates/layout/resume.tpl.php templates/resume wwwroot/css/resume.css .gitignore Tests/Unit/ResumeTemplateTest.php
git commit -m "Render the resume for screen and print" -m "One look, decided once, so formatting stops being a per-version chore. Every database string is escaped, private rows never reach the page, and print hides the nav and form so Save as PDF comes out clean." -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>" -m "Claude-Session: https://claude.ai/code/session_01RpuvXy513YWEEMR3uzrQ6g"
```

---

### Task 8: Public page and the tailor page

**Files:**
- Modify: `wwwroot/index.php` (whole file)
- Create: `wwwroot/tailor/index.php`

**Interfaces:**
- Consumes:
  - `Repository::publicQuery()`, `load()`, `profileQuery()` and `profiles()`
  - `Builder::build()`
  - `Query::fromRequest()`
  - `PublishedPdf::url()`
  - the three templates from Task 7
- Produces: `GET /` (public résumé) and `GET /tailor/` (admin only).

These are entry scripts, so they have no unit tests of their own. Their logic is in the classes tested above. They are verified by PHPStan and phpcs here, and live in Task 11.

- [ ] **Step 1: Replace `wwwroot/index.php`**

```php
<?php

declare(strict_types=1);

# Must include here because DH runs FastCGI https://www.phind.com/search?cache=zfj8o8igbqvaj8cm91wp1b7k
# Extract DreamHost project root: /home/username/domain.com
preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

/**
 * Set up by prepend.php.
 *
 * @var \Config\Config $config
 * @var \PDO $mla_database
 * @var \Auth\IsLoggedIn $is_logged_in
 */

// The public résumé, for everyone: whatever profile the import marked public.
$today = (new \DateTimeImmutable())->format('Y-m-d');
$repository = new \Resume\Repository($mla_database);
$query = $repository->publicQuery($today);
$document = $query === null ? null : (new \Resume\Builder())->build($repository->load(), $query);

$inner = new \View\Template(config: $config);
$inner->setTemplate('resume/document.tpl.php');
$inner->set('document', $document);
$inner->set('pdf_url', \Resume\PublishedPdf::url(__DIR__));

# A Config.php written before $site_title existed should still render a page.
// @phpstan-ignore nullCoalesce.property (an older Config.php may not declare it)
$site_title = $config->site_title ?? 'Résumé';

$layout = new \View\Template(config: $config);
$layout->setTemplate('layout/resume.tpl.php');
$layout->set('page_title', $site_title);
$layout->set('username', $is_logged_in->isLoggedIn() ? $is_logged_in->getLoggedInUsername() : '');
$layout->set('page_content', $inner->grabTheGoods());
$layout->echoToScreen();
```

- [ ] **Step 2: Write `wwwroot/tailor/index.php`**

```php
<?php

declare(strict_types=1);

# Must include here because DH runs FastCGI https://www.phind.com/search?cache=zfj8o8igbqvaj8cm91wp1b7k
# Extract DreamHost project root: /home/username/domain.com
preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

/**
 * Set up by prepend.php.
 *
 * @var \Config\Config $config
 * @var \PDO $mla_database
 * @var \Auth\IsLoggedIn $is_logged_in
 */

// Rob's click-clack page: pick a preset, or tick audiences and set dates,
// then print. Read-only GET, so every view is a bookmarkable URL.
if (!$is_logged_in->isLoggedIn() || !$is_logged_in->isAdmin()) {
    header('Location: /login/');
    exit;
}

$today = (new \DateTimeImmutable())->format('Y-m-d');
$repository = new \Resume\Repository($mla_database);
$data = $repository->load();

$profile_slug = $_GET['profile'] ?? '';
$profile_slug = is_string($profile_slug) ? $profile_slug : '';
$query = ($profile_slug === '' ? null : $repository->profileQuery($profile_slug, $today))
    ?? \Resume\Query::fromRequest($_GET, $today, $data->known_tags);

$form = new \View\Template(config: $config);
$form->setTemplate('resume/tailor_form.tpl.php');
$form->set('query', $query);
$form->set('known_tags', $data->known_tags);
$form->set('profiles', $repository->profiles());
$form->set('profile_slug', $profile_slug);

$resume = new \View\Template(config: $config);
$resume->setTemplate('resume/document.tpl.php');
$resume->set('document', (new \Resume\Builder())->build($data, $query));
$resume->set('pdf_url', null);

$layout = new \View\Template(config: $config);
$layout->setTemplate('layout/resume.tpl.php');
$layout->set('page_title', 'Tailor résumé');
$layout->set('username', $is_logged_in->getLoggedInUsername());
$layout->set('page_content', $form->grabTheGoods() . $resume->grabTheGoods());
$layout->echoToScreen();
```

- [ ] **Step 3: Run all three gates**

Run: `~/work/rob/standards-mcp/run-unit.sh "$PWD" && ~/work/rob/standards-mcp/run-phpcs.sh "$PWD" && ~/work/rob/standards-mcp/run-phpstan.sh "$PWD"`
Expected: all pass. If PHPStan flags `$mla_database` in the `@var` block, check that `prepend.php` names it exactly `$mla_database`, which it does.

`TemplateTest::testWelcomeEscapesSiteTitle` still passes: `welcome.tpl.php` stays in the repo for the login flow and isn't touched.

- [ ] **Step 4: Commit**

```bash
git add wwwroot/index.php wwwroot/tailor/index.php
git commit -m "Serve the public resume and the tailor page" -m "The home page is now the public resume for everyone. Rob's tailor page turns presets, audiences and two dates into a resume he can print to PDF." -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>" -m "Claude-Session: https://claude.ai/code/session_01RpuvXy513YWEEMR3uzrQ6g"
```

---

### Task 9: CLI importer, PDF publisher, docs

**Files:**
- Create: `bin/import_resume.php`, `bin/publish_pdf.sh`
- Modify: `phpcs.xml` (add `<file>bin</file>`), `phpstan.neon` (add `- bin` under `paths`)
- Modify: `CLAUDE.md` (append a "Résumé data" section)
- Test: `Tests/Unit/ResumePublishScriptTest.php`

**Interfaces:**
- Consumes: `Importer`, `SqliteSchema`, `Database\Base::getPDO()`, `Config\Config`, `PublishedPdf::FILENAME`.
- Produces: `php bin/import_resume.php [--check] < doc.json` and `bin/publish_pdf.sh [ssh-host]`.

- [ ] **Step 1: Write the failing test**

`Tests/Unit/ResumePublishScriptTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;

/**
 * The shell script can't read the PHP constant, so this test keeps the two
 * names identical. A mismatch would upload a PDF the page never links to.
 */
class ResumePublishScriptTest extends Unit
{
    public function testScriptUploadsTheFileThePageLinks(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/bin/publish_pdf.sh');
        $this->assertIsString($script);
        $this->assertStringContainsString('FILENAME="' . \Resume\PublishedPdf::FILENAME . '"', $script);
    }
}
```

- [ ] **Step 2: Run the test and confirm it fails**

Run: `~/work/rob/standards-mcp/run-unit.sh "$PWD"`
Expected: FAIL, because `bin/publish_pdf.sh` is missing.

- [ ] **Step 3: Write `bin/import_resume.php`**

```php
<?php

declare(strict_types=1);

/**
 * Replaces the résumé tables with the JSON document on stdin. The JSON lives
 * in the private data repo, never in this one.
 *
 *   ssh resume 'php resume.robnugen.com/bin/import_resume.php' < resume.json
 *   php bin/import_resume.php --check < resume.json
 *
 * --check imports into a throwaway in-memory SQLite instead, so a document
 * can be validated anywhere with no Config.php and no database.
 */

require_once dirname(__DIR__) . '/classes/Mlaphp/Autoloader.php';
spl_autoload_register([new \Mlaphp\Autoloader(), 'load']);

$args = $_SERVER['argv'] ?? [];
$check = is_array($args) && in_array('--check', $args, true);

$json = stream_get_contents(STDIN);
if ($json === false || trim($json) === '') {
    fwrite(STDERR, "Nothing on stdin. Pipe the resume JSON in.\n");
    exit(1);
}

if ($check) {
    $pdo = new \PDO('sqlite::memory:');
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    \Resume\SqliteSchema::create($pdo);
} else {
    $pdo = \Database\Base::getPDO(new \Config\Config());
}

try {
    $counts = (new \Resume\Importer($pdo))->import($json);
} catch (\Resume\ImportException $e) {
    fwrite(STDERR, 'Not imported: ' . $e->getMessage() . "\n");
    exit(1);
}

foreach ($counts as $table => $count) {
    echo str_pad($table, 20) . $count . "\n";
}
echo $check ? "Valid. Nothing was written.\n" : "Imported.\n";
```

- [ ] **Step 4: Write `bin/publish_pdf.sh` and make it executable**

```bash
#!/usr/bin/env bash
# Prints the public resume to PDF with headless Chrome on this machine and
# uploads it into the site's wwwroot/, where the "Download PDF" link finds it.
# Run after every import. The PDF is personal data: gitignored, never committed.
#
#   bin/publish_pdf.sh            # ssh alias "resume"
#   bin/publish_pdf.sh otherhost
set -euo pipefail

HOST="${1:-resume}"
URL="https://resume.robnugen.com/"
FILENAME="Rob_Nugen_resume.pdf"   # must equal \Resume\PublishedPdf::FILENAME
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

google-chrome --headless=new --disable-gpu --no-pdf-header-footer \
    --print-to-pdf="$WORK/$FILENAME" "$URL"
test -s "$WORK/$FILENAME"
rsync -a "$WORK/$FILENAME" "$HOST:resume.robnugen.com/wwwroot/$FILENAME"
echo "Published $URL$FILENAME"
```

Run: `chmod +x bin/publish_pdf.sh`

- [ ] **Step 5: Bring `bin/` under the gates**

In `phpcs.xml`, add `<file>bin</file>` after `<file>Tests</file>`. In `phpstan.neon`, add `        - bin` under `paths:` after `- Tests`. phpcs only looks at `.php` files, so the `.sh` is ignored.

- [ ] **Step 6: Append the "Résumé data" section to `CLAUDE.md`**

```markdown
## Résumé data

- **Public code, private data.** The résumé itself is one JSON document in the
  private repo `~/work/rob/jobhunt/resume_private/` (`resume.json`). This repo never
  holds real résumé data, seed SQL, dumps or generated PDFs. Tests use the invented
  Pat Example in `Tests/Unit/ResumeFixture.php`, which is also the worked example of
  the JSON format (keys listed in the plan, `docs/superpowers/plans/2026-10-11-resume-builder.md`, Task 3).
- **Validate** without a database (runs anywhere the runner image is):

      docker run --rm -i --network none -v "$PWD":/app:ro -w /app \
        standards-codeception-runner:php8.3 php bin/import_resume.php --check \
        < ../resume_private/resume.json

- **Import** (replaces every résumé row in one transaction, so a bad document changes nothing):

      ssh resume 'php resume.robnugen.com/bin/import_resume.php' < ../resume_private/resume.json

- **Publish the PDF** after each import: `bin/publish_pdf.sh`. It prints the public page with
  headless Chrome on this machine and rsyncs `Rob_Nugen_resume.pdf` into the server's `wwwroot/`.
- **Tables** come from `db_schemas/03_resume/create_resume_tables.sql`, applied once from
  `/admin/migrate_tables.php`. The SQLite copy in `classes/Resume/SqliteSchema.php` must
  match it, and `ResumeSchemaFilesTest` fails if they drift.
- **Choosing** (which entries, which wording, which contacts) is all in `Resume\Builder`,
  per the spec. `/tailor/` (admin only) is the click-clack page.
- **One-day events** (a talk) set `ended_on` equal to `started_on`. A null `ended_on`
  means "ongoing" and prints "Present".
```

- [ ] **Step 7: Run all three gates, plus `--check` against the fixture**

Run: `~/work/rob/standards-mcp/run-unit.sh "$PWD" && ~/work/rob/standards-mcp/run-phpcs.sh "$PWD" && ~/work/rob/standards-mcp/run-phpstan.sh "$PWD"`
Expected: all pass.

Then dump the fixture's JSON (sanity check, invented data only):

```bash
docker run --rm -i --network none -v "$PWD":/app:ro -w /app standards-codeception-runner:php8.3 \
  php -r 'require "Tests/Unit/_bootstrap.php"; echo \Tests\Unit\ResumeFixture::json();' > /tmp/fixture.json
docker run --rm -i --network none -v "$PWD":/app:ro -w /app standards-codeception-runner:php8.3 \
  php bin/import_resume.php --check < /tmp/fixture.json
```

Expected: a table of counts ending in `Valid. Nothing was written.`

- [ ] **Step 8: Commit**

```bash
git add bin phpcs.xml phpstan.neon CLAUDE.md Tests/Unit/ResumePublishScriptTest.php
git commit -m "Add resume import and PDF publish scripts" -m "Import reads the private JSON on stdin, so the data never lands in this repo or on disk on the server. --check validates a document against SQLite with no database. The PDF is printed locally by headless Chrome, so the server needs no PDF library." -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>" -m "Claude-Session: https://claude.ai/code/session_01RpuvXy513YWEEMR3uzrQ6g"
```

---

### Task 10: Seed data (private repo, reviewed by Rob)

**Files:**
- Create: `~/work/rob/jobhunt/resume_private/resume.json` (**private repo, never this one**)

**Interfaces:**
- Consumes: `resume_private/history/*`, `resume_private/facts_settled.md`, the format from Task 3, and `bin/import_resume.php --check`.
- Produces: one valid document, committed to `resume_private`.

This task writes data, not code, so it has no TDD cycle. Its test is `--check`, followed by Rob's read-through.

- [ ] **Step 1: Draft the document from the sources**

Write `resume_private/resume.json` following these rules:
- **Entries:** one per job, role, talk, school, volunteer post, certification and language found in `history/`. Include the travel and other items with `"include_by_default": false`.
- **Dates:** settled facts in `facts_settled.md` override every past version. That gives Nova starting 2003-04, FSD starting 1999-08 (`month`), the AndBeyond child under sbstrm plus a top-level AndBeyond entry from 2021-06, and ist Associates with `ended_on` null.
- **One-day events:** `ended_on` equals `started_on`, with `day` precision when the day is known.
- **Wordings:** each wording is a text whose `written_on` is the `captured_on` of the first source it appears in, and whose `retired_on` is the `captured_on` of the first source that changed it. Add a `sources` entry for each history file, and point texts at it with `source`.
- **Private notes:** facts that must never print (why a project died, who owns ist Associates) go in `private_note`.
- **Contacts:** dated rows for each phone number and each address era. The street address is `is_private: true` unless Rob says otherwise.
- **Tags:** `programming`, `ai`, `speaking`, `leadership`, `publications`, `teaching`, `market-jp`, `market-au`.
- **Profiles:** `public` (`is_public: true`), `programming`, `speaking`, `leadership`.

- [ ] **Step 2: Validate**

```bash
cd ~/work/rob/jobhunt/resume.robnugen.com
docker run --rm -i --network none -v "$PWD":/app:ro -w /app standards-codeception-runner:php8.3 \
  php bin/import_resume.php --check < ../resume_private/resume.json
```

Expected: `Valid. Nothing was written.` Fix whatever entry or field it names and re-run until it passes.

- [ ] **Step 3: Commit in the private repo**

```bash
cd ~/work/rob/jobhunt/resume_private
git add resume.json
git commit -m "Seed the resume document from every past version" -m "Settled facts override the old versions. Each wording is dated by the version it first appeared in, so a 2007 or 2022 rebuild picks the words used then." -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>" -m "Claude-Session: https://claude.ai/code/session_01RpuvXy513YWEEMR3uzrQ6g"
```

- [ ] **Step 4: Rob reviews `resume.json`.** This is the gate for data accuracy. Apply his corrections, re-run Step 2, and commit again.

---

### Task 11: Close the bubble, deploy, verify live

**Files:** none (operations)

The gated steps are Rob's. Do them in this order.

- [ ] **Step 1: Close the bubble** with the `close-bubble` skill from `resume-builder`: `--dry-run` first, marker `DONE`, or Rob's exact message if he gives one. Never check out or merge `master`.
- [ ] **Step 2 (Rob): Advance `master` and push to `origin`.**
- [ ] **Step 3: Pull on the server.**

```bash
ssh resume 'cd resume.robnugen.com && git pull --ff-only && git log --oneline -1'
```

Expected: the DONE merge commit.

- [ ] **Step 4 (Rob, browser): Apply the migration.** Open `https://resume.robnugen.com/admin/migrate_tables.php` and apply `03_resume/create_resume_tables.sql`.
- [ ] **Step 5: Check the empty state, then import.**

```bash
curl -sS https://resume.robnugen.com/ | grep -c 'No résumé is published yet'   # expect 1
ssh resume 'php resume.robnugen.com/bin/import_resume.php' < ../resume_private/resume.json
curl -sS https://resume.robnugen.com/ | grep -c 'class="Entry"'               # expect > 10
```

- [ ] **Step 6: Publish the PDF and check it.**

```bash
bin/publish_pdf.sh
curl -sS -o /dev/null -w '%{http_code} %{content_type}\n' https://resume.robnugen.com/Rob_Nugen_resume.pdf
```

Expected: `200 application/pdf`. Open the PDF and check that the nav and download link are absent, there are no headers or footers, and the escaping looks right.

- [ ] **Step 7 (Rob): Try `/tailor/`.** Pick each preset, tick tags, try `as_of=2007-06-14` with `wording_as_of=2007-06-14`, and print one to PDF.
- [ ] **Step 8: Check that private data stays private.**

```bash
curl -sS https://resume.robnugen.com/.git/config -o /dev/null -w '%{http_code}\n'   # expect 404
cd ~/work/rob/jobhunt/resume.robnugen.com && git log --all -p | grep -cE '[0-9]{2,3}-[0-9]{4}-[0-9]{4}' # expect 0: no phone-number-shaped strings
```
