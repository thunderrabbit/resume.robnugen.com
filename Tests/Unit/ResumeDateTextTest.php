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
