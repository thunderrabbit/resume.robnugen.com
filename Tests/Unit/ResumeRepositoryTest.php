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
        $this->assertSame(
            [['url' => 'https://example.com/talk', 'kind' => 'video']],
            $this->entry($data, 'talk')->links
        );
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
