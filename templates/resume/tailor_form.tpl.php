<?php
/**
 * Rob's click-clack form. A plain GET, so every view is a bookmarkable URL
 * and no CSRF token is needed. A chosen preset overrides the boxes.
 *
 * @var \Resume\Query $query
 * @var list<string> $known_tags
 * @var list<array{slug: string, title: string}> $profiles
 * @var string $profile_slug
 */
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
?>
<form class="TailorForm ScreenOnly" method="get" action="/tailor/">
    <fieldset>
        <legend>Preset</legend>
        <select name="profile">
            <option value="">(custom: use the boxes below)</option>
            <?php foreach ($profiles as $profile): ?>
                <option value="<?= $e($profile['slug']) ?>"<?= $profile['slug'] === $profile_slug ? ' selected' : '' ?>>
                    <?= $e($profile['title']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </fieldset>
    <fieldset>
        <legend>Audience and market</legend>
        <?php foreach ($known_tags as $tag): ?>
            <label>
                <input type="checkbox" name="tags[]" value="<?= $e($tag) ?>"<?= in_array($tag, $query->tags, true) ? ' checked' : '' ?>>
                <?= $e($tag) ?>
            </label>
        <?php endforeach; ?>
    </fieldset>
    <fieldset>
        <legend>When</legend>
        <label>What had happened by <input type="date" name="as_of" value="<?= $e($query->as_of) ?>"></label>
        <label>Worded as on <input type="date" name="wording_as_of" value="<?= $e($query->wording_as_of) ?>"></label>
    </fieldset>
    <button type="submit">Show</button>
    <button type="button" onclick="window.print()">Print / save as PDF</button>
</form>
