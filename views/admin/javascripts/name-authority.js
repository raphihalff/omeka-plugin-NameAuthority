/* Name Authority admin: VIAF look-up on the edit page. */
(function () {
    'use strict';

    function $(id) { return document.getElementById(id); }

    function el(tag, attrs, children) {
        var node = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) {
            if (k === 'text') { node.textContent = attrs[k]; } else { node.setAttribute(k, attrs[k]); }
        });
        (children || []).forEach(function (c) { if (c) { node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); } });
        return node;
    }

    function stripDates(s) {
        return s.replace(/[,\s]*\(?\b(?:b|d|fl|ca)\.\s*(?=\d)/g, ' ')
            .replace(/[,\s]*\(?\d{3,4}\??(?:\s*(?:-|–|or)\s*\d{0,4}\??)*\)?\.?/g, '')
            .replace(/\s+/g, ' ').replace(/^[\s,]+|[\s,]+$/g, '');
    }

    function defaultQuery() {
        var a = $('authoritative');
        if (a && a.value.trim()) { return a.value.trim(); }
        var forms = $('forms');
        if (forms) {
            var lines = forms.value.split(/\r?\n/);
            for (var i = 0; i < lines.length; i++) {
                var m = lines[i].match(/^[^:]{2,40}:\s*(.+)$/);
                if (m && m[1].trim()) { return m[1].trim(); }
            }
        }
        return '';
    }

    function updateLinks(q) {
        var s = stripDates(q);
        $('name-authority-lc-site').href = 'https://id.loc.gov/search/?q=' + encodeURIComponent(s) +
            '&q=cs%3Ahttp%3A%2F%2Fid.loc.gov%2Fauthorities%2Fnames';
    }

    function init() {
        var box = $('name-authority-viaf');
        if (!box) { return; }
        var input = $('name-authority-viaf-q');
        var results = $('name-authority-viaf-results');
        var button = $('name-authority-viaf-go');
        var touched = false;

        input.value = defaultQuery();
        updateLinks(input.value);
        input.addEventListener('input', function () { touched = true; updateLinks(input.value); });
        var auth = $('authoritative');
        if (auth) {
            auth.addEventListener('input', function () {
                if (!touched) { input.value = defaultQuery(); updateLinks(input.value); }
            });
        }

        function norm(s) { return (s || '').replace(/\s+/g, ' ').trim(); }

        function note(text, cls) {
            var p = el('p', {'class': 'name-authority-viaf-chosen' + (cls ? ' ' + cls : '')}, typeof text === 'string' ? [text] : text);
            results.insertBefore(p, results.firstChild);
            return p;
        }

        /* Put the old authoritative name into the search variants, unless it is already a form or variant. */
        function keepAsVariant(old) {
            var variants = $('variants'), forms = $('forms');
            if (!old || !variants) { return false; }
            var have = (variants.value + '\n' + (forms ? forms.value.replace(/^[^:\n]{2,40}:\s*/gm, '') : '')).split(/\r?\n/).map(norm);
            if (have.indexOf(norm(old)) !== -1) { return false; }
            variants.value = norm(variants.value) ? variants.value.replace(/\s*$/, '') + '\n' + old : old;
            variants.classList.add('name-authority-filled');
            return true;
        }

        function setAuthoritative(heading) {
            var auth = $('authoritative');
            var old = norm(auth.value);
            auth.value = heading;
            auth.classList.add('name-authority-filled');
            return keepAsVariant(old) ? old : null;
        }

        function offerHeading(r) {
            var auth = $('authoritative');
            if (!auth) { return; }
            var waiting = note('Fetching LC\'s heading from VIAF…');
            var recUrl = box.getAttribute('data-record-url') + '?viaf=' + encodeURIComponent(r.viaf);
            fetch(recUrl, {credentials: 'same-origin', headers: {Accept: 'application/json'}})
                .then(function (resp) { if (!resp.ok) { throw new Error('HTTP ' + resp.status); } return resp.json(); })
                .then(function (d) {
                    waiting.remove();
                    if (d.error) { note('Could not get LC\'s heading: ' + d.error, 'name-authority-viaf-warn'); return; }
                    var lc = $('lc_id');
                    if (lc && d.lc && !lc.value) { lc.value = d.lc; lc.classList.add('name-authority-filled'); }
                    if (!d.heading) {
                        note('LC has no heading for this person in VIAF, so the authoritative name was left as it is.', 'name-authority-viaf-warn');
                        return;
                    }
                    var current = norm(auth.value);
                    if (!current) {
                        setAuthoritative(d.heading);
                        note(['Filled in the authoritative name with LC\'s heading: ', el('strong', {dir: 'auto', text: d.heading}), '.']);
                    } else if (current === norm(d.heading)) {
                        note(['The authoritative name already matches LC\'s heading.']);
                    } else {
                        var btn = el('button', {type: 'button', 'class': 'button small green', text: 'Use as authoritative name'});
                        var p = note(['LC\'s heading: ', el('strong', {dir: 'auto', text: d.heading}), el('br'),
                            'Current authoritative name: ', el('strong', {dir: 'auto', text: current}), el('br'), btn]);
                        btn.addEventListener('click', function () {
                            var moved = setAuthoritative(d.heading);
                            p.textContent = 'Authoritative name changed to ' + d.heading + '.' +
                                (moved ? ' The old name, ' + moved + ', was added to the search variants.' : '') + ' Click Save to keep it.';
                        });
                    }
                })
                .catch(function (e) { waiting.remove(); note('Could not get LC\'s heading (' + e.message + ').', 'name-authority-viaf-warn'); });
        }

        function choose(r) {
            var viaf = $('viaf_id');
            var lc = $('lc_id');
            if (viaf) { viaf.value = r.viaf; viaf.classList.add('name-authority-filled'); }
            if (lc && r.lc) { lc.value = r.lc; lc.classList.add('name-authority-filled'); }
            var msg = r.lc ? 'VIAF ' + r.viaf + ' and LC ' + r.lc : 'VIAF ' + r.viaf;
            note('Filled in ' + msg + '. Click Save to keep it.');
            offerHeading(r);
        }

        function render(data) {
            results.innerHTML = '';
            if (data.error) {
                results.appendChild(el('p', {'class': 'error', text: data.error + ' Use the search links below instead.'}));
                return;
            }
            if (!data.results || !data.results.length) {
                results.appendChild(el('p', {text: 'No matches in VIAF. Try a shorter form, e.g. the surname only, or another spelling.'}));
                return;
            }
            var list = el('ol', {'class': 'name-authority-viaf-list'});
            data.results.forEach(function (r) {
                var ids = el('span', {'class': 'name-authority-viaf-ids'}, [
                    el('a', {href: 'https://viaf.org/viaf/' + encodeURIComponent(r.viaf) + '/', target: '_blank', rel: 'noopener', text: 'VIAF ' + r.viaf}),
                    r.lc ? ' · ' : '',
                    r.lc ? el('a', {href: 'https://id.loc.gov/authorities/' + (r.lc.indexOf('sh') === 0 ? 'subjects' : 'names') + '/' + encodeURIComponent(r.lc) + '.html', target: '_blank', rel: 'noopener', text: 'LC ' + r.lc}) : ''
                ]);
                var use = el('button', {type: 'button', 'class': 'button small green', text: 'Use'});
                use.addEventListener('click', function () { choose(r); });
                var children = [
                    el('span', {'class': 'name-authority-viaf-name', dir: 'auto', text: r.name}),
                    r.type ? el('span', {'class': 'name-authority-lang', text: r.type}) : '',
                    el('br'), ids, ' ', use
                ];
                if (r.also && r.also.length) {
                    children.push(el('div', {'class': 'name-authority-viaf-also', dir: 'auto', text: 'also: ' + r.also.join(' · ')}));
                }
                if (r.used_by && r.used_by.length) {
                    var warn = el('div', {'class': 'name-authority-viaf-used'}, ['Already used by: ']);
                    r.used_by.forEach(function (u, i) {
                        if (i) { warn.appendChild(document.createTextNode(', ')); }
                        warn.appendChild(el('a', {href: u.url, dir: 'auto', text: u.name || ('#' + u.id)}));
                    });
                    warn.appendChild(document.createTextNode(' (possible duplicate)'));
                    children.push(warn);
                }
                list.appendChild(el('li', {}, children));
            });
            results.appendChild(list);
        }

        function lookup() {
            var q = input.value.trim();
            if (!q) { input.focus(); return; }
            updateLinks(q);
            button.disabled = true;
            results.innerHTML = '';
            results.appendChild(el('p', {text: 'Searching VIAF…'}));
            var url = box.getAttribute('data-url') + '?q=' + encodeURIComponent(q) + '&id=' + encodeURIComponent(box.getAttribute('data-id') || '');
            fetch(url, {credentials: 'same-origin', headers: {Accept: 'application/json'}})
                .then(function (r) {
                    if (!r.ok) { throw new Error('HTTP ' + r.status); }
                    return r.json();
                })
                .then(render)
                .catch(function (e) { render({error: 'The look-up failed (' + e.message + ').'}); })
                .then(function () { button.disabled = false; });
        }

        button.addEventListener('click', lookup);
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); lookup(); }
        });
    }

    /* Browse page: enable "Compare and merge" when exactly two names are ticked. */
    function initPick() {
        var form = $('name-authority-pick');
        if (!form) { return; }
        var go = $('name-authority-pick-go');
        var label = $('name-authority-pick-count');
        var initial = label.textContent;
        function update() {
            var n = form.querySelectorAll('input[name="ids[]"]:checked').length;
            go.disabled = n !== 2;
            var del = $('name-authority-pick-delete');
            if (del) { del.disabled = n === 0; }
            label.textContent = n === 0 ? initial : n + ' ticked' + (n === 2 ? '.' : ' (merging needs exactly two).');
        }
        form.addEventListener('change', update);
        update();
    }

    function start() { init(); initPick(); }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
}());
