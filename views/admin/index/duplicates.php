<?php
$title = $only ? __('Possible duplicates of "%s"', $only->getDisplayName()) : __('Possible duplicates (%d)', $total_results);
echo head(array('title' => $title, 'bodyclass' => 'name-authority duplicates'));
echo flash();
$page = (int) Zend_Controller_Front::getInstance()->getRequest()->getParam('page', 1);
?>
<p class="name-authority-actions">
    <a class="button" href="<?php echo html_escape(url('name-authority/index/browse')); ?>"><?php echo __('Back to all names'); ?></a>
    <?php if ($only): ?><a class="button" href="<?php echo html_escape(url('name-authority/index/duplicates')); ?>"><?php echo __('All possible duplicates'); ?></a><?php endif; ?>
</p>
<p class="explanation"><?php echo __('Pairs of name records that may be the same person. Merging keeps one record and adds the other\'s names to it (as forms for missing languages, otherwise as search variants); item metadata is not changed. "Not the same" hides the pair for good.'); ?></p>

<?php if (!$pairs): ?>
    <p><?php echo __('No possible duplicates.'); ?></p>
<?php else: ?>
    <div class="name-authority-pager"><?php echo pagination_links(); ?></div>
    <?php foreach ($pairs as $pair): ?>
        <?php echo $this->partial('index/pair.php', array('pair' => $pair, 'csrf' => $csrf, 'return' => 'duplicates',
            'only' => $only ? $only->id : null, 'page' => $page > 1 ? $page : null)); ?>
    <?php endforeach; ?>
    <div class="name-authority-pager"><?php echo pagination_links(); ?></div>
<?php endif; ?>
<?php echo foot(); ?>
