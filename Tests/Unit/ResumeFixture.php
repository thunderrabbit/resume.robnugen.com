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
