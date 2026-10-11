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
