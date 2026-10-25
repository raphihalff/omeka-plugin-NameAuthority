# Building an import sheet

Name Authority can start empty: records can be added one at a time from **Name Authority → Names with no record**, and the item-form suggestions keep new items consistent. On a collection that already has hundreds of names, though, it is quicker to build a sheet of name records and import it once. This page describes the sheet and one way to make it from the items you already have.

## The sheet

A tab-separated text file (TSV, UTF-8), one row per person or organization, with a header row. Columns can be in any order and empty cells are fine. See [`examples/sample-authorities.tsv`](../examples/sample-authorities.tsv).

| Column | What goes in it |
|---|---|
| `authoritative` | The authoritative heading, usually the Library of Congress form: *Tolstoy, Leo, graf, 1828-1910*. Optional. |
| `english`, `german`, `lithuanian`, … | The form to use on items in that language. Any column not named on this page is read as a language, and its header is the language's name. |
| `other_languages` | Forms in languages that don't have their own column: `Polish: Lew Tołstoj; Italian: Lev Tolstoj`. |
| `variants_for_search` | Other names and spellings that should find the person but aren't shown on items, separated by ` \| `: real names, pseudonyms, older romanizations. |
| `viaf_id`, `lc_id` | VIAF and LC identifiers, if known: `56947970`, `n79076448`. |
| `notes` | Private notes for cataloguers. |

**Own column or `other_languages`?** They import the same way. Give a language its own column if many rows have a form in it, since one name per cell is easier to edit and check. Use `other_languages` for the odd form in a language few rows need. After importing, Name Authority lists the columns it read as languages, so a typo (`gernan`) or a stray column (`source`) shows up; remove it and import again.

A row needs at least one name in any column. Every name in a row (authoritative, forms, variants) is matched against the values on your items, ignoring case, spacing, brackets, hyphens and ligatures. Variants can also link names that aren't spellings of each other: listing *Clemens, Samuel Langhorne* as a variant of Mark Twain, for instance, means a search for *Clemens* finds Twain's items.

## From your items, in four steps

### 1. Export the names on your items

[`tools/export-names.sql`](../tools/export-names.sql) lists every distinct Creator and Contributor value with the number of items using it and those items' languages. It only reads the database.

```
mysql -u USER -p DATABASE --default-character-set=utf8mb4 -B < export-names.sql > names.tsv
```

No command line? Paste the query into phpMyAdmin's SQL tab and export the result as CSV with tabs as the separator. Edit the field list in the query to include Subject or other fields, and change the `omeka_` prefix if yours differs.

### 2. Make a starter sheet

[`tools/make-starter-sheet.py`](../tools/make-starter-sheet.py) (Python 3, nothing to install) turns the export into a sheet in the format above:

```
python3 make-starter-sheet.py names.tsv starter.tsv
```

It groups spellings that differ only in capitals, spacing, punctuation, dates, word order, vowel points or accents, and ligatures (*Smith, Jane A.* and *Smith, Jane A., 1950-*; *Tolstoï, Léon* and *Tolstoi, Leon*). In each group the most-used spelling becomes the form for its language and the rest become search variants. The language comes from the items' Language field. Where that doesn't fit the spelling's alphabet, it is guessed: the language your items most often use for names in that alphabet. Your most-used languages get columns of their own; the rest go in `other_languages`. An `items` column shows how much each row matters, and a `check` column says what to look at. Both are ignored when importing.

### 3. Review it

Open `starter.tsv` in a spreadsheet (or in [OpenRefine](https://openrefine.org/), whose *Cluster and edit* finds looser spelling matches) and work down from the most-used rows:

- **Join the same person in different scripts.** *Tolstoy, Leo* and *Толстой, Лев* start as two rows: move the forms into one row and delete the other. This can also be done after importing, with *Compare and merge* in the plugin.
- **Split different people who share a name** that the script grouped together. A `check` note marks every grouped row.
- **Check the language columns**, especially where languages share an alphabet. The language of each spelling was decided automatically, from item languages or a guess from the alphabet, and can be wrong: a Hebrew-script name could be Yiddish or Hebrew, a Cyrillic one Russian or Ukrainian. The `check` column marks guesses, and rows with two forms in the same alphabet.
- **Add authoritative headings and IDs** where you know them, or leave them for later: the plugin's *Look up in VIAF* fills in VIAF and LC numbers and offers LC's heading.
- **Leave out rows you don't want**, such as one-off names or non-names in the fields you exported.

Save as **tab-separated, UTF-8**. In LibreOffice: *Save As → Text CSV*, field delimiter *Tab*, character set *UTF-8*. In Excel: *Save As → UTF-16 Unicode Text* is tab-separated but not UTF-8, so prefer LibreOffice, Google Sheets (*File → Download → Tab-separated values*) or OpenRefine (*Export → Tab-separated value*).

### 4. Import

**Name Authority → Import TSV**:

- **Replace everything** the first time;
- **Add new names** afterwards: rows whose names already have a record are skipped.

Then:

- click **Re-index search**, so the site search finds variants;
- go through **Names with no record**: anything you left out or that didn't match shows up there;
- go through **Possible duplicates**: rows that should have been one person show up there, ready to merge.

**Export TSV** gives the records back in the same format, so the sheet can go back and forth between the plugin and your spreadsheet.

## Do the items need changing?

No. Because every spelling in a record is matched, the plugin works with your items as they are. Clicking any spelling finds them all, and searches find variants. If you also want the items themselves to use one form per language, change them in Omeka (or in bulk, after a database backup). The plugin's *Names with no record* list then tells you when you're done.
