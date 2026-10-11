<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;

/**
 * The shell script can't read the PHP constant, so this test keeps the two
 * names identical. A mismatch would upload a PDF the page never links to.
 */
class ResumePublishScriptTest extends Unit
{
    public function testScriptUploadsTheFileThePageLinks(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/bin/publish_pdf.sh');
        $this->assertIsString($script);
        $this->assertStringContainsString('FILENAME="' . \Resume\PublishedPdf::FILENAME . '"', $script);
    }
}
