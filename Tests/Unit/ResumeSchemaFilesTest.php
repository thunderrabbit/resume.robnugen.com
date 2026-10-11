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
        return $m[1];
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
        // Comments may name tables in prose, so only the SQL itself is scanned.
        $sql = (string) preg_replace('/--[^\n]*/', '', $this->sql());
        foreach (preg_split('/;\s*\n/', $sql) ?: [] as $statement) {
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
        $actual = array_map(
            static fn (mixed $n): string => is_string($n) ? $n : '',
            $stmt->fetchAll(\PDO::FETCH_COLUMN)
        );
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
