<?php
/**
 * One entry, then its children. document.tpl.php includes this with $entry
 * and $e set, and it includes itself once for each child.
 *
 * @var \Resume\RenderedEntry $entry
 * @var \Closure(string): string $e
 */
?>
<article class="Entry">
    <div class="EntryHead">
        <span class="EntryOrg"><?= $e($entry->org_name ?? '') ?></span>
        <?php if ($entry->location !== null): ?>
            <span class="EntryLocation"><?= $e($entry->location) ?></span>
        <?php endif; ?>
        <span class="EntryDates"><?= $e($entry->dates) ?></span>
    </div>
    <?php if ($entry->title !== null): ?>
        <div class="EntryTitle"><?= $e($entry->title) ?></div>
    <?php endif; ?>
    <?php if ($entry->summary !== null): ?>
        <p class="EntrySummary"><?= $e($entry->summary) ?></p>
    <?php endif; ?>
    <?php if ($entry->bullets !== []): ?>
        <ul class="EntryBullets">
            <?php foreach ($entry->bullets as $bullet): ?>
                <li><?= $e($bullet) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php if ($entry->skills !== []): ?>
        <p class="EntrySkills">Technologies: <?= $e(implode(', ', $entry->skills)) ?></p>
    <?php endif; ?>
    <?php foreach ($entry->links as $link): ?>
        <?php if (preg_match('#^https?://#i', $link['url']) === 1): ?>
            <p class="EntryLink"><a href="<?= $e($link['url']) ?>"><?= $e($entry->url_label ?? $link['url']) ?></a></p>
        <?php endif; ?>
    <?php endforeach; ?>
    <?php if ($entry->children !== []): ?>
        <div class="EntryChildren">
            <?php foreach ($entry->children as $child): ?>
                <?php
                $entry_stack[] = $entry;
                $entry = $child;
                include __FILE__;
                $entry = array_pop($entry_stack);
                ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</article>
