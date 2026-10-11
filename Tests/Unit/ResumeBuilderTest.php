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
        $doc = $this->build(['programming', 'speaking']);
        $this->assertSame('Speaker-facing title', $this->entry($doc, 'acme')->title);
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
