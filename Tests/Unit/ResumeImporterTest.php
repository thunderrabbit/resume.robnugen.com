<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;

class ResumeImporterTest extends Unit
{
    private function countRows(\PDO $pdo, string $sql): int
    {
        $stmt = $pdo->query($sql);
        $value = $stmt === false ? false : $stmt->fetchColumn();
        return is_int($value) ? $value : -1;
    }

    /**
     * Imports the fixture with one nested value replaced, e.g. ['entries', 0, 'section'].
     *
     * @param non-empty-list<string|int> $path
     */
    private function importEdited(\PDO $pdo, array $path, mixed $value): void
    {
        $doc = self::withValue(ResumeFixture::doc(), $path, $value);
        (new \Resume\Importer($pdo))->import(json_encode($doc, JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<mixed> $doc
     * @param non-empty-list<string|int> $path
     * @return array<mixed>
     */
    private static function withValue(array $doc, array $path, mixed $value): array
    {
        $key = array_shift($path);
        if ($path === []) {
            $doc[$key] = $value;
            return $doc;
        }
        $child = $doc[$key] ?? [];
        $doc[$key] = self::withValue(is_array($child) ? $child : [], $path, $value);
        return $doc;
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
        $this->assertSame(4, $this->countRows($pdo, 'SELECT COUNT(*) FROM entries'));
        $this->assertSame(9, $this->countRows($pdo, 'SELECT COUNT(*) FROM entry_texts'));
    }

    public function testParentIsLinkedBySlug(): void
    {
        $pdo = ResumeFixture::loadedPdo();
        $this->assertSame(
            $this->countRows($pdo, "SELECT entry_id FROM entries WHERE entry_slug = 'uni'"),
            $this->countRows($pdo, "SELECT parent_entry_id FROM entries WHERE entry_slug = 'uni-recycling'")
        );
    }

    public function testTextWithSemicolonsSurvivesWhole(): void
    {
        $pdo = ResumeFixture::loadedPdo();
        $this->assertSame(1, $this->countRows(
            $pdo,
            "SELECT COUNT(*) FROM entry_texts WHERE body = 'Cut report time; from days to hours'"
        ));
    }

    public function testBadDocumentIsRejectedAndOldDataKept(): void
    {
        $pdo = ResumeFixture::loadedPdo();
        try {
            $this->importEdited($pdo, ['entries', 0, 'section'], 'nope');
            $this->fail('expected ImportException');
        } catch (\Resume\ImportException $e) {
            $this->assertStringContainsString("entries[0]: unknown section 'nope'", $e->getMessage());
        }
        $this->assertSame(4, $this->countRows($pdo, 'SELECT COUNT(*) FROM entries'), 'rolled back');
    }

    public function testInvalidJsonIsRejected(): void
    {
        $this->expectException(\Resume\ImportException::class);
        (new \Resume\Importer(ResumeFixture::emptyPdo()))->import('{not json');
    }

    public function testImpossibleDateIsRejected(): void
    {
        $this->expectExceptionMessage("entries[1]: 'started_on' must be a real date");
        $this->importEdited(ResumeFixture::emptyPdo(), ['entries', 1, 'started_on'], '2021-02-30');
    }

    public function testDuplicateSlugIsRejected(): void
    {
        $this->expectExceptionMessage("entries[1]: duplicate 'acme'");
        $this->importEdited(ResumeFixture::emptyPdo(), ['entries', 1, 'slug'], 'acme');
    }

    public function testTwoPublicProfilesAreRejected(): void
    {
        $this->expectExceptionMessage('only one profile may be public');
        $this->importEdited(ResumeFixture::emptyPdo(), ['profiles', 1, 'is_public'], true);
    }

    public function testParentCycleIsRejected(): void
    {
        $this->expectExceptionMessage('is its own ancestor');
        $this->importEdited(ResumeFixture::emptyPdo(), ['entries', 2, 'parent'], 'uni-recycling');
    }

    public function testRetiredBeforeWrittenIsRejected(): void
    {
        $this->expectExceptionMessage("entries[0].texts[1]: retired before it was written");
        $this->importEdited(ResumeFixture::emptyPdo(), ['entries', 0, 'texts', 1, 'retired_on'], '2011-01-01');
    }

    public function testDuplicateSlugIgnoresCaseLikeMysql(): void
    {
        $this->expectExceptionMessage("entries[1]: duplicate 'ACME'");
        $this->importEdited(ResumeFixture::emptyPdo(), ['entries', 1, 'slug'], 'ACME');
    }

    public function testOverlongSlugIsRejectedBeforeMysqlWould(): void
    {
        $this->expectExceptionMessage("entries[1]: 'slug' must be at most 80 characters");
        $this->importEdited(ResumeFixture::emptyPdo(), ['entries', 1, 'slug'], str_repeat('x', 81));
    }

    public function testEndPrecisionDefaultsToStartPrecision(): void
    {
        $pdo = ResumeFixture::emptyPdo();
        $this->importEdited($pdo, ['entries', 1, 'ended_precision'], null);
        $stmt = $pdo->query("SELECT ended_precision FROM entries WHERE entry_slug = 'talk'");
        $this->assertNotFalse($stmt);
        $this->assertSame('day', $stmt->fetchColumn());
    }
}
