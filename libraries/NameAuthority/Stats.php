<?php
/**
 * Counts for the overview on the Name Authority page, and the record sets
 * behind them (for the browse filters).
 *
 * @copyright Raphael Halff
 * @license GPLv3 http://www.gnu.org/licenses/gpl-3.0.txt
 */
class NameAuthority_Stats
{
    /** @return array */
    public static function compute()
    {
        $db = get_db();
        $a = $db->NameAuthority;
        $f = $db->NameAuthorityForm;
        $s = array();

        $row = $db->fetchRow("SELECT COUNT(*) AS total,
                SUM(authoritative IS NULL OR authoritative = '') AS no_auth,
                SUM(viaf_id IS NOT NULL AND viaf_id <> '') AS viaf,
                SUM(lc_id IS NOT NULL AND lc_id <> '') AS lc
            FROM `$a`");
        foreach ($row as $k => $v) {
            $s[$k] = (int) $v;
        }

        // Records by number of languages with a form.
        $s['langs'] = array('0' => 0, '1' => 0, '2' => 0, '3+' => 0);
        $rows = $db->fetchAll("SELECT n, COUNT(*) AS c FROM (
                SELECT a.id, COUNT(DISTINCT f.language) AS n FROM `$a` a
                LEFT JOIN `$f` f ON f.authority_id = a.id AND f.kind = 'form'
                GROUP BY a.id) x GROUP BY n");
        foreach ($rows as $r) {
            $s['langs'][$r['n'] >= 3 ? '3+' : (string) $r['n']] += (int) $r['c'];
        }

        // Records with a form in each language.
        $s['by_language'] = array();
        foreach ($db->fetchAll("SELECT language, COUNT(DISTINCT authority_id) AS c FROM `$f`
                WHERE kind = 'form' AND language IS NOT NULL AND language <> ''
                GROUP BY language ORDER BY c DESC, language") as $r) {
            $s['by_language'][$r['language']] = (int) $r['c'];
        }

        $row = $db->fetchRow("SELECT COUNT(DISTINCT authority_id) AS records, COUNT(*) AS variants FROM `$f` WHERE kind = 'variant'");
        $s['with_variants'] = (int) $row['records'];
        $s['variants'] = (int) $row['variants'];

        $s['unused'] = count(self::unusedIds());
        // Names with no record: topic fields counted separately.
        $topics = array_flip(self::topicElementIds());
        $items = $subjectItems = array();
        $s['unmatched_values'] = $s['unmatched_subject'] = 0;
        foreach (self::unmatchedValues() as $u) {
            if (isset($topics[$u['element_id']])) {
                $s['unmatched_subject']++;
                $subjectItems += array_flip($u['items']);
                continue;
            }
            $s['unmatched_values']++;
            $items += array_flip($u['items']);
        }
        $s['unmatched_items'] = count($items);
        $s['unmatched_subject_items'] = count($subjectItems);
        $s['subject_only'] = count(self::subjectOnlyIds());
        $s['duplicates'] = count(NameAuthority_Duplicates::pairs());
        return $s;
    }

    /** Ids of records whose names are on no item. */
    public static function unusedIds()
    {
        $ids = array();
        foreach (get_db()->fetchCol('SELECT id FROM `' . get_db()->NameAuthority . '`') as $id) {
            if (!NameAuthorityPlugin::instanceItemIds((int) $id)) {
                $ids[] = (int) $id;
            }
        }
        return $ids;
    }

    /** Name fields that mostly hold topics (a plugin setting; Dublin Core Subject by default). */
    public static function topicElementIds()
    {
        return NameAuthorityPlugin::topicElementIds();
    }

    /** Ids of records whose names appear on items only in topic fields. */
    public static function subjectOnlyIds()
    {
        $topics = self::topicElementIds();
        if (!$topics) {
            return array();
        }
        $plugin = new NameAuthorityPlugin;
        $db = get_db();
        $fieldsByKey = array();
        foreach ($db->fetchAll($db->select()->from($db->ElementText, array('element_id', 'text'))
                ->where("record_type = 'Item'")->where('element_id IN (?)', $plugin->getElementIds())) as $r) {
            $fieldsByKey[NameAuthorityPlugin::matchKey($r['text'])][(int) $r['element_id']] = true;
        }
        $topics = array_flip($topics);
        $used = array();
        foreach ($db->getTable('NameAuthorityForm')->fetchAllForms() as $f) {
            if (isset($fieldsByKey[$f['match_key']])) {
                $used[(int) $f['authority_id']] = (isset($used[(int) $f['authority_id']]) ? $used[(int) $f['authority_id']] : array()) + $fieldsByKey[$f['match_key']];
            }
        }
        $out = array();
        foreach ($used as $aid => $fields) {
            if (!array_diff_key($fields, $topics)) {
                $out[] = $aid;
            }
        }
        sort($out);
        return $out;
    }

    /** Ids of records with forms in exactly $n languages ("3+" for three or more). */
    public static function idsByLanguageCount($n)
    {
        $db = get_db();
        $having = $n === '3+' ? 'n >= 3' : 'n = ' . (int) $n;
        return array_map('intval', $db->fetchCol("SELECT id FROM (
                SELECT a.id, COUNT(DISTINCT f.language) AS n FROM `{$db->NameAuthority}` a
                LEFT JOIN `{$db->NameAuthorityForm}` f ON f.authority_id = a.id AND f.kind = 'form'
                GROUP BY a.id) x WHERE $having"));
    }

    /**
     * Values in the name fields that match no name record.
     *
     * @return array list of array('text', 'element', 'items' => [ids]), most items first
     */
    public static function unmatchedValues()
    {
        $plugin = new NameAuthorityPlugin;
        $elementIds = $plugin->getElementIds();
        if (!$elementIds) {
            return array();
        }
        $db = get_db();
        $names = array();
        foreach ($db->fetchAll("SELECT id, name FROM `{$db->Element}` WHERE id IN (" . implode(',', array_map('intval', $elementIds)) . ")") as $r) {
            $names[(int) $r['id']] = $r['name'];
        }
        $rows = $db->fetchAll($db->select()
            ->from($db->ElementText, array('record_id', 'element_id', 'text'))
            ->where("record_type = 'Item'")
            ->where('element_id IN (?)', $elementIds));
        $out = array();
        foreach ($rows as $r) {
            if (NameAuthorityPlugin::findAuthorityId($r['text'])) {
                continue;
            }
            $text = NameAuthorityPlugin::cleanText($r['text']);
            if ($text === '') {
                continue;
            }
            $key = NameAuthorityPlugin::matchKey($text) . "\x1f" . $r['element_id'];
            if (!isset($out[$key])) {
                $out[$key] = array('text' => $text, 'element_id' => (int) $r['element_id'],
                    'element' => isset($names[(int) $r['element_id']]) ? $names[(int) $r['element_id']] : '', 'items' => array());
            }
            $out[$key]['items'][(int) $r['record_id']] = (int) $r['record_id'];
        }
        $out = array_values($out);
        foreach ($out as &$o) {
            $o['items'] = array_values($o['items']);
        }
        unset($o);
        usort($out, function ($x, $y) {
            return count($y['items']) - count($x['items']) ?: strcmp($x['text'], $y['text']);
        });
        return $out;
    }
}
