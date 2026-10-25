<?php
/**
 * Import / export of name records as tab-separated text.
 *
 * Columns (any order, header row required):
 *   authoritative, viaf_id, lc_id, notes,
 *   one column per language, headed by its name (english, german, lithuanian, ...):
 *     every column not listed in $_reserved is read as a language,
 *   other_languages  "German: form; Polish: form",
 *   variants_for_search  "form | form | ...",
 *   replaced_on_items    "form | form | ..."   (also kept as search variants)
 *
 * Export writes the same columns, so a file can be exported, edited and imported again.
 *
 * @copyright Raphael Halff
 * @license GPLv3 http://www.gnu.org/licenses/gpl-3.0.txt
 */
class NameAuthority_Importer
{
    const LIST_SEP = ' | ';

    /** Columns that are not languages. */
    protected static $_reserved = array(
        'id', 'group', 'authoritative', 'authoritative_status', 'grouping', 'viaf_id', 'lc_id', 'notes',
        'other_languages', 'variants_for_search', 'variants', 'replaced_on_items', 'items', 'check',
        // columns of tools/export-names.sql, in case that file is imported as it is
        'name', 'field', 'item_languages',
    );

    /** Headers of the last parsed sheet that were read as languages ("German", "Lithuanian"). */
    public static $languageColumns = array();

    /**
     * Parse TSV text into records.
     *
     * @return array list of array(authoritative, viaf_id, lc_id, notes, forms => [[language, text]], variants => [])
     */
    public static function parse($tsv)
    {
        $tsv = preg_replace('/^\xEF\xBB\xBF/', '', (string) $tsv);   // BOM
        $lines = preg_split('/\r\n|\n|\r/', $tsv);
        $header = null;
        $records = array();
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = explode("\t", $line);
            if ($header === null) {
                $labels = array();
                $header = array();
                foreach ($cells as $c) {
                    $c = trim(self::_unquote($c));
                    $h = strtolower($c);
                    $header[] = $h;
                    $labels[$h] = self::_languageLabel($c);
                }
                self::$languageColumns = array();
                foreach ($header as $h) {
                    if ($h !== '' && !in_array($h, self::$_reserved)) {
                        self::$languageColumns[] = $labels[$h];
                    }
                }
                continue;
            }
            $row = array();
            foreach ($header as $i => $h) {
                $row[$h] = isset($cells[$i]) ? self::_unquote($cells[$i]) : '';
            }
            $rec = array(
                'authoritative' => NameAuthorityPlugin::cleanText(isset($row['authoritative']) ? $row['authoritative'] : ''),
                'viaf_id' => trim(isset($row['viaf_id']) ? $row['viaf_id'] : ''),
                'lc_id' => trim(isset($row['lc_id']) ? $row['lc_id'] : ''),
                'notes' => trim(isset($row['notes']) && !isset($row['check']) ? $row['notes'] : ''),
                'forms' => array(),
                'variants' => array(),
            );
            // Every other column is a language: its header names it.
            foreach ($header as $h) {
                if ($h === '' || in_array($h, self::$_reserved) || $row[$h] === '') {
                    continue;
                }
                $rec['forms'][] = array('language' => $labels[$h], 'text' => $row[$h]);
            }
            if (!empty($row['other_languages'])) {
                foreach (preg_split('/\s*;\s*/u', $row['other_languages']) as $part) {
                    if (strpos($part, ':') !== false) {
                        list($lang, $text) = array_map('trim', explode(':', $part, 2));
                        if ($text !== '') {
                            $rec['forms'][] = array('language' => $lang, 'text' => $text);
                        }
                    }
                }
            }
            $formKeys = array();
            foreach ($rec['forms'] as $f) {
                $formKeys[NameAuthorityPlugin::matchKey($f['text'])] = true;
            }
            $formKeys[NameAuthorityPlugin::matchKey($rec['authoritative'])] = true;
            foreach (array('variants_for_search', 'variants', 'replaced_on_items') as $col) {
                if (empty($row[$col])) {
                    continue;
                }
                foreach (explode('|', $row[$col]) as $v) {
                    $v = NameAuthorityPlugin::cleanText($v);
                    $k = NameAuthorityPlugin::matchKey($v);
                    if ($v !== '' && !isset($formKeys[$k])) {
                        $rec['variants'][] = $v;
                        $formKeys[$k] = true;
                    }
                }
            }
            if ($rec['authoritative'] === '' && !$rec['forms'] && !$rec['variants']) {
                continue;
            }
            $records[] = $rec;
        }
        return $records;
    }

    /**
     * Save parsed records.
     *
     * @param string $mode 'replace' (delete everything first) or 'add' (skip names that already exist)
     * @return array counts
     */
    public static function import(array $records, $mode = 'add')
    {
        $db = get_db();
        $counts = array('added' => 0, 'skipped' => 0);
        if ($mode === 'replace') {
            $db->query("DELETE FROM `{$db->NameAuthorityForm}`");
            $db->query("DELETE FROM `{$db->NameAuthority}`");
        }
        NameAuthorityPlugin::clearCache();
        foreach ($records as $rec) {
            if ($mode !== 'replace') {
                $probe = $rec['authoritative'] !== '' ? $rec['authoritative'] : ($rec['forms'] ? $rec['forms'][0]['text'] : $rec['variants'][0]);
                if (NameAuthorityPlugin::findAuthorityId($probe)) {
                    $counts['skipped']++;
                    continue;
                }
            }
            $a = new NameAuthority;
            $a->authoritative = $rec['authoritative'];
            $a->viaf_id = $rec['viaf_id'];
            $a->lc_id = $rec['lc_id'];
            $a->notes = $rec['notes'];
            $a->save();
            $a->saveForms($rec['forms'], $rec['variants']);
            release_object($a);
            $counts['added']++;
        }
        NameAuthorityPlugin::clearCache();
        return $counts;
    }

    /** All records as TSV (same columns as the import). */
    public static function export()
    {
        $db = get_db();
        $authorities = $db->getTable('NameAuthority')->findBy(array('sort_field' => 'authoritative'));
        $langs = array();
        $rows = array();
        foreach ($authorities as $a) {
            $row = array('id' => $a->id, 'authoritative' => $a->authoritative, 'forms' => array(),
                'variants' => $a->getVariants(), 'viaf_id' => $a->viaf_id, 'lc_id' => $a->lc_id, 'notes' => $a->notes);
            foreach ($a->getLanguageForms() as $f) {
                $l = strtolower((string) $f['language']);
                if ($l === '') {
                    $l = 'other';
                }
                if (isset($row['forms'][$l])) {
                    $row['variants'][] = $f['text'];
                    continue;
                }
                $row['forms'][$l] = $f['text'];
                $langs[$l] = true;
            }
            $rows[] = $row;
        }
        // The four most-used languages get columns of their own; the rest go in other_languages.
        $main = array_slice(array_map('strtolower', array_keys(NameAuthorityPlugin::languagesInUse())), 0, 4);
        $out = array(implode("\t", array_merge(array('id', 'authoritative'), $main, array('other_languages', 'variants_for_search', 'viaf_id', 'lc_id', 'notes'))));
        foreach ($rows as $r) {
            $cells = array($r['id'], $r['authoritative']);
            foreach ($main as $l) {
                $cells[] = isset($r['forms'][$l]) ? $r['forms'][$l] : '';
            }
            $other = array();
            foreach ($r['forms'] as $l => $t) {
                if (!in_array($l, $main)) {
                    $other[] = ucfirst($l) . ': ' . $t;
                }
            }
            $cells[] = implode('; ', $other);
            $cells[] = implode(self::LIST_SEP, $r['variants']);
            $cells[] = $r['viaf_id'];
            $cells[] = $r['lc_id'];
            $cells[] = str_replace(array("\t", "\r", "\n"), ' ', (string) $r['notes']);
            $out[] = implode("\t", array_map(function ($c) {
                return str_replace(array("\t", "\r", "\n"), ' ', (string) $c);
            }, $cells));
        }
        return implode("\n", $out) . "\n";
    }

    /** Column header as a language name: "german" -> "German", "judeo_arabic" -> "Judeo Arabic"; capitals kept. */
    protected static function _languageLabel($header)
    {
        return ucwords(str_replace('_', ' ', $header));
    }

    /** Spreadsheet exports may wrap cells in quotes. */
    protected static function _unquote($cell)
    {
        $cell = trim($cell);
        if (strlen($cell) >= 2 && $cell[0] === '"' && substr($cell, -1) === '"') {
            $cell = str_replace('""', '"', substr($cell, 1, -1));
        }
        return $cell;
    }
}
