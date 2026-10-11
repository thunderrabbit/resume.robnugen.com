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
