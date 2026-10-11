<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Resume\Query;

class ResumeTemplateTest extends Unit
{
    private function template(string $file): \View\Template
    {
        $config = new \Config\Config();
        $config->app_path = dirname(__DIR__, 2);
        $page = new \View\Template($config);
        $page->setTemplate($file);
        return $page;
    }

    /** @param list<string> $tags */
    private function render(array $tags = [], ?string $pdf_url = null): string
    {
        $data = (new \Resume\Repository(ResumeFixture::loadedPdo()))->load();
        $doc = (new \Resume\Builder())->build($data, new Query('2026-10-11', '2026-10-11', $tags));
        $page = $this->template('resume/document.tpl.php');
        $page->set('document', $doc);
        $page->set('pdf_url', $pdf_url);
        return $page->grabTheGoods();
    }

    public function testRendersNameEntriesAndSkills(): void
    {
        $html = $this->render();
        $this->assertStringContainsString('<h1>Pat Example</h1>', $html);
        $this->assertStringContainsString('Senior Engineer', $html);
        $this->assertStringContainsString('Technologies: PHP, SQL', $html);
        $this->assertStringContainsString('Recycling Chair', $html);
        $this->assertStringContainsString('Apr 2010 – Jun 2020', $html);
    }

    public function testEscapesEveryDatabaseString(): void
    {
        $html = $this->render();
        $this->assertStringContainsString('Acme &amp; Sons', $html);
        $this->assertStringContainsString('Wrote the &lt;b&gt;SQL&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>SQL</b>', $html);
    }

    public function testPrivateDataNeverRenders(): void
    {
        $html = $this->render(['programming', 'speaking', 'market-au']);
        $this->assertStringNotContainsString('1 Secret Lane', $html);
        $this->assertStringNotContainsString('secret reason', $html);
    }

    public function testLinksRenderOnlyForHttp(): void
    {
        $this->assertStringContainsString('href="https://example.com/talk"', $this->render(['speaking']));
    }

    public function testPdfLinkOnlyWhenPublished(): void
    {
        $this->assertStringNotContainsString('Download PDF', $this->render());
        $this->assertStringContainsString('href="/r.pdf" download', $this->render([], '/r.pdf'));
    }

    public function testNullDocumentSaysNotPublished(): void
    {
        $page = $this->template('resume/document.tpl.php');
        $page->set('document', null);
        $page->set('pdf_url', null);
        $this->assertStringContainsString('No résumé is published yet.', $page->grabTheGoods());
    }

    public function testLayoutShowsNavOnlyWhenLoggedIn(): void
    {
        $layout = $this->template('layout/resume.tpl.php');
        $layout->set('page_title', 'Résumé');
        $layout->set('username', '');
        $layout->set('page_content', 'x');
        $this->assertStringNotContainsString('/tailor/', $layout->grabTheGoods());

        $layout->set('username', 'rob');
        $this->assertStringContainsString('href="/tailor/"', $layout->grabTheGoods());
    }

    public function testTailorFormReflectsTheQuery(): void
    {
        $form = $this->template('resume/tailor_form.tpl.php');
        $form->set('query', new Query('2015-01-01', '2007-06-14', ['speaking']));
        $form->set('known_tags', ['programming', 'speaking']);
        $form->set('profiles', [['slug' => 'talks', 'title' => 'Speaking']]);
        $form->set('profile_slug', '');
        $html = $form->grabTheGoods();
        $this->assertStringContainsString('value="speaking" checked', $html);
        $this->assertStringNotContainsString('value="programming" checked', $html);
        $this->assertStringContainsString('name="as_of" value="2015-01-01"', $html);
        $this->assertStringContainsString('name="wording_as_of" value="2007-06-14"', $html);
    }

    public function testPublishedPdfUrlOnlyWhenTheFileExists(): void
    {
        $dir = sys_get_temp_dir() . '/resume-pdf-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $this->assertNull(\Resume\PublishedPdf::url($dir));
        touch($dir . '/' . \Resume\PublishedPdf::FILENAME);
        $this->assertSame('/' . \Resume\PublishedPdf::FILENAME, \Resume\PublishedPdf::url($dir));
        unlink($dir . '/' . \Resume\PublishedPdf::FILENAME);
        rmdir($dir);
    }
}
