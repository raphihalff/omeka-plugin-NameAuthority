<?php
/*
 * One suggested pair, side by side, with Merge / Not the same buttons.
 * Expects: $pair (a, b, reasons, recA, recB), $csrf, $return ('duplicates'|'edit'),
 * optional $returnId, $only, $page.
 */
$base = 'name-authority/index/';
$hidden = function (array $fields) use ($csrf) {
    $html = $csrf->getElement('csrf_token')->render();
    foreach ($fields as $k => $v) {
        if ($v !== null && $v !== '') {
            $html .= '<input type="hidden" name="' . html_escape($k) . '" value="' . html_escape($v) . '">';
        }
    }
    return $html;
};
$ctx = array('return' => $return, 'return_id' => isset($returnId) ? $returnId : null,
    'only' => isset($only) ? $only : null, 'page' => isset($page) ? $page : null);
$sides = array(array($pair['recA'], $pair['recB']), array($pair['recB'], $pair['recA']));
?>
<div class="name-authority-pair">
    <?php if ($pair['reasons']): ?>
    <p class="name-authority-reasons">
        <?php foreach ($pair['reasons'] as $reason => $example): ?>
            <span class="name-authority-reason name-authority-reason-<?php echo html_escape($reason); ?>"><?php echo html_escape(NameAuthority_Duplicates::reasonLabel($reason)); ?></span>
            <span class="name-authority-reason-example" dir="auto"><?php echo html_escape($example); ?></span>
        <?php endforeach; ?>
    </p>
    <?php endif; ?>
    <div class="name-authority-pair-sides">
    <?php foreach ($sides as $side): list($rec, $other) = $side; $n = count(NameAuthorityPlugin::instanceItemIds($rec->id)); ?>
        <div class="name-authority-side">
            <h3><a dir="auto" href="<?php echo html_escape(url($base . 'edit/id/' . $rec->id)); ?>"><?php echo html_escape($rec->getDisplayName()); ?></a> <span class="name-authority-id">#<?php echo (int) $rec->id; ?></span></h3>
            <?php foreach ($rec->getLanguageForms() as $f): ?>
                <div><span class="name-authority-lang"><?php echo html_escape($f['language']); ?></span> <span dir="auto"><?php echo html_escape($f['text']); ?></span></div>
            <?php endforeach; ?>
            <?php if ($v = $rec->getVariants()): ?>
                <div class="name-authority-variants"><span class="name-authority-lang"><?php echo __('Variants'); ?></span> <span dir="auto"><?php echo html_escape(implode(' · ', $v)); ?></span></div>
            <?php endif; ?>
            <?php if ($rec->viaf_id !== '' || $rec->lc_id !== ''): ?>
                <div class="name-authority-ids">
                    <?php if ($rec->viaf_id !== ''): ?><a href="https://viaf.org/viaf/<?php echo rawurlencode($rec->viaf_id); ?>/" target="_blank" rel="noopener">VIAF <?php echo html_escape($rec->viaf_id); ?></a><?php endif; ?>
                    <?php if ($rec->lc_id !== ''): ?><a href="https://id.loc.gov/authorities/names/<?php echo rawurlencode($rec->lc_id); ?>.html" target="_blank" rel="noopener">LC <?php echo html_escape($rec->lc_id); ?></a><?php endif; ?>
                </div>
            <?php endif; ?>
            <div class="name-authority-items"><?php if ($n): ?><a href="<?php echo html_escape(url('items/browse', array('name_authority' => $rec->id))); ?>" target="_blank"><?php echo __(plural('%d item', '%d items', $n), $n); ?></a><?php else: ?><?php echo __('No items'); ?><?php endif; ?></div>
            <form method="post" action="<?php echo html_escape(url($base . 'merge')); ?>">
                <?php echo $hidden($ctx + array('target' => $rec->id, 'source' => $other->id)); ?>
                <button type="submit" class="button green small"><?php echo __('Keep this one, merge the other into it'); ?></button>
            </form>
        </div>
    <?php endforeach; ?>
    </div>
    <?php if ($pair['reasons']): ?>
    <form method="post" action="<?php echo html_escape(url($base . 'not-duplicate')); ?>" class="name-authority-not-same">
        <?php echo $hidden($ctx + array('a' => $pair['a'], 'b' => $pair['b'])); ?>
        <button type="submit" class="button small"><?php echo __('Not the same'); ?></button>
    </form>
    <?php endif; ?>
</div>
