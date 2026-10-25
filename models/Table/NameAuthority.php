<?php
/**
 * @copyright Raphael Halff
 * @license GPLv3 http://www.gnu.org/licenses/gpl-3.0.txt
 */
class Table_NameAuthority extends Omeka_Db_Table
{
    public function applySearchFilters($select, $params)
    {
        $alias = $this->getTableAlias();
        if (isset($params['q']) && trim($params['q']) !== '') {
            $db = $this->getDb();
            $q = '%' . trim($params['q']) . '%';
            // Exact substring, or (ignoring ligatures, spacing, hyphens, vowel points and accents) every word.
            $ids = array_map('intval', NameAuthorityPlugin::findAuthorityIdsContaining($params['q'], true));
            $select->where("`$alias`.`id` IN (SELECT f.authority_id FROM `{$db->NameAuthorityForm}` f WHERE f.text LIKE ?)"
                . ($ids ? " OR `$alias`.`id` IN (" . implode(',', $ids) . ")" : ''), $q);
        }
        if (!empty($params['missing_authoritative'])) {
            $select->where("(`$alias`.`authoritative` IS NULL OR `$alias`.`authoritative` = '')");
        }
        if (!empty($params['has_viaf'])) {
            $select->where("`$alias`.`viaf_id` <> ''");
        }
        if (!empty($params['no_viaf'])) {
            $select->where("(`$alias`.`viaf_id` IS NULL OR `$alias`.`viaf_id` = '')");
        }
        if (!empty($params['has_lc'])) {
            $select->where("`$alias`.`lc_id` <> ''");
        }
        $db = $this->getDb();
        if (!empty($params['has_variants'])) {
            $select->where("`$alias`.`id` IN (SELECT f.authority_id FROM `{$db->NameAuthorityForm}` f WHERE f.kind = 'variant')");
        }
        if (isset($params['language']) && $params['language'] !== '') {
            $select->where("`$alias`.`id` IN (SELECT f.authority_id FROM `{$db->NameAuthorityForm}` f WHERE f.kind = 'form' AND f.language = ?)", $params['language']);
        }
        if (isset($params['ids']) && is_array($params['ids'])) {
            $ids = array_map('intval', $params['ids']);
            $select->where($ids ? "`$alias`.`id` IN (" . implode(',', $ids) . ")" : '0 = 1');
        }
        parent::applySearchFilters($select, array_diff_key($params, array_flip(array('q', 'missing_authoritative',
            'has_viaf', 'no_viaf', 'has_lc', 'has_variants', 'language', 'ids'))));
    }

    /**
     * Also sort by number of items ("items"), which isn't stored: the records
     * are ordered in PHP by item count (then name) and the database is told
     * that order.
     */
    public function applySorting($select, $sortField, $sortDir)
    {
        if ($sortField !== 'items') {
            parent::applySorting($select, $sortField, $sortDir);
            return;
        }
        $db = $this->getDb();
        $rows = $db->fetchPairs("SELECT id, authoritative FROM `{$db->NameAuthority}`");
        $counts = array();
        foreach ($rows as $id => $name) {
            $counts[(int) $id] = count(NameAuthorityPlugin::instanceItemIds((int) $id));
        }
        $desc = in_array(strtoupper((string) $sortDir), array('DESC', 'D'), true);
        uksort($counts, function ($a, $b) use ($counts, $rows, $desc) {
            if ($counts[$a] !== $counts[$b]) {
                return $desc ? $counts[$b] - $counts[$a] : $counts[$a] - $counts[$b];
            }
            return strcmp((string) $rows[$a], (string) $rows[$b]) ?: $a - $b;
        });
        if ($counts) {
            $select->order(new Zend_Db_Expr('FIELD(`' . $this->getTableAlias() . '`.`id`, ' . implode(',', array_keys($counts)) . ')'));
        }
    }

    protected function _getColumnPairs()
    {
        return array($this->getTableAlias() . '.id', $this->getTableAlias() . '.authoritative');
    }
}
