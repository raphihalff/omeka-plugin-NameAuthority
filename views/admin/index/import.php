<?php
echo head(array('title' => __('Import names'), 'bodyclass' => 'name-authority import'));
echo flash();
?>
<form method="post" enctype="multipart/form-data" class="name-authority-form">
    <?php echo $csrf->getElement('csrf_token'); ?>
    <p><?php echo __('Upload a tab-separated file (UTF-8) with a header row, such as a sheet you made from your items or a file exported from this page.'); ?></p>
    <p class="explanation"><?php echo __('Columns: <code>authoritative</code>, one column per language, headed by the language\'s name (<code>english</code>, <code>german</code>, <code>lithuanian</code>, …), <code>other_languages</code> ("German: name; Polish: name"), <code>variants_for_search</code> (separated by " | "), and optionally <code>viaf_id</code>, <code>lc_id</code>, <code>notes</code>. Any other column is read as a language; after importing you\'ll see which.'); ?></p>
    <p><input type="file" name="tsv" accept=".tsv,.txt,text/tab-separated-values"></p>
    <fieldset>
        <label><input type="radio" name="mode" value="add" checked> <?php echo __('Add new names (skip names that already exist)'); ?></label><br>
        <label><input type="radio" name="mode" value="replace"> <?php echo __('Replace everything (delete all current name records first)'); ?></label>
    </fieldset>
    <p><input type="submit" class="submit green button" value="<?php echo html_escape(__('Import')); ?>"></p>
</form>
<?php echo foot(); ?>
