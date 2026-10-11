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
