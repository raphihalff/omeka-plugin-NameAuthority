<?php
$checkbox = function ($name, $label, $explanation) {
    $view = get_view();
    echo '<div class="field"><div class="two columns alpha">' . $view->formLabel($name, $label) . '</div>'
        . '<div class="inputs five columns omega"><p class="explanation">' . $explanation . '</p>'
        . $view->formCheckbox($name, true, array('checked' => (bool) get_option($name))) . '</div></div>';
};
?>
<?php
// One <select> of all fields, grouped by element set.
$fieldSelect = function ($name, $selected, $multiple, $emptyLabel = null) use ($elementOptions) {
    $selected = array_map('intval', (array) $selected);
    $html = '<select name="' . $name . ($multiple ? '[]" multiple size="10"' : '"') . ' id="' . $name . '">';
    if ($emptyLabel !== null) {
        $html .= '<option value="0">' . html_escape($emptyLabel) . '</option>';
    }
    foreach ($elementOptions as $set => $els) {
        $html .= '<optgroup label="' . html_escape($set) . '">';
        foreach ($els as $id => $label) {
            $html .= '<option value="' . (int) $id . '"' . (in_array((int) $id, $selected, true) ? ' selected' : '') . '>' . html_escape($label) . '</option>';
        }
        $html .= '</optgroup>';
    }
    return $html . '</select>';
};
$field = function ($name, $label, $explanation, $control) {
    echo '<div class="field"><div class="two columns alpha"><label for="' . $name . '">' . $label . '</label></div>'
        . '<div class="inputs five columns omega"><p class="explanation">' . $explanation . '</p>' . $control . '</div></div>';
};
?>
<h2><?php echo __('Fields'); ?></h2>
<?php
$field('name_authority_element_ids', __('Fields that hold names'),
    __('Item values in these fields are matched to name records: names become clickable, find every form, and so on. Hold Cmd/Ctrl to choose several.'),
    $fieldSelect('name_authority_element_ids', $elementIds, true));
$field('name_authority_topic_element_ids', __('Which of these mostly hold topics?'),
    __('Optional. Of the name fields chosen above, pick any whose values are mostly topics rather than people, such as Subject. They keep working exactly like the other name fields (clickable, every form found, suggestions); the only difference is that their values without a record are listed separately, under "Topics with no record", so they don\'t crowd out names. Fields not chosen above are greyed out here.'),
    $fieldSelect('name_authority_topic_element_ids', $topicIds, true));
$field('name_authority_authoritative_element_id', __('Field for the authoritative heading'),
    __('Optional. If one of the name fields above is for the authoritative form of a name (e.g. the LC heading), choose it: the item form then suggests each record\'s authoritative name there instead of a language form.'),
    $fieldSelect('name_authority_authoritative_element_id', $authoritativeId, false, __('None')));
$field('name_authority_language_element_id', __('Field with the item\'s language'),
    __('Used by the item form to suggest the form of a name in the item\'s language (e.g. the German form on a German item). Values can be language names or codes (German, ger, de).'),
    $fieldSelect('name_authority_language_element_id', $languageId, false, __('None')));
?>

<h2><?php echo __('Item form'); ?></h2>
<?php
$checkbox('name_authority_item_suggest', __('Suggest names while typing'),
    __('On the item add/edit form, typing in a name field suggests matching name records (any language or spelling) and fills in the form in the item\'s language (or the authoritative name, in the authoritative-heading field). Where LC Suggest is set for the same field, its suggestions are listed below.'));
?>

<h2><?php echo __('Search'); ?></h2>
<?php
$checkbox('name_authority_expand_clicks', __('Clicked names find every form'),
    __('When a visitor clicks a name (a search for "is exactly" that name in one of the fields above), show the person\'s items under every form, in every language.'));
$checkbox('name_authority_expand_search', __('Searches find variants'),
    __('Item search and the site search also find a person by any form or search variant (e.g. "Clemens" finds Mark Twain, if that is one of his variants). The site search needs a re-index after names change (Name Authority &gt; Re-index search).'));
?>

<h2><?php echo __('Display'); ?></h2>
<?php
$checkbox('name_authority_show_aka', __('"Also:" under names'),
    __('On item pages, list the person\'s other forms after each name, e.g. "also: Лев Толстой · Lew Tolstoi".'));
$checkbox('name_authority_aka_variants', __('…including search variants'),
    __('Also list search variants in that line.'));
$checkbox('name_authority_show_banner', __('Person banner on results'),
    __('Above the results of a clicked name: the authoritative name, the forms in each language and (if set) VIAF / LC links.'));
$checkbox('name_authority_show_ids', __('VIAF and LC identifiers'),
    __('Show VIAF / LC ID fields when editing names, and links to them in the person banner.'));
$checkbox('name_authority_make_links', __('Make names clickable'),
    __('Link each name to the person\'s items. Only turn this on if names are not already links (e.g. from Search By Metadata), or they will be linked twice.'));
?>
<script>
/* Topics and the authoritative-heading field must be name fields: grey out the others, live. */
(function () {
    var names = document.getElementById('name_authority_element_ids');
    var dependents = ['name_authority_topic_element_ids', 'name_authority_authoritative_element_id'].map(function (id) {
        return document.getElementById(id);
    });
    if (!names) { return; }
    function sync() {
        var chosen = {};
        Array.prototype.forEach.call(names.options, function (o) { if (o.selected) { chosen[o.value] = true; } });
        dependents.forEach(function (sel) {
            if (!sel) { return; }
            Array.prototype.forEach.call(sel.options, function (o) {
                if (o.value === '0') { return; }
                var wasDisabled = o.disabled;
                o.disabled = !chosen[o.value];
                if (o.disabled && o.selected) {
                    o.dataset.restore = '1';   // put it back if the name field is ticked again
                    o.selected = false;
                    if (!sel.multiple) { sel.value = '0'; }
                } else if (wasDisabled && !o.disabled && o.dataset.restore) {
                    delete o.dataset.restore;
                    if (sel.multiple || sel.value === '0') { o.selected = true; }
                }
            });
        });
    }
    names.addEventListener('change', sync);
    sync();
}());
</script>
