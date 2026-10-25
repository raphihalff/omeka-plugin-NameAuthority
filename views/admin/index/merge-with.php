<?php
$base = 'name-authority/index/';
echo head(array('title' => __('Merge "%s" with another record', $record->getDisplayName()), 'bodyclass' => 'name-authority merge-with'));
echo flash();
?>
<p class="name-authority-actions">
    <a class="button" href="<?php echo html_escape(url($base . 'edit/id/' . $record->id)); ?>"><?php echo __('Back to this record'); ?></a>
    <a class="button" href="<?php echo html_escape(url($base . 'browse')); ?>"><?php echo __('Back to all names'); ?></a>
</p>

<?php if ($pair): ?>
    <p class="explanation"><?php echo __('Choose which record to keep. The other record\'s names are added to it (as forms for languages it lacks, otherwise as search variants), with its VIAF / LC IDs and notes; then the other record is deleted. Item metadata is not changed.'); ?></p>
    <?php echo $this->partial('index/pair.php', array('pair' => $pair, 'csrf' => $csrf, 'return' => 'edit', 'returnId' => $record->id)); ?>
    <p><a href="<?php echo html_escape(url($base . 'merge-with', array('id' => $record->id))); ?>"><?php echo __('Pick a different record'); ?></a></p>
<?php else: ?>
    <form method="get" class="name-authority-filter">
        <input type="hidden" name="id" value="<?php echo (int) $record->id; ?>">
        <input type="text" name="q" value="<?php echo html_escape($q); ?>" dir="auto" autofocus
            placeholder="<?php echo html_escape(__('Find the other record by any name, in any language')); ?>">
        <button type="submit" class="button"><?php echo __('Search'); ?></button>
    </form>
    <?php if ($q !== ''): ?>
        <?php if (!$results): ?>
            <p><?php echo __('No other record has a name containing "%s".', html_escape($q)); ?></p>
        <?php else: ?>
        <table class="name-authority-table">
            <thead><tr>
                <th scope="col"><?php echo __('Authoritative name'); ?></th>
                <th scope="col"><?php echo __('Forms by language'); ?></th>
                <th scope="col"><?php echo __('Items'); ?></th>
                <th scope="col"></th>
            </tr></thead>
            <tbody>
            <?php foreach ($results as $r): $n = count(NameAuthorityPlugin::instanceItemIds($r->id)); ?>
                <tr>
                    <td dir="auto"><?php echo $r->authoritative !== '' ? html_escape($r->authoritative) : '<em>' . __('(no authoritative name)') . '</em>'; ?> <span class="name-authority-id">#<?php echo (int) $r->id; ?></span></td>
                    <td><?php foreach ($r->getLanguageForms() as $f): ?>
                        <div><span class="name-authority-lang"><?php echo html_escape($f['language']); ?></span> <span dir="auto"><?php echo html_escape($f['text']); ?></span></div>
                    <?php endforeach; ?></td>
                    <td><?php echo $n; ?></td>
                    <td><a class="button small" href="<?php echo html_escape(url($base . 'merge-with', array('id' => $record->id, 'other' => $r->id))); ?>"><?php echo __('Compare'); ?></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>
<?php echo foot(); ?>
