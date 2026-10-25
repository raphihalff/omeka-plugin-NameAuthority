<?php
$a = $name_authority;
$title = $a->exists() ? __('Edit name: %s', $a->getDisplayName()) : __('Add a name');
echo head(array('title' => $title, 'bodyclass' => 'name-authority edit'));
echo flash();
?>
<?php if ($nav): ?>
<nav class="name-authority-recnav" aria-label="<?php echo html_escape(__('Records in this list')); ?>">
    <a class="button" href="<?php echo html_escape($listUrl); ?>"><?php echo __('Back to list'); ?></a>
    <?php if ($prevUrl): ?><a class="button" href="<?php echo html_escape($prevUrl); ?>" rel="prev" accesskey="p">&larr; <?php echo __('Previous'); ?></a><?php else: ?><span class="button" aria-disabled="true">&larr; <?php echo __('Previous'); ?></span><?php endif; ?>
    <span class="name-authority-recnav-pos"><?php echo $nav['position'] ? __('%1$s of %2$s', number_format($nav['position']), number_format($nav['total'])) : __('No longer in this list (%s left)', number_format($nav['total'])); ?></span>
    <?php if ($nextUrl): ?><a class="button" href="<?php echo html_escape($nextUrl); ?>" rel="next" accesskey="n"><?php echo __('Next'); ?> &rarr;</a><?php else: ?><span class="button" aria-disabled="true"><?php echo __('Next'); ?> &rarr;</span><?php endif; ?>
</nav>
<?php endif; ?>
<form method="post" class="name-authority-form">
    <?php echo $csrf->getElement('csrf_token'); ?>
    <section class="seven columns alpha">
        <div class="field">
            <div class="two columns alpha"><label for="authoritative"><?php echo __('Authoritative name'); ?></label></div>
            <div class="inputs five columns omega">
                <p class="explanation"><?php echo __('Usually the LC heading, e.g. "Tolstoy, Leo, graf, 1828-1910".'); ?><?php if ($authNames = NameAuthorityPlugin::elementNames(array(NameAuthorityPlugin::authoritativeElementId()))): ?> <?php echo __('Suggested in %s on the item form.', html_escape(current($authNames))); ?><?php endif; ?></p>
                <input type="text" name="authoritative" id="authoritative" dir="auto" value="<?php echo html_escape($a->authoritative); ?>">
            </div>
        </div>
        <div class="field">
            <div class="two columns alpha"><label for="forms"><?php echo __('Forms by language'); ?></label></div>
            <div class="inputs five columns omega">
                <p class="explanation"><?php echo __('One per line, as "Language: name". This is the form used on items in that language, e.g. "Russian: Лев Толстой".'); ?></p>
                <textarea name="forms" id="forms" rows="6" dir="auto"><?php echo htmlspecialchars($formsText, ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>
        </div>
        <div class="field">
            <div class="two columns alpha"><label for="variants"><?php echo __('Search variants'); ?></label></div>
            <div class="inputs five columns omega">
                <p class="explanation"><?php echo __('One per line. Other names and spellings that should find this person, e.g. other romanizations, or a real name behind a pen name. Not shown on items.'); ?></p>
                <textarea name="variants" id="variants" rows="6" dir="auto"><?php echo htmlspecialchars($variantsText, ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>
        </div>
        <?php if ($showIds): ?>
        <div class="field">
            <div class="two columns alpha"><label for="viaf_id"><?php echo __('VIAF ID'); ?></label></div>
            <div class="inputs five columns omega">
                <p class="explanation"><?php echo __('The number from viaf.org/viaf/<strong>…</strong>'); ?></p>
                <input type="text" name="viaf_id" id="viaf_id" value="<?php echo html_escape($a->viaf_id); ?>">
            </div>
        </div>
        <div class="field">
            <div class="two columns alpha"><label for="lc_id"><?php echo __('LC ID'); ?></label></div>
            <div class="inputs five columns omega">
                <p class="explanation"><?php echo __('e.g. n80013426 (names) or sh85148350 (subjects), from id.loc.gov'); ?></p>
                <input type="text" name="lc_id" id="lc_id" value="<?php echo html_escape($a->lc_id); ?>">
            </div>
        </div>
        <div class="field">
            <div class="two columns alpha"><label for="name-authority-viaf-q"><?php echo __('Look up'); ?></label></div>
            <div class="inputs five columns omega">
                <div id="name-authority-viaf" data-url="<?php echo html_escape(url('name-authority/index/viaf')); ?>" data-record-url="<?php echo html_escape(url('name-authority/index/viaf-record')); ?>" data-id="<?php echo (int) $a->id; ?>">
                    <p class="explanation"><?php echo __('Search VIAF for this name, then choose a match: this fills in the VIAF and LC IDs and offers LC\'s heading as the authoritative name. Save afterwards.'); ?></p>
                    <div class="name-authority-viaf-bar">
                        <input type="text" id="name-authority-viaf-q" dir="auto" placeholder="<?php echo html_escape(__('Name to look up')); ?>">
                        <button type="button" class="button" id="name-authority-viaf-go"><?php echo __('Look up in VIAF'); ?></button>
                    </div>
                    <div id="name-authority-viaf-results" aria-live="polite"></div>
                    <p class="name-authority-viaf-links">
                        <?php echo __('Or search by hand:'); ?>
                        <a id="name-authority-viaf-site" href="https://viaf.org/" target="_blank" rel="noopener">viaf.org</a> ·
                        <a id="name-authority-lc-site" href="https://id.loc.gov/" target="_blank" rel="noopener">id.loc.gov</a>
                    </p>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <div class="field">
            <div class="two columns alpha"><label for="notes"><?php echo __('Notes'); ?></label></div>
            <div class="inputs five columns omega">
                <p class="explanation"><?php echo __('Private, for cataloguers.'); ?></p>
                <textarea name="notes" id="notes" rows="3" dir="auto"><?php echo htmlspecialchars((string) $a->notes, ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>
        </div>
    </section>
    <section class="three columns omega">
        <div id="save" class="panel">
            <input type="submit" class="submit big green button" value="<?php echo html_escape(__('Save')); ?>">
            <?php if ($nextUrl): ?>
                <input type="submit" name="save_next" class="big green button" value="<?php echo html_escape(__('Save and next')); ?>">
            <?php endif; ?>
            <?php if ($a->exists()): ?>
                <a class="big button" href="<?php echo html_escape(url('items/browse', array('name_authority' => $a->id))); ?>" target="_blank" rel="noopener"><?php echo __('%d items', $itemCount); ?></a>
                <a class="big button" href="<?php echo html_escape(url('name-authority/index/merge-with', array('id' => $a->id))); ?>"><?php echo __('Merge with another record…'); ?></a>
                <a class="big red button" href="<?php echo html_escape(url('name-authority/index/delete-confirm/id/' . $a->id)); ?>"><?php echo __('Delete'); ?></a>
            <?php endif; ?>
            <a href="<?php echo html_escape($from !== '' ? $listUrl : url('name-authority/index/browse')); ?>"><?php echo $from !== '' ? __('Back to list') : __('Back to all names'); ?></a>
        </div>
    </section>
</form>
<?php if ($duplicates): ?>
<section class="ten columns alpha omega name-authority-edit-dups">
    <h2><?php echo __('Possible duplicates'); ?></h2>
    <?php foreach ($duplicates as $pair): ?>
        <?php echo $this->partial('index/pair.php', array('pair' => $pair, 'csrf' => $csrf, 'return' => 'edit', 'returnId' => $a->id)); ?>
    <?php endforeach; ?>
</section>
<?php endif; ?>
<?php echo foot(); ?>
