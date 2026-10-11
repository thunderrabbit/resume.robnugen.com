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
