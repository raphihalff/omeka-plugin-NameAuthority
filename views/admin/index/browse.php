<?php
$base = 'name-authority/index/';
echo head(array('title' => __('Name Authority (%s)', $grand_total), 'bodyclass' => 'name-authority browse'));
echo flash();
?>
<div class="name-authority-actions">
    <a class="button green" href="<?php echo html_escape(url($base . 'add')); ?>"><?php echo __('Add a name'); ?></a>
    <a class="button" href="<?php echo html_escape(url($base . 'import')); ?>"><?php echo __('Import TSV'); ?></a>
    <a class="button" href="<?php echo html_escape(url($base . 'export')); ?>"><?php echo __('Export TSV'); ?></a>
    <form method="post" action="<?php echo html_escape(url($base . 'reindex')); ?>" class="name-authority-inline">
        <?php echo (new Omeka_Form_SessionCsrf)->getElement('csrf_token'); ?>
        <button type="submit" class="button blue"><?php echo __('Re-index search'); ?></button>
    </form>
    <a class="button<?php echo $duplicate_total ? ' red' : ''; ?>" href="<?php echo html_escape(url($base . 'duplicates')); ?>"><?php echo __('Possible duplicates (%d)', $duplicate_total); ?></a>
</div>

<?php
$browse = function (array $params) use ($base) {
    return html_escape(url($base . 'browse', $params));
};
$tile = function ($n, $label, array $params = null) use ($browse) {
    $num = number_format($n);
    $inner = '<span class="name-authority-stat-n">' . $num . '</span> <span class="name-authority-stat-l">' . $label . '</span>';
    return '<li>' . ($params !== null && $n ? '<a href="' . $browse($params) . '">' . $inner . '</a>' : $inner) . '</li>';
};
$pct = function ($n) use ($stats) {
    return $stats['total'] ? ' <span class="name-authority-stat-pct">' . round(100 * $n / $stats['total']) . '%</span>' : '';
};
?>
<section class="name-authority-stats" aria-label="<?php echo html_escape(__('Overview')); ?>">
    <div class="name-authority-stat-group">
        <h2><?php echo __('Records'); ?></h2>
        <ul>
            <?php echo $tile($stats['total'], __('name records'), array()); ?>
            <?php echo $tile($stats['no_auth'], __('without an authoritative name') . $pct($stats['no_auth']), array('missing_authoritative' => 1)); ?>
            <?php echo $tile($stats['viaf'], __('with a VIAF ID') . $pct($stats['viaf']), array('has_viaf' => 1)); ?>
            <?php echo $tile($stats['lc'], __('with an LC ID') . $pct($stats['lc']), array('has_lc' => 1)); ?>
            <?php echo $tile($stats['unused'], __('not on any item'), array('unused' => 1)); ?>
        </ul>
    </div>
    <div class="name-authority-stat-group">
        <h2><?php echo __('Languages'); ?></h2>
        <ul>
            <?php echo $tile($stats['langs']['1'], __('monolingual') . $pct($stats['langs']['1']), array('langs' => '1')); ?>
            <?php echo $tile($stats['langs']['2'], __('in two languages'), array('langs' => '2')); ?>
            <?php echo $tile($stats['langs']['3+'], __('in three or more'), array('langs' => '3+')); ?>
            <?php echo $tile($stats['langs']['0'], __('authoritative name only'), array('langs' => '0')); ?>
        </ul>
        <p class="name-authority-stat-langs">
            <?php $parts = array(); foreach ($stats['by_language'] as $lang => $n) {
                $parts[] = '<a href="' . $browse(array('language' => $lang)) . '">' . html_escape($lang) . ' ' . number_format($n) . '</a>';
            } echo implode(' · ', $parts); ?>
        </p>
    </div>
    <div class="name-authority-stat-group">
        <h2><?php echo __('Searching and upkeep'); ?></h2>
        <ul>
            <?php echo $tile($stats['variants'], __('search variants, in %s records', number_format($stats['with_variants'])), array('has_variants' => 1)); ?>
            <?php echo '<li><a href="' . html_escape(url($base . 'duplicates')) . '"><span class="name-authority-stat-n">' . number_format($stats['duplicates']) . '</span> <span class="name-authority-stat-l">' . __('possible duplicates') . '</span></a></li>'; ?>
            <?php echo '<li><a href="' . html_escape(url($base . 'unmatched')) . '"><span class="name-authority-stat-n">' . number_format($stats['unmatched_values']) . '</span> <span class="name-authority-stat-l">' . __('names with no record (on %s items)', number_format($stats['unmatched_items'])) . '</span></a></li>'; ?>
<?php if (NameAuthorityPlugin::topicElementIds()): ?><?php echo '<li><a href="' . html_escape(url($base . 'unmatched', array('subjects' => 1))) . '"><span class="name-authority-stat-n">' . number_format($stats['unmatched_subject']) . '</span> <span class="name-authority-stat-l">' . __('topics with no record (on %s items)', number_format($stats['unmatched_subject_items'])) . '</span></a></li>'; ?><?php endif; ?>
            <?php if ($topicNames = NameAuthorityPlugin::elementNames(NameAuthorityPlugin::topicElementIds())): ?>
            <?php echo $tile($stats['subject_only'], __('records used only in %s', html_escape(implode(', ', $topicNames))), array('subject_only' => 1)); ?>
            <?php endif; ?>
        </ul>
    </div>
</section>

<form method="get" class="name-authority-filter">
    <input type="text" name="q" value="<?php echo html_escape((string) $q); ?>" placeholder="<?php echo html_escape(__('Find a name in any language or spelling')); ?>" dir="auto">
    <?php foreach ($active_filters as $key => $label): if ($key === 'missing_authoritative') { continue; } ?>
        <input type="hidden" name="<?php echo html_escape($key); ?>" value="<?php echo html_escape(Zend_Controller_Front::getInstance()->getRequest()->getParam($key)); ?>">
    <?php endforeach; ?>
    <label><input type="checkbox" name="missing_authoritative" value="1" <?php echo isset($active_filters['missing_authoritative']) ? 'checked' : ''; ?>> <?php echo __('Only names without an authoritative form'); ?></label>
    <button type="submit" class="button"><?php echo __('Search'); ?></button>
</form>
<?php if ($active_filters): ?>
    <p class="name-authority-active">
        <?php echo __('Showing %1$s names %2$s.', number_format($total_results), html_escape(implode(', ', $active_filters))); ?>
        <a href="<?php echo html_escape(url($base . 'browse')); ?>"><?php echo __('Show all'); ?></a>
    </p>
<?php endif; ?>

<?php if (!$name_authorities): ?>
    <p><?php echo __('No names found.'); ?> <?php if (!$grand_total): ?><a href="<?php echo html_escape(url($base . 'import')); ?>"><?php echo __('Add names one at a time, or import a sheet of names to start.'); ?></a><?php endif; ?></p>
<?php else: ?>
<?php echo pagination_links(); ?>
<form method="get" action="<?php echo html_escape(url($base . 'merge-with')); ?>" id="name-authority-pick">
<div class="name-authority-pick-bar">
    <button type="submit" class="button" id="name-authority-pick-go" disabled><?php echo __('Compare and merge the two ticked names'); ?></button>
    <button type="submit" class="button red" id="name-authority-pick-delete" formaction="<?php echo html_escape(url($base . 'delete-many-confirm')); ?>" disabled><?php echo __('Delete ticked'); ?></button>
    <span class="explanation" id="name-authority-pick-count"><?php echo __('Tick names to merge (two) or delete (search above to find them on one page).'); ?></span>
</div>
<table class="name-authority-table">
    <thead>
        <tr>
            <th scope="col" class="name-authority-pick-col"><span class="screen-reader-text"><?php echo __('Select'); ?></span></th>
            <?php echo browse_sort_links(array(__('Authoritative name') => 'authoritative'), array('link_tag' => 'th', 'link_attr' => array('scope' => 'col'), 'list_tag' => '')); ?>
            <th scope="col"><?php echo __('Forms by language'); ?></th>
            <th scope="col"><?php echo __('Search variants'); ?></th>
            <?php
            // Items: first click shows the most-used first.
            $req = Zend_Controller_Front::getInstance()->getRequest();
            $byItems = $req->getParam('sort_field') === 'items';
            $itemsDir = $byItems ? ($req->getParam('sort_dir') === 'a' ? 'a' : 'd') : null;
            $itemsParams = array_merge($_GET, array('sort_field' => 'items', 'sort_dir' => $itemsDir === 'd' ? 'a' : 'd'));
            unset($itemsParams['page']);
            ?>
            <th scope="col"<?php echo $byItems ? ' class="sorting ' . ($itemsDir === 'd' ? 'desc' : 'asc') . '"' : ''; ?>><a href="<?php echo html_escape(url(array(), null, $itemsParams)); ?>" title="<?php echo html_escape($byItems ? ($itemsDir === 'd' ? __('Most items first') : __('Fewest items first')) : __('Sort by number of items')); ?>"><?php echo __('Items'); ?> <span class="sort-icon"></span></a></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($name_authorities as $a): ?>
        <tr>
            <td class="name-authority-pick-col"><input type="checkbox" name="ids[]" value="<?php echo (int) $a->id; ?>" aria-label="<?php echo html_escape(__('Select %s', $a->getDisplayName())); ?>"></td>
            <td>
                <a href="<?php echo html_escape(url($base . 'edit/id/' . $a->id) . ($from !== '' ? '?' . http_build_query(array('from' => $from)) : '')); ?>" dir="auto"><?php echo $a->authoritative !== '' ? html_escape($a->authoritative) : '<em>' . __('(no authoritative name)') . '</em>'; ?></a>
                <?php if (isset($flagged[$a->id])): ?>
                    <a class="name-authority-dup-flag" href="<?php echo html_escape(url($base . 'duplicates', array('id' => $a->id))); ?>" title="<?php echo html_escape(__('Possible duplicate: compare and merge')); ?>"><?php echo __('possible duplicate'); ?></a>
                <?php endif; ?>
                <ul class="action-links group">
                    <li><a href="<?php echo html_escape(url($base . 'edit/id/' . $a->id) . ($from !== '' ? '?' . http_build_query(array('from' => $from)) : '')); ?>"><?php echo __('Edit'); ?></a></li>
                    <li><a href="<?php echo html_escape(url($base . 'delete-confirm/id/' . $a->id)); ?>"><?php echo __('Delete'); ?></a></li>
                </ul>
            </td>
            <td><?php foreach ($a->getLanguageForms() as $f): ?>
                <div><span class="name-authority-lang"><?php echo html_escape($f['language']); ?></span> <span dir="auto"><?php echo html_escape($f['text']); ?></span></div>
            <?php endforeach; ?></td>
            <td dir="auto"><?php echo html_escape(implode(' · ', $a->getVariants())); ?></td>
            <td><?php $n = count(NameAuthorityPlugin::instanceItemIds($a->id)); ?>
                <?php if ($n): ?><a href="<?php echo html_escape(url('items/browse', array('name_authority' => $a->id))); ?>" target="_blank" rel="noopener"><?php echo $n; ?></a><?php else: ?>0<?php endif; ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</form>
<?php echo pagination_links(); ?>
<?php endif; ?>
<?php echo foot(); ?>
