<?php

declare(strict_types=1);

# Must include here because DH runs FastCGI https://www.phind.com/search?cache=zfj8o8igbqvaj8cm91wp1b7k
# Extract DreamHost project root: /home/username/domain.com
preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

/**
 * Set up by prepend.php.
 *
 * @var \Config\Config $config
 * @var \PDO $mla_database
 * @var \Auth\IsLoggedIn $is_logged_in
 */

// The public résumé, for everyone: whatever profile the import marked public.
$today = (new \DateTimeImmutable())->format('Y-m-d');
$repository = new \Resume\Repository($mla_database);
$query = $repository->publicQuery($today);
$document = $query === null ? null : (new \Resume\Builder())->build($repository->load(), $query);

$inner = new \View\Template(config: $config);
$inner->setTemplate('resume/document.tpl.php');
$inner->set('document', $document);
$inner->set('pdf_url', \Resume\PublishedPdf::url(__DIR__));

# A Config.php written before $site_title existed should still render a page.
// @phpstan-ignore nullCoalesce.property (an older Config.php may not declare it)
$site_title = $config->site_title ?? 'Résumé';

$layout = new \View\Template(config: $config);
$layout->setTemplate('layout/resume.tpl.php');
$layout->set('page_title', $site_title);
$layout->set('username', $is_logged_in->isLoggedIn() ? $is_logged_in->getLoggedInUsername() : '');
$layout->set('page_content', $inner->grabTheGoods());
$layout->echoToScreen();
