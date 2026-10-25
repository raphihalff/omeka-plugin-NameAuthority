/*
 * Name Authority: suggestions on the item form.
 *
 * Typing in a name field (as chosen in the plugin settings) lists matching
 * name records and fills in the form in the item's language, or the
 * authoritative name in the authoritative-heading field. Where LC Suggest is set for the
 * same field, its suggestions follow ours in the same list (LC Suggest's own
 * autocomplete would otherwise replace this one, or the other way round).
 */
(function ($) {
    'use strict';
    var cfg = window.NameAuthoritySuggest;
    if (!cfg || !$ || !$.ui || !$.ui.autocomplete) {
        return;
    }
    var empty = function () { return $.Deferred().resolve([]).promise(); };

    /* The item's language(s) as typed in its Language field right now (saved or not).
       Several per box are fine ("English, German"); HTML from "Use HTML" is ignored. */
    function itemLanguages() {
        var out = [];
        if (cfg.languageElementId) {
            $('#element-' + cfg.languageElementId).find('textarea, input[type=text], select').each(function () {
                var v = String($(this).val() || '').replace(/<[^>]*>/g, ' ');
                $.each(v.split(/[,;\/\n]+/), function (i, part) {
                    part = $.trim(part);
                    if (part && $.inArray(part, out) === -1) { out.push(part); }
                });
            });
        }
        return out;
    }

    function ours(elementId, term) {
        return $.ajax({
            url: cfg.endpoint,
            data: {term: term, element: elementId, lang: itemLanguages()},
            dataType: 'json',
            timeout: 8000
        }).then(function (d) { return $.isArray(d) ? d : []; }, empty);
    }

    function lc(elementId, term) {
        var url = cfg.lc && cfg.lc[elementId];
        if (!url) { return empty(); }
        return $.ajax({url: url, data: {term: term}, dataType: 'json', timeout: 6000})
            .then(function (d) {
                return $.isArray(d) ? $.map(d, function (s) {
                    return typeof s === 'string' ? {value: s, label: s, source: 'lc'} : null;
                }) : [];
            }, empty);   // LC failing or slow never hides our suggestions
    }

    function sourceFor(elementId) {
        return function (request, response) {
            var langs = itemLanguages();
            $.when(ours(elementId, request.term), lc(elementId, request.term)).done(function (a, b) {
                var seen = {};
                var list = [];
                if (a.length && elementId !== cfg.authoritativeElementId) {
                    // Say which language the forms are for, so it's clear what was read from the form.
                    list.push({header: true, value: '', label: langs.length
                        ? cfg.text.formsFor.replace('%s', langs.join(', '))
                        : cfg.text.noLanguage});
                }
                $.each(a.concat(b), function (i, s) {
                    var k = s.value + '\u0000' + (s.source || '');
                    if (!seen[k]) { seen[k] = true; list.push(s); }
                });
                response(list);
            });
        };
    }

    function renderItem(ul, item) {
        if (item.header) {
            return $('<li class="ui-autocomplete-category name-authority-suggest-header">').append($('<div>').text(item.label)).appendTo(ul);
        }
        var $li = $('<li>').addClass(item.source === 'lc' ? 'name-authority-suggest-lc' : 'name-authority-suggest-own');
        var $wrap = $('<div>');
        $('<span class="name-authority-suggest-value" dir="auto">').text(item.value).appendTo($wrap);
        var $meta = $('<span class="name-authority-suggest-meta">');
        if (item.source === 'lc') {
            $meta.append($('<span class="name-authority-suggest-tag">').text(cfg.text.lc));
        } else {
            if (item.language) { $meta.append($('<span class="name-authority-suggest-tag">').text(item.language)); }
            if (item.note) { $meta.append($('<span class="name-authority-suggest-note">').text(item.note)); }
            if (item.record && item.record !== item.value) { $meta.append($('<span dir="auto">').text(item.record)); }
            if (item.items) { $meta.append($('<span>').text(item.items === 1 ? cfg.text.item : cfg.text.items.replace('%d', item.items))); }
            if (item.matched) { $meta.append($('<span dir="auto">').text(cfg.text.matched.replace('%s', item.matched))); }
        }
        $wrap.append($meta);
        return $li.append($wrap).appendTo(ul);
    }

    function attach() {
        $.each(cfg.elements, function (i, elementId) {
            $('#element-' + elementId + ' textarea').each(function () {
                var $t = $(this);
                var options = {
                    minLength: 2,
                    delay: 250,
                    focus: function (e, ui) { if (ui.item && ui.item.header) { e.preventDefault(); } },
                    select: function (e, ui) { if (ui.item && ui.item.header) { e.preventDefault(); } },
                    source: sourceFor(elementId),
                    classes: {'ui-autocomplete': 'name-authority-suggest'}
                };
                // LC Suggest (or an earlier run) may already have set one up: take it over.
                if ($t.autocomplete('instance')) {
                    $t.autocomplete('option', options);
                } else {
                    $t.autocomplete(options);
                }
                $t.autocomplete('instance')._renderItem = renderItem;
                // Keyboard and mouse skip the grey "Forms for…" line.
                $t.autocomplete('widget').menu('option', 'items', '> :not(.ui-autocomplete-category)');
            });
        });
    }

    // Omeka fires this after the form loads and after "Add Input" / item type changes.
    // LC Suggest sets up its own autocomplete on the same event, so run just after it.
    $(document).on('omeka:elementformload', function () { setTimeout(attach, 0); });
    $(function () { setTimeout(attach, 0); });
}(window.jQuery));
