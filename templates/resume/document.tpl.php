<?php
/**
 * One résumé. Every string from the database is escaped here.
 *
 * @var \Resume\Document|null $document
 * @var string|null $pdf_url
 */
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
?>
<main class="Resume">
<?php if ($document === null || $document->sections === []): ?>
    <p class="ResumeEmpty">No résumé is published yet.</p>
<?php else: ?>
    <header class="ResumeHeader">
        <?php foreach ($document->contacts as $contact): ?>
            <?php if ($contact->kind === 'name'): ?>
                <h1><?= $e($contact->value) ?></h1>
            <?php endif; ?>
        <?php endforeach; ?>
        <p class="ResumeContact">
            <?php foreach ($document->contacts as $contact): ?>
                <?php if ($contact->kind === 'name') {
                    continue;
                } ?>
                <span class="Contact Contact-<?= $e($contact->kind) ?>">
                    <?php if ($contact->kind === 'email'): ?>
                        <a href="mailto:<?= $e($contact->value) ?>"><?= $e($contact->value) ?></a>
                    <?php elseif ($contact->kind === 'url' && preg_match('#^https?://#i', $contact->value) === 1): ?>
                        <a href="<?= $e($contact->value) ?>"><?= $e($contact->value) ?></a>
                    <?php elseif ($contact->kind === 'whatsapp'): ?>
                        WhatsApp: <?= $e($contact->value) ?>
                    <?php else: ?>
                        <?= $e($contact->value) ?>
                    <?php endif; ?>
                </span>
            <?php endforeach; ?>
        </p>
        <?php if (($pdf_url ?? null) !== null): ?>
            <p class="ScreenOnly"><a href="<?= $e($pdf_url) ?>" download>Download PDF</a></p>
        <?php endif; ?>
    </header>
    <?php foreach ($document->sections as $section): ?>
        <section class="ResumeSection">
            <h2><?= $e($section->heading) ?></h2>
            <?php foreach ($section->entries as $entry): ?>
                <?php include __DIR__ . '/entry.tpl.php'; ?>
            <?php endforeach; ?>
        </section>
    <?php endforeach; ?>
<?php endif; ?>
</main>
