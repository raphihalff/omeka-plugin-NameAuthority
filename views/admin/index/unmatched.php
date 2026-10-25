<?php
echo head(array('title' => $show_topics ? __('Topics with no record (%s)', number_format($total_results)) : __('Names with no record (%s)', number_format($total_results)), 'bodyclass' => 'name-authority unmatched'));
$fieldList = function (array $names) {
    return implode(', ', array_map('html_escape', $names));
};
echo flash();
$base = 'name-authority/index/';
?>
<p class="name-authority-actions">
    <a class="button" href="<?php echo html_escape(url($base . 'browse')); ?>"><?php echo __('Back to all names'); ?></a>
</p>
<nav class="name-authority-tabs" aria-label="<?php echo html_escape(__('Lists')); ?>">
    <a href="<?php echo html_escape(url($base . 'unmatched')); ?>"<?php echo !$show_topics ? ' class="current" aria-current="page"' : ''; ?>><?php echo __('Names'); ?> <span><?php echo number_format($counts['names']); ?></span></a>
    <a href="<?php echo html_escape(url($base . 'unmatched', array('subjects' => 1))); ?>"<?php echo $show_topics ? ' class="current" aria-current="page"' : ''; ?>><?php echo __('Topics'); ?> <span><?php echo number_format($counts['subjects']); ?></span></a>
</nav>

<?php if ($show_topics): ?>
    <p class="explanation"><?php echo __('Values in %s that match no record. Adding a record for one makes it clickable (with "Make names clickable" on) and lets it be found under every spelling you add to the record. Topics work the same way as people.', $fieldList($topic_names)); ?></p>
<?php else: ?>
    <p class="explanation"><?php echo __('Values in %s that match no record, in any form or variant. Clicked names and searches for these find only that exact spelling. Add a record for them, or add the spelling to an existing record as a form or search variant.', $fieldList($name_field_names)); ?></p>
    <p class="name-authority-filter">
        <?php
        $link = function ($label, array $params, $current) use ($base) {
            return '<a href="' . html_escape(url($base . 'unmatched', $params)) . '"' . ($current ? ' class="current"' : '') . '>' . $label . '</a>';
        };
        $links = array($link(__('All name fields'), array(), !$element));
        foreach ($by_element as $id => $e) {
            $links[] = $link(html_escape(__($e['name'])) . ' (' . number_format($e['count']) . ')', array('element' => $id), $element === $id);
        }
        echo implode(' · ', $links);
        ?>
    </p>
<?php endif; ?>

<?php if (!$values): ?>
    <p><?php echo $show_topics ? __('Every topic on the items has a record.') : __('Every name on the items has a record.'); ?></p>
<?php else: ?>
<div class="name-authority-pager"><?php echo pagination_links(); ?></div>
<div class="name-authority-scroll"><table class="name-authority-table">
    <thead>
        <tr>
            <th scope="col"><?php echo $show_topics ? __('Topic') : __('Name on items'); ?></th>
            <?php if (!$show_topics): ?><th scope="col"><?php echo __('Field'); ?></th><?php endif; ?>
            <th scope="col"><?php echo __('Items'); ?></th>
            <th scope="col"></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($values as $v): $n = count($v['items']); ?>
        <tr>
            <td dir="auto"><?php echo html_escape($v['text']); ?></td>
            <?php if (!$show_topics): ?><td><?php echo html_escape(__($v['element'])); ?></td><?php endif; ?>
            <td><a href="<?php echo html_escape(url('items/browse', array('advanced' => array(array('element_id' => $v['element_id'], 'type' => 'is exactly', 'terms' => $v['text']))))); ?>" target="_blank" rel="noopener"><?php echo $n; ?></a></td>
            <td>
                <?php $find = NameAuthority_Viaf::stripDates($v['text']); $find = strpos($find, ',') !== false ? trim(substr($find, 0, strpos($find, ','))) : $find; ?>
                <a href="<?php echo html_escape(url($base . 'browse', array('q' => $find))); ?>"><?php echo __('Find similar records'); ?></a> ·
                <a href="<?php echo html_escape(url($base . 'add', array('name' => $v['text']))); ?>"><?php echo __('Add a record'); ?></a>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<div class="name-authority-pager"><?php echo pagination_links(); ?></div>
<?php endif; ?>
<?php echo foot(); ?>
