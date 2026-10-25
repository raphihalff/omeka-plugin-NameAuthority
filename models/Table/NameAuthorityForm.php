<?php
/**
 * @copyright Raphael Halff
 * @license GPLv3 http://www.gnu.org/licenses/gpl-3.0.txt
 */
class Table_NameAuthorityForm extends Omeka_Db_Table
{
    public function applySearchFilters($select, $params)
    {
        $alias = $this->getTableAlias();
        if (isset($params['authority_id'])) {
            $select->where("`$alias`.`authority_id` = ?", (int) $params['authority_id']);
            $select->order("$alias.id ASC");
        }
    }

    /**
     * Every form, as rows of (authority_id, text, match_key, language, kind).
     * Small enough to load at once (a few thousand rows).
     */
    public function fetchAllForms()
    {
        $select = $this->getSelect()->reset(Zend_Db_Select::COLUMNS)
            ->columns(array('authority_id', 'text', 'match_key', 'language', 'kind'))
            ->order($this->getTableAlias() . '.id ASC');
        return $this->getDb()->fetchAll($select);
    }
}
