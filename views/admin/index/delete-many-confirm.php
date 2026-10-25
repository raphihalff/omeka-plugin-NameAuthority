<?php
echo head(array('title' => __('Delete %d name records', count($records)), 'bodyclass' => 'name-authority name-authority-delete'));
echo flash();
?>
<p><?php echo __('Delete these name records? Item metadata is not changed; only the records and their forms and variants are removed.'); ?></p>
<ul class="name-authority-delete-list">
<?php foreach ($records as $r): $n = count(NameAuthorityPlugin::instanceItemIds($r->id)); ?>
    <li><span dir="auto"><?php echo html_escape($r->getDisplayName()); ?></span>
        <span class="name-authority-id">#<?php echo (int) $r->id; ?> · <?php echo __(plural('%d item', '%d items', $n), $n); ?></span></li>
<?php endforeach; ?>
</ul>
<form method="post" action="<?php echo html_escape(url('name-authority/index/delete-many')); ?>">
    <?php echo $csrf->getElement('csrf_token'); ?>
    <?php foreach ($records as $r): ?><input type="hidden" name="ids[]" value="<?php echo (int) $r->id; ?>"><?php endforeach; ?>
    <input type="submit" class="red button" value="<?php echo html_escape(__(plural('Delete %d record', 'Delete %d records', count($records)), count($records))); ?>">
    <a class="button" href="<?php echo html_escape(url('name-authority/index/browse')); ?>"><?php echo __('Cancel'); ?></a>
</form>
<?php echo foot(); ?>
