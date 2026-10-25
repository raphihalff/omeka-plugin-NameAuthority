<?php
echo head(array('title' => __('Delete name'), 'bodyclass' => 'name-authority name-authority-delete'));
?>
<p><?php echo __('Delete the name record "%s"? Item metadata is not changed; only this record and its forms are removed.', html_escape($name_authority->getDisplayName())); ?></p>
<form method="post" action="<?php echo html_escape(url('name-authority/index/delete/id/' . $name_authority->id)); ?>">
    <?php echo $csrf->getElement('csrf_token'); ?>
    <input type="submit" class="red button" value="<?php echo html_escape(__('Delete')); ?>">
    <a class="button" href="<?php echo html_escape(url('name-authority/index/edit/id/' . $name_authority->id)); ?>"><?php echo __('Cancel'); ?></a>
</form>
<?php echo foot(); ?>
