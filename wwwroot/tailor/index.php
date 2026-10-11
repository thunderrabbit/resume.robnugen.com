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

// Rob's click-clack page: pick a preset, or tick audiences and set dates,
// then print. Read-only GET, so every view is a bookmarkable URL.
if (!$is_logged_in->isLoggedIn() || !$is_logged_in->isAdmin()) {
    header('Location: /login/');
    exit;
}

$today = (new \DateTimeImmutable())->format('Y-m-d');
$repository = new \Resume\Repository($mla_database);
$data = $repository->load();

$profile_slug = $_GET['profile'] ?? '';
$profile_slug = is_string($profile_slug) ? $profile_slug : '';
$query = ($profile_slug === '' ? null : $repository->profileQuery($profile_slug, $today))
    ?? \Resume\Query::fromRequest($_GET, $today, $data->known_tags);

$form = new \View\Template(config: $config);
$form->setTemplate('resume/tailor_form.tpl.php');
$form->set('query', $query);
$form->set('known_tags', $data->known_tags);
$form->set('profiles', $repository->profiles());
$form->set('profile_slug', $profile_slug);

$resume = new \View\Template(config: $config);
$resume->setTemplate('resume/document.tpl.php');
$resume->set('document', (new \Resume\Builder())->build($data, $query));
$resume->set('pdf_url', null);

$layout = new \View\Template(config: $config);
$layout->setTemplate('layout/resume.tpl.php');
$layout->set('page_title', 'Tailor résumé');
$layout->set('username', $is_logged_in->getLoggedInUsername());
$layout->set('page_content', $form->grabTheGoods() . $resume->grabTheGoods());
$layout->echoToScreen();
