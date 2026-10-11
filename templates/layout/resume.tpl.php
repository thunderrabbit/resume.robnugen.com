<?php
/**
 * Page shell for résumé pages: no site menu, so the page prints clean.
 * A logged-in visitor (Rob) gets a small nav that print hides.
 *
 * @var string $page_title
 * @var string $username
 * @var string $page_content
 */
$resume_logged_in = isset($username) && is_string($username) && $username !== '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title ?? 'Résumé') ?></title>
    <link rel="stylesheet" href="/css/<?= \Version\Site::SEMVER ?>/resume.css">
</head>
<body>
    <?php if ($resume_logged_in): ?>
        <nav class="ResumeNav ScreenOnly">
            <a href="/">Public résumé</a> |
            <a href="/tailor/">Tailor</a> |
            <a href="/admin/">Admin</a> |
            <a href="/logout/">Log out</a>
        </nav>
    <?php endif; ?>
    <?= $page_content ?>
</body>
</html>
