<?php
/**
 * One person (or corporate body / topic) with an authoritative name.
 *
 * @copyright Raphael Halff
 * @license GPLv3 http://www.gnu.org/licenses/gpl-3.0.txt
 */
class NameAuthority extends Omeka_Record_AbstractRecord
{
    public $authoritative = '';
    public $viaf_id = '';
    public $lc_id = '';
    public $notes = '';
    public $added;
    public $modified;

    protected function _initializeMixins()
    {
        $this->_mixins[] = new Mixin_Timestamp($this);
    }

    /**
     * All forms of this record (kind: authoritative | form | variant).
     *
     * @return NameAuthorityForm[]
     */
    public function getForms()
    {
        return $this->getTable('NameAuthorityForm')->findBy(array('authority_id' => (int) $this->id));
    }

    /** Language forms keyed by language, in the order they were saved. */
    public function getLanguageForms()
    {
        $out = array();
        foreach ($this->getForms() as $form) {
            if ($form->kind === 'form') {
                $out[] = array('language' => $form->language, 'text' => $form->text);
            }
        }
        return $out;
    }

    public function getVariants()
    {
        $out = array();
        foreach ($this->getForms() as $form) {
            if ($form->kind === 'variant') {
                $out[] = $form->text;
            }
        }
        return $out;
    }

    /** Best name to show: the authoritative name, or else the first language form. */
    public function getDisplayName()
    {
        if (trim((string) $this->authoritative) !== '') {
            return $this->authoritative;
        }
        $forms = $this->getLanguageForms();
        return $forms ? $forms[0]['text'] : __('(unnamed)');
    }

    /**
     * Replace this record's forms.
     *
     * @param array $languageForms list of array('language' => ..., 'text' => ...)
     * @param array $variants list of strings
     */
    public function saveForms(array $languageForms, array $variants)
    {
        $db = $this->getDb();
        $db->delete($db->NameAuthorityForm, $db->quoteInto('authority_id = ?', (int) $this->id));
        $seen = array();
        $add = function ($text, $language, $kind) use ($db, &$seen) {
            $text = NameAuthorityPlugin::cleanText($text);
            if ($text === '') {
                return;
            }
            $key = $kind . "\x1f" . $language . "\x1f" . $text;
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $db->insert('NameAuthorityForm', array(
                'authority_id' => (int) $this->id,
                'text' => $text,
                'match_key' => NameAuthorityPlugin::matchKey($text),
                'language' => $language,
                'kind' => $kind,
            ));
        };
        if (trim((string) $this->authoritative) !== '') {
            $add($this->authoritative, null, 'authoritative');
        }
        foreach ($languageForms as $lf) {
            $add($lf['text'], $lf['language'] !== '' ? $lf['language'] : null, 'form');
        }
        foreach ($variants as $v) {
            $add($v, null, 'variant');
        }
        NameAuthorityPlugin::clearCache();
    }

    protected function _validate()
    {
        if (strlen((string) $this->authoritative) > 500) {
            $this->addError('authoritative', __('The authoritative name is too long.'));
        }
    }

    protected function afterDelete()
    {
        $db = $this->getDb();
        $db->delete($db->NameAuthorityForm, $db->quoteInto('authority_id = ?', (int) $this->id));
        NameAuthorityPlugin::clearCache();
    }

    public function getRecordUrl($action = 'show')
    {
        if ($action === 'show') {
            return url('items/browse', array('name_authority' => $this->id));
        }
        return url(array('module' => 'name-authority', 'controller' => 'index', 'action' => $action, 'id' => $this->id), 'default');
    }

    public function getResourceId()
    {
        return 'NameAuthority_Index';
    }
}
