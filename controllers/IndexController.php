<?php
/**
 * Admin pages: browse, add, edit, delete, import, export, re-index.
 *
 * @copyright Raphael Halff
 * @license GPLv3 http://www.gnu.org/licenses/gpl-3.0.txt
 */
class NameAuthority_IndexController extends Omeka_Controller_AbstractActionController
{
    public function init()
    {
        $this->_helper->db->setDefaultModelName('NameAuthority');
    }

    public function indexAction()
    {
        $this->_helper->redirector('browse');
    }

    /** Filters reachable from the overview counts: param => label. */
    protected function _filterLabels()
    {
        return array(
            'missing_authoritative' => __('without an authoritative name'),
            'has_viaf' => __('with a VIAF ID'),
            'no_viaf' => __('without a VIAF ID'),
            'has_lc' => __('with an LC ID'),
            'has_variants' => __('with search variants'),
            'unused' => __('not on any item'),
        );
    }

    /** List parameters kept when opening a record from the list (for Previous / Next). */
    protected static $_listKeys = array('q', 'missing_authoritative', 'has_viaf', 'no_viaf', 'has_lc', 'has_variants',
        'unused', 'language', 'langs', 'subject_only', 'sort_field', 'sort_dir', 'page');

    /**
     * Turn list parameters (from the request, or from an edit page's "from")
     * into table parameters plus labels for the active filters.
     */
    protected function _browseParams(array $src)
    {
        $get = function ($k, $default = null) use ($src) {
            return isset($src[$k]) && $src[$k] !== '' ? $src[$k] : $default;
        };
        $params = array(
            'q' => $get('q'),
            'sort_field' => $get('sort_field', 'authoritative'),
            'sort_dir' => $get('sort_dir', 'a'),
        );
        $active = array();
        foreach ($this->_filterLabels() as $key => $label) {
            if ($get($key)) {
                $params[$key] = 1;
                $active[$key] = $label;
            }
        }
        $language = trim((string) $get('language', ''));
        if ($language !== '') {
            $params['language'] = $language;
            $active['language'] = __('with a %s form', $language);
        }
        $langs = (string) $get('langs', '');
        if (in_array($langs, array('0', '1', '2', '3+'), true)) {
            $params['ids'] = NameAuthority_Stats::idsByLanguageCount($langs);
            $labels = array('0' => __('with no language form'), '1' => __('in one language'),
                '2' => __('in two languages'), '3+' => __('in three or more languages'));
            $active['langs'] = $labels[$langs];
        }
        if ($get('subject_only')) {
            $subjectOnly = NameAuthority_Stats::subjectOnlyIds();
            $params['ids'] = isset($params['ids']) ? array_values(array_intersect($params['ids'], $subjectOnly)) : $subjectOnly;
            $active['subject_only'] = __('used on items only in %s', implode(', ', NameAuthorityPlugin::elementNames(NameAuthorityPlugin::topicElementIds())));
        }
        if (!empty($params['unused'])) {
            $unused = NameAuthority_Stats::unusedIds();
            $params['ids'] = isset($params['ids']) ? array_values(array_intersect($params['ids'], $unused)) : $unused;
            unset($params['unused']);
        }
        return array($params, $active);
    }

    /** The list's query string, to carry into edit links. */
    protected function _listQuery(array $src)
    {
        $keep = array();
        foreach (self::$_listKeys as $k) {
            if (isset($src[$k]) && $src[$k] !== '') {
                $keep[$k] = $src[$k];
            }
        }
        // Never empty: the plain, unfiltered list is a list too.
        return $keep ? http_build_query($keep) : 'list=all';
    }

    /** Back to the list, on the page that holds this record. */
    protected function _listUrl($from, $nav)
    {
        parse_str((string) $from, $src);
        if ($nav && $nav['position']) {
            $src['page'] = (int) ceil($nav['position'] / 50);
            if ($src['page'] <= 1) {
                unset($src['page']);
            }
        }
        unset($src['list']);
        $query = http_build_query($src);
        return url('name-authority/index/browse') . ($query !== '' ? '?' . $query : '');
    }

    /** Ids of every record in the list described by $from, in list order. */
    protected function _listIds($from)
    {
        parse_str((string) $from, $src);
        list($params) = $this->_browseParams($src);
        $table = $this->_helper->db->getTable('NameAuthority');
        $select = $table->getSelectForFindBy($params);
        $select->reset(Zend_Db_Select::COLUMNS)->reset(Zend_Db_Select::LIMIT_COUNT)->reset(Zend_Db_Select::LIMIT_OFFSET)
            ->columns($table->getTableAlias() . '.id');
        return array_map('intval', $table->getDb()->fetchCol($select));
    }

    public function browseAction()
    {
        $src = $this->getRequest()->getQuery();
        list($params, $active) = $this->_browseParams($src);
        $perPage = 50;
        $page = max(1, (int) $this->getParam('page', 1));
        $table = $this->_helper->db->getTable('NameAuthority');
        $records = $table->findBy($params, $perPage, $page);
        $total = $table->count($params);
        Zend_Registry::set('pagination', array('page' => $page, 'per_page' => $perPage, 'total_results' => $total));
        $stats = NameAuthority_Stats::compute();
        $this->view->assign(array(
            'name_authorities' => $records,
            'total_results' => $total,
            'q' => $params['q'],
            'active_filters' => $active,
            'from' => $this->_listQuery($src),
            'grand_total' => $stats['total'],
            'stats' => $stats,
            'flagged' => NameAuthority_Duplicates::flaggedIds(),
            'duplicate_total' => $stats['duplicates'],
        ));
    }

    /** Values in the name fields that match no name record (topic fields on their own tab). */
    public function unmatchedAction()
    {
        $all = NameAuthority_Stats::unmatchedValues();
        $topics = array_flip(NameAuthority_Stats::topicElementIds());
        $showTopics = (bool) $this->getParam('subjects');
        $element = (int) $this->getParam('element');
        $byElement = array();
        $counts = array('names' => 0, 'subjects' => 0);
        foreach ($all as $u) {
            $isTopic = isset($topics[$u['element_id']]);
            $counts[$isTopic ? 'subjects' : 'names']++;
            if (!$isTopic) {
                $byElement[$u['element_id']] = array('name' => $u['element'],
                    'count' => (isset($byElement[$u['element_id']]) ? $byElement[$u['element_id']]['count'] : 0) + 1);
            }
        }
        $all = array_values(array_filter($all, function ($u) use ($topics, $showTopics, $element) {
            if ($element) {
                return $u['element_id'] === $element;
            }
            return isset($topics[$u['element_id']]) === $showTopics;
        }));
        $perPage = 100;
        $page = max(1, (int) $this->getParam('page', 1));
        Zend_Registry::set('pagination', array('page' => $page, 'per_page' => $perPage, 'total_results' => count($all)));
        $this->view->assign(array(
            'values' => array_slice($all, ($page - 1) * $perPage, $perPage),
            'total_results' => count($all),
            'by_element' => $byElement,
            'element' => $element,
            'show_topics' => $showTopics,
            'counts' => $counts,
            'topic_names' => NameAuthorityPlugin::elementNames(array_keys($topics)),
            'name_field_names' => NameAuthorityPlugin::elementNames(array_diff((new NameAuthorityPlugin)->getElementIds(), array_keys($topics))),
        ));
    }

    /** Confirm deleting the ticked records. */
    public function deleteManyConfirmAction()
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $this->getParam('ids')))));
        $records = $ids ? $this->_helper->db->getTable('NameAuthority')->findBy(array('ids' => $ids, 'sort_field' => 'authoritative')) : array();
        if (!$records) {
            $this->_helper->flashMessenger(__('Tick the names to delete first.'), 'error');
            $this->_helper->redirector('browse');
            return;
        }
        $this->view->assign(array('records' => $records, 'csrf' => new Omeka_Form_SessionCsrf));
    }

    public function deleteManyAction()
    {
        $csrf = new Omeka_Form_SessionCsrf;
        if ($this->getRequest()->isPost() && $csrf->isValid($_POST)) {
            $ids = array_values(array_unique(array_filter(array_map('intval', (array) $this->getParam('ids')))));
            $n = 0;
            foreach ($ids ? $this->_helper->db->getTable('NameAuthority')->findBy(array('ids' => $ids)) : array() as $r) {
                $r->delete();
                $n++;
            }
            NameAuthority_Duplicates::clearCache();
            $this->_helper->flashMessenger(__(plural('%d name record was deleted.', '%d name records were deleted.', $n), $n)
                . ' ' . __('Re-index the search for the site search to use the change.'), 'success');
        }
        $this->_helper->redirector('browse');
    }

    public function addAction()
    {
        $authority = new NameAuthority;
        $this->_edit($authority);
    }

    public function editAction()
    {
        $authority = $this->_helper->db->findById();
        $this->_edit($authority);
    }

    protected function _edit(NameAuthority $authority)
    {
        $csrf = new Omeka_Form_SessionCsrf;
        $from = (string) $this->getParam('from');
        $nav = null;
        if ($from !== '' && $authority->exists()) {
            $ids = $this->_listIds($from);
            $pos = array_search((int) $authority->id, $ids, true);
            $nav = array(
                'position' => $pos === false ? null : $pos + 1,
                'total' => count($ids),
                'prev' => $pos !== false && $pos > 0 ? $ids[$pos - 1] : null,
                'next' => $pos !== false && $pos < count($ids) - 1 ? $ids[$pos + 1] : null,
            );
        }
        $editUrl = function ($id) use ($from) {
            return url('name-authority/index/edit/id/' . (int) $id) . ($from !== '' ? '?' . http_build_query(array('from' => $from)) : '');
        };
        if ($this->getRequest()->isPost()) {
            if (!$csrf->isValid($_POST)) {
                $this->_helper->flashMessenger(__('There was an error on the form. Please try again.'), 'error');
            } else {
                $authority->authoritative = NameAuthorityPlugin::cleanText($this->getParam('authoritative'));
                $authority->viaf_id = trim((string) $this->getParam('viaf_id', $authority->viaf_id));
                $authority->lc_id = trim((string) $this->getParam('lc_id', $authority->lc_id));
                $authority->notes = trim((string) $this->getParam('notes'));
                list($forms, $errors) = self::parseFormLines($this->getParam('forms'));
                $variants = array_filter(array_map(array('NameAuthorityPlugin', 'cleanText'), preg_split('/\r\n|\n|\r/', (string) $this->getParam('variants'))));
                if ($errors) {
                    $this->_helper->flashMessenger(__('Each line under "Forms by language" must look like "Language: name", e.g. "German: Lew Tolstoi". Check: %s', implode(' / ', $errors)), 'error');
                } elseif ($authority->authoritative === '' && !$forms) {
                    $this->_helper->flashMessenger(__('Give at least an authoritative name or one language form.'), 'error');
                } elseif ($authority->save(false)) {
                    $authority->saveForms($forms, $variants);
                    $this->_helper->flashMessenger(__('The name "%s" was saved. Re-index the search for the site search to use the change.', $authority->getDisplayName()), 'success');
                    NameAuthority_Duplicates::clearCache();
                    $goNext = $this->getParam('save_next') && $nav && $nav['next'];
                    if ($dups = NameAuthority_Duplicates::pairsFor($authority->id)) {
                        $this->_helper->flashMessenger($goNext
                            ? __('"%1$s" may be a duplicate of %2$d other record(s); see Possible duplicates.', $authority->getDisplayName(), count($dups))
                            : __('This name may be a duplicate of %1$d other record(s). See "Possible duplicates" below.', count($dups)), 'alert');
                    }
                    // Next is worked out before saving: the save can take this record out of a filtered list.
                    $this->_helper->redirector->gotoUrl($editUrl($goNext ? $nav['next'] : $authority->id), array('prependBase' => false));
                    return;
                } else {
                    $this->_helper->flashMessenger($authority->getErrors());
                }
            }
        }
        $this->view->assign(array(
            'name_authority' => $authority,
            'csrf' => $csrf,
            'formsText' => $this->getRequest()->isPost() ? (string) $this->getParam('forms') : self::formLines($authority, (string) $this->getParam('name')),
            'variantsText' => $this->getRequest()->isPost() ? (string) $this->getParam('variants') : ($authority->exists() ? implode("\n", $authority->getVariants()) : ''),
            'itemCount' => $authority->exists() ? count(NameAuthorityPlugin::instanceItemIds($authority->id)) : 0,
            'showIds' => (bool) get_option('name_authority_show_ids'),
            'duplicates' => $authority->exists() ? $this->_pairRecords(NameAuthority_Duplicates::pairsFor($authority->id)) : array(),
            'from' => $from,
            'nav' => $nav,
            'listUrl' => $this->_listUrl($from, $nav),
            'prevUrl' => $nav && $nav['prev'] ? $editUrl($nav['prev']) : null,
            'nextUrl' => $nav && $nav['next'] ? $editUrl($nav['next']) : null,
        ));
        $this->render('edit');
    }

    public function deleteConfirmAction()
    {
        $authority = $this->_helper->db->findById();
        $this->view->assign(array('name_authority' => $authority, 'csrf' => new Omeka_Form_SessionCsrf));
    }

    public function deleteAction()
    {
        $authority = $this->_helper->db->findById();
        $csrf = new Omeka_Form_SessionCsrf;
        if ($this->getRequest()->isPost() && $csrf->isValid($_POST)) {
            $name = $authority->getDisplayName();
            $authority->delete();
            $this->_helper->flashMessenger(__('The name "%s" was deleted.', $name), 'success');
        }
        $this->_helper->redirector('browse');
    }

    public function importAction()
    {
        $csrf = new Omeka_Form_SessionCsrf;
        $this->view->csrf = $csrf;
        if (!$this->getRequest()->isPost()) {
            return;
        }
        if (!$csrf->isValid($_POST)) {
            $this->_helper->flashMessenger(__('There was an error on the form. Please try again.'), 'error');
            return;
        }
        if (empty($_FILES['tsv']['tmp_name']) || !is_uploaded_file($_FILES['tsv']['tmp_name'])) {
            $this->_helper->flashMessenger(__('Choose a TSV file to import.'), 'error');
            return;
        }
        $text = file_get_contents($_FILES['tsv']['tmp_name']);
        if (!mb_check_encoding($text, 'UTF-8')) {
            $this->_helper->flashMessenger(__('The file is not UTF-8. Export it from OpenRefine or your spreadsheet as UTF-8 tab-separated text.'), 'error');
            return;
        }
        $records = NameAuthority_Importer::parse($text);
        if (!$records) {
            $this->_helper->flashMessenger(__('No names found. The first row must be a header (authoritative, english, german, ...).'), 'error');
            return;
        }
        $mode = $this->getParam('mode') === 'replace' ? 'replace' : 'add';
        $counts = NameAuthority_Importer::import($records, $mode);
        $this->_helper->flashMessenger(__('Imported %1$d names (%2$d skipped because they already exist). Now re-index the search so the site search finds the variants.', $counts['added'], $counts['skipped']), 'success');
        if (NameAuthority_Importer::$languageColumns) {
            $this->_helper->flashMessenger(__('Columns read as languages: %s. If one of these is not a language, remove that column and import again.', implode(', ', NameAuthority_Importer::$languageColumns)), 'alert');
        }
        $this->_helper->redirector('browse');
    }

    public function exportAction()
    {
        $this->_helper->viewRenderer->setNoRender(true);
        $tsv = NameAuthority_Importer::export();
        $this->getResponse()
            ->setHeader('Content-Type', 'text/tab-separated-values; charset=utf-8', true)
            ->setHeader('Content-Disposition', 'attachment; filename="name-authorities-' . date('Y-m-d') . '.tsv"', true)
            ->setBody($tsv);
    }

    public function reindexAction()
    {
        $csrf = new Omeka_Form_SessionCsrf;
        if ($this->getRequest()->isPost() && $csrf->isValid($_POST)) {
            Zend_Registry::get('bootstrap')->getResource('jobs')->sendLongRunning('Job_SearchTextIndex');
            $this->_helper->flashMessenger(__('Re-indexing the search. This can take a few minutes; the site keeps working meanwhile.'), 'success');
        }
        $this->_helper->redirector('browse');
    }

    // ---- VIAF look-up ----------------------------------------------------------

    /** JSON: VIAF matches for ?q=, each with the other name records already using that ID. */
    public function viafAction()
    {
        $this->_helper->viewRenderer->setNoRender(true);
        $q = (string) $this->getParam('q');
        $self = (int) $this->getParam('id');
        $out = NameAuthority_Viaf::search($q);
        $db = get_db();
        foreach ($out['results'] as &$r) {
            $r['used_by'] = array();
            $rows = $db->fetchAll("SELECT id, authoritative FROM `{$db->NameAuthority}` WHERE (viaf_id = ? OR (lc_id <> '' AND lc_id = ?)) AND id <> ?",
                array($r['viaf'], $r['lc'], $self));
            foreach ($rows as $row) {
                $r['used_by'][] = array('id' => (int) $row['id'], 'name' => $row['authoritative'],
                    'url' => url('name-authority/index/edit/id/' . $row['id']));
            }
        }
        unset($r);
        $out['viaf_search'] = NameAuthority_Viaf::viafSearchUrl($q);
        $out['lc_search'] = NameAuthority_Viaf::lcSearchUrl($q);
        $this->getResponse()
            ->setHeader('Content-Type', 'application/json; charset=utf-8', true)
            ->setBody(json_encode($out));
    }

    /** JSON: suggestions for the item form (?term=&element=&lang[]=). */
    public function suggestAction()
    {
        $this->_helper->viewRenderer->setNoRender(true);
        $langs = array_filter(array_map('strval', (array) $this->getParam('lang')), 'strlen');
        $out = NameAuthority_Suggest::suggest((string) $this->getParam('term'), $langs, (int) $this->getParam('element'));
        $this->getResponse()
            ->setHeader('Content-Type', 'application/json; charset=utf-8', true)
            ->setBody(json_encode($out));
    }

    /** JSON: LC's heading for ?viaf=ID, taken from the VIAF record. */
    public function viafRecordAction()
    {
        $this->_helper->viewRenderer->setNoRender(true);
        $this->getResponse()
            ->setHeader('Content-Type', 'application/json; charset=utf-8', true)
            ->setBody(json_encode(NameAuthority_Viaf::lcHeading(trim((string) $this->getParam('viaf')))));
    }

    // ---- possible duplicates -------------------------------------------------

    public function duplicatesAction()
    {
        $only = (int) $this->getParam('id');
        $pairs = $only ? NameAuthority_Duplicates::pairsFor($only) : NameAuthority_Duplicates::pairs();
        $perPage = 25;
        $page = max(1, (int) $this->getParam('page', 1));
        $total = count($pairs);
        Zend_Registry::set('pagination', array('page' => $page, 'per_page' => $perPage, 'total_results' => $total));
        $this->view->assign(array(
            'pairs' => $this->_pairRecords(array_slice($pairs, ($page - 1) * $perPage, $perPage)),
            'total_results' => $total,
            'only' => $only ? $this->_helper->db->getTable('NameAuthority')->find($only) : null,
            'csrf' => new Omeka_Form_SessionCsrf,
        ));
    }

    /**
     * Merge any two records, suggested or not: pick the other record by search
     * (?id=X&q=...), or come from the list with two ticked (?ids[]=X&ids[]=Y).
     */
    public function mergeWithAction()
    {
        $table = $this->_helper->db->getTable('NameAuthority');
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $this->getParam('ids')))));
        $id = (int) $this->getParam('id');
        $other = (int) $this->getParam('other');
        if (count($ids) === 2) {
            list($id, $other) = $ids;
        } elseif ($ids) {
            $this->_helper->flashMessenger(__('Tick exactly two names to compare and merge them.'), 'error');
            $this->_helper->redirector('browse');
            return;
        }
        $record = $id ? $table->find($id) : null;
        if (!$record) {
            throw new Omeka_Controller_Exception_404;
        }
        $q = trim((string) $this->getParam('q'));
        $pair = null;
        $results = array();
        if ($other && $other !== $id && ($otherRecord = $table->find($other))) {
            $reasons = array();
            foreach (NameAuthority_Duplicates::pairsFor($id) as $p) {
                if ($p['a'] === $other || $p['b'] === $other) {
                    $reasons = $p['reasons'];
                }
            }
            $pair = array('a' => min($id, $other), 'b' => max($id, $other), 'reasons' => $reasons,
                'recA' => $record, 'recB' => $otherRecord);
        } elseif ($q !== '') {
            foreach ($table->findBy(array('q' => $q, 'sort_field' => 'authoritative'), 30) as $r) {
                if ((int) $r->id !== $id) {
                    $results[] = $r;
                }
            }
        }
        $this->view->assign(array(
            'record' => $record,
            'q' => $q,
            'results' => $results,
            'pair' => $pair,
            'csrf' => new Omeka_Form_SessionCsrf,
        ));
    }

    public function mergeAction()
    {
        $csrf = new Omeka_Form_SessionCsrf;
        $table = $this->_helper->db->getTable('NameAuthority');
        $target = $table->find((int) $this->getParam('target'));
        $source = $table->find((int) $this->getParam('source'));
        if (!$this->getRequest()->isPost() || !$csrf->isValid($_POST)) {
            $this->_helper->flashMessenger(__('There was an error on the form. Please try again.'), 'error');
        } elseif (!$target || !$source || $target->id === $source->id) {
            $this->_helper->flashMessenger(__('One of these name records no longer exists.'), 'error');
        } else {
            $name = $source->getDisplayName();
            NameAuthority_Duplicates::merge($target, $source);
            $this->_helper->flashMessenger(__('"%1$s" was merged into "%2$s". Re-index the search for the site search to use the change.', $name, $target->getDisplayName()), 'success');
            if ($this->getParam('return') === 'edit') {
                $this->_helper->redirector('edit', null, null, array('id' => $target->id));
                return;
            }
        }
        $this->_redirectToDuplicates();
    }

    public function notDuplicateAction()
    {
        $csrf = new Omeka_Form_SessionCsrf;
        if ($this->getRequest()->isPost() && $csrf->isValid($_POST)) {
            NameAuthority_Duplicates::ignore($this->getParam('a'), $this->getParam('b'));
            $this->_helper->flashMessenger(__('Marked as different people. This pair will not be suggested again.'), 'success');
        }
        if ($this->getParam('return') === 'edit') {
            $this->_helper->redirector('edit', null, null, array('id' => (int) $this->getParam('return_id')));
            return;
        }
        $this->_redirectToDuplicates();
    }

    protected function _redirectToDuplicates()
    {
        $params = array();
        if ($this->getParam('only')) {
            $params['id'] = (int) $this->getParam('only');
        }
        if ((int) $this->getParam('page') > 1) {
            $params['page'] = (int) $this->getParam('page');
        }
        $this->_helper->redirector->gotoUrl(url('name-authority/index/duplicates') . ($params ? '?' . http_build_query($params) : ''), array('prependBase' => false));
    }

    /** Load both records of each pair; skip pairs whose records are gone. */
    protected function _pairRecords(array $pairs)
    {
        $table = $this->_helper->db->getTable('NameAuthority');
        $out = array();
        foreach ($pairs as $p) {
            $a = $table->find($p['a']);
            $b = $table->find($p['b']);
            if ($a && $b) {
                $out[] = $p + array('recA' => $a, 'recB' => $b);
            }
        }
        return $out;
    }

    /** "Language: text" per line -> list of forms (+ list of bad lines). */
    public static function parseFormLines($text)
    {
        $forms = array();
        $errors = array();
        foreach (preg_split('/\r\n|\n|\r/', (string) $text) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (!preg_match('/^([^:]{2,40}):\s*(.+)$/u', $line, $m)) {
                $errors[] = $line;
                continue;
            }
            $forms[] = array('language' => ucfirst(trim($m[1])), 'text' => trim($m[2]));
        }
        return array($forms, $errors);
    }

    public static function formLines(NameAuthority $authority, $prefill = '')
    {
        if (!$authority->exists()) {
            // Offer the languages the other records use, most-used first; a name
            // being added goes under the most-used language written in its alphabet.
            $inUse = NameAuthorityPlugin::languagesInUse();
            $languages = array_slice(array_keys($inUse), 0, 3) ?: array('English');
            $prefill = NameAuthorityPlugin::cleanText($prefill);
            if ($prefill === '') {
                return implode(": \n", $languages) . ": \n";
            }
            $script = NameAuthorityPlugin::script($prefill);
            $lang = array_search($script, $inUse, true);
            if ($lang === false) {
                // No record yet in this alphabet: a first guess, easy to correct.
                $defaults = array('latin' => 'English', 'cyrillic' => 'Russian', 'hebrew' => 'Hebrew',
                    'greek' => 'Greek', 'arabic' => 'Arabic');
                $lang = $defaults[$script];
            }
            $lines = array($lang . ': ' . $prefill);
            foreach ($languages as $l) {
                if ($l !== $lang && count($lines) < 3) {
                    $lines[] = $l . ': ';
                }
            }
            return implode("\n", $lines) . "\n";
        }
        $lines = array();
        foreach ($authority->getLanguageForms() as $f) {
            $lines[] = ($f['language'] ? $f['language'] : 'Other') . ': ' . $f['text'];
        }
        return implode("\n", $lines);
    }
}
