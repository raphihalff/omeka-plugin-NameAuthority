#!/usr/bin/env python3
"""
Name Authority: turn the output of export-names.sql into a starter import sheet.

    python3 make-starter-sheet.py names.tsv starter.tsv

Spellings that differ only in capitals, spacing, punctuation, dates, word order,
accents or ligatures are grouped into one row (Smith, Jane A. / Smith, Jane A., 1950-;
שלום עליכם / שלום־עליכם). In each group the most-used
spelling becomes the form for its language and the others become search
variants. The language comes from the items' Language field where it fits the
spelling's alphabet. Otherwise it is guessed: the language most used on your
items for names in that alphabet, or else English for Latin script, Russian for
Cyrillic, Hebrew, Greek or Arabic. These decisions are automatic and can be
wrong where languages share an alphabet (Yiddish and Hebrew, Russian and
Ukrainian): guesses, and rows with two forms in one alphabet, are flagged in
the "check" column.

Your most-used languages get columns of their own; the rest go in
other_languages.

The sheet is a starting point, not a finished authority file:
  * the same person in two alphabets (Tolstoy / Толстой) ends up in two rows:
    join them by hand, or import and use Merge in the plugin;
  * different people with the same name are grouped: split them by hand;
  * "authoritative" is left empty: fill it in, or later use Look up in VIAF.
The "items" and "check" columns are ignored by the importer.

Needs only Python 3 (standard library).
"""
import csv
import re
import sys
import unicodedata
from collections import Counter, defaultdict

LIGATURES = {'װ': 'וו', 'ױ': 'וי', 'ײ': 'יי'}
LATIN_LANGS = {'english', 'german', 'french', 'polish', 'spanish', 'italian', 'dutch', 'portuguese',
               'czech', 'hungarian', 'romanian', 'ladino'}
CYRILLIC_LANGS = {'russian', 'ukrainian', 'belarusian', 'bulgarian'}
HEBREW_LANGS = {'yiddish', 'hebrew', 'ladino', 'aramaic'}
GREEK_LANGS = {'greek'}
ARABIC_LANGS = {'arabic', 'persian', 'urdu', 'pashto'}
SCRIPT_LANGS = {'latin': LATIN_LANGS, 'cyrillic': CYRILLIC_LANGS, 'hebrew': HEBREW_LANGS,
                'greek': GREEK_LANGS, 'arabic': ARABIC_LANGS}
KNOWN_LANGS = set().union(*SCRIPT_LANGS.values())
DEFAULT_LANG = {'latin': 'english', 'cyrillic': 'russian', 'hebrew': 'hebrew', 'greek': 'greek', 'arabic': 'arabic'}
COLUMNS = 6   # languages with a column of their own
CODES = {'yid': 'yiddish', 'yi': 'yiddish', 'heb': 'hebrew', 'he': 'hebrew', 'eng': 'english', 'en': 'english',
         'rus': 'russian', 'ru': 'russian', 'ger': 'german', 'deu': 'german', 'de': 'german',
         'pol': 'polish', 'pl': 'polish', 'fre': 'french', 'fra': 'french', 'fr': 'french',
         'spa': 'spanish', 'es': 'spanish', 'ukr': 'ukrainian', 'uk': 'ukrainian'}


def unescape_mysql(s):
    """mysql -B escapes tabs, newlines and backslashes."""
    return re.sub(r'\\(.)', lambda m: {'n': '\n', 't': '\t', 'r': '\r', '0': '', '\\': '\\'}.get(m.group(1), m.group(1)), s)


def clean(s):
    return re.sub(r'\s+', ' ', unicodedata.normalize('NFC', s).replace(' ', ' ')).strip()


def key(s):
    """Grouping key: no case, punctuation, dates, word order, points/accents, ligatures."""
    s = ''.join(LIGATURES.get(c, c) for c in s)
    s = unicodedata.normalize('NFKD', s)
    s = ''.join(c for c in s if not unicodedata.combining(c))
    s = s.lower().replace('־', ' ')
    s = re.sub(r'\b(b|d|fl|ca)\.\s*(?=\d)', ' ', s)
    s = re.sub(r'[\d?]+', ' ', s)
    s = re.sub(r'[^\w\s]', ' ', s)
    return ' '.join(sorted(s.split()))


def script(s):
    counts = Counter()
    for c in s:
        if '\u0590' <= c <= '\u05ff' or '\ufb1d' <= c <= '\ufb4f':
            counts['hebrew'] += 1
        elif '\u0400' <= c <= '\u04ff':
            counts['cyrillic'] += 1
        elif '\u0370' <= c <= '\u03ff':
            counts['greek'] += 1
        elif '\u0600' <= c <= '\u06ff':
            counts['arabic'] += 1
        elif c.isalpha():
            counts['latin'] += 1
    return counts.most_common(1)[0][0] if counts else 'latin'


def lang_name(value):
    v = value.strip().lower()
    return CODES.get(v, v)


def fits(lang, alphabet):
    """A language fits an alphabet if it is known to use it, or isn't in the lists above."""
    return lang in SCRIPT_LANGS[alphabet] or lang not in KNOWN_LANGS


def choose_language(text, item_languages, usual):
    """(language, guessed?) for a spelling, from its items' languages and its script."""
    alphabet = script(text)
    for lang, _ in item_languages.most_common():
        if fits(lang, alphabet):
            return lang, False
    return usual.get(alphabet) or DEFAULT_LANG[alphabet], True


def main(src, dst):
    spellings = defaultdict(lambda: {'items': 0, 'langs': Counter(), 'fields': set()})
    with open(src, encoding='utf-8', newline='') as f:
        reader = csv.DictReader(f, delimiter='\t', quoting=csv.QUOTE_NONE)
        for row in reader:
            text = clean(unescape_mysql(row.get('name') or ''))
            if not text:
                continue
            sp = spellings[text]
            n = int(row.get('items') or 0)
            sp['items'] += n
            sp['fields'].add(row.get('field') or '')
            for l in (row.get('item_languages') or '').split(';'):
                if l.strip() and l.strip().upper() != 'NULL':
                    sp['langs'][lang_name(l)] += n

    # The language most used on items for names in each alphabet: the guess when a
    # spelling's own items have no language that fits.
    by_alphabet = defaultdict(Counter)
    for text, sp in spellings.items():
        alphabet = script(text)
        for lang, n in sp['langs'].items():
            if fits(lang, alphabet):
                by_alphabet[alphabet][lang] += n
    usual = {a: c.most_common(1)[0][0] for a, c in by_alphabet.items() if c}

    groups = defaultdict(list)
    for text, sp in spellings.items():
        k = key(text)
        if k:
            groups[k].append((text, sp))

    out = []
    for k, members in groups.items():
        members.sort(key=lambda m: (-m[1]['items'], m[0]))
        forms, variants, check = {}, [], []
        for text, sp in members:
            lang, guessed = choose_language(text, sp['langs'], usual)
            if lang not in forms:
                forms[lang] = text
                if guessed:
                    check.append('language of "%s" guessed from its script' % text)
            else:
                variants.append(text)
        alphabets = defaultdict(list)
        for lang, text in forms.items():
            alphabets[script(text)].append(lang)
        for langs in alphabets.values():
            if len(langs) > 1:
                check.append('%s forms share an alphabet and their languages were assigned automatically: check which is which'
                             % ' and '.join(l.capitalize() for l in langs))
        if len(members) > 1:
            check.append('%d spellings grouped: check they are one person' % len(members))
        total = sum(sp['items'] for _, sp in members)
        out.append({
            'authoritative': '',
            'forms': forms,
            'variants_for_search': ' | '.join(variants),
            'viaf_id': '',
            'lc_id': '',
            'notes': '',
            'items': total,
            'check': '; '.join(check),
        })
    # Columns for the most-used languages; the rest go in other_languages.
    use = Counter()
    for r in out:
        for lang in r['forms']:
            use[lang] += r['items']
    lang_cols = [l for l, _ in use.most_common(COLUMNS)]
    for r in out:
        for lang in lang_cols:
            r[lang] = r['forms'].get(lang, '')
        r['other_languages'] = '; '.join('%s: %s' % (l.capitalize(), t) for l, t in r['forms'].items() if l not in lang_cols)
    out.sort(key=lambda r: (-r['items'], sorted(r['forms'].values())))

    cols = ['authoritative'] + lang_cols + ['other_languages',
            'variants_for_search', 'viaf_id', 'lc_id', 'notes', 'items', 'check']
    with open(dst, 'w', encoding='utf-8', newline='') as f:
        w = csv.DictWriter(f, fieldnames=cols, delimiter='\t', quoting=csv.QUOTE_NONE, escapechar='\\', lineterminator='\n')
        w.writeheader()
        for r in out:
            w.writerow({c: (str(r[c]).replace('\t', ' ').replace('\n', ' ')) for c in cols})
    print('%d spellings -> %d rows in %s' % (len(spellings), len(out), dst))


if __name__ == '__main__':
    if len(sys.argv) != 3:
        sys.exit('usage: python3 make-starter-sheet.py names.tsv starter.tsv')
    main(sys.argv[1], sys.argv[2])
