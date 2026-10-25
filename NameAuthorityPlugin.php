<?php
/**
 * Name Authority: one record per person with an authoritative name, one form
 * per language and search variants.
 *
 * - Clicking a name (an "is exactly" search on a name field) finds the person's
 *   items under every form, in every language.
 * - Item search and site-wide search also find variants (e.g. Clemens -> Mark Twain).
 * - Optional: "also known as" line under names, a person banner above results,
 *   VIAF / LC identifiers.
 *
 * Matching between item values and name forms ignores case, extra spaces,
 * hyphens and dashes, brackets and ligatures, so it keeps working before and after metadata clean-ups.
 *
 * @copyright Raphael Halff
 * @license GPLv3 http://www.gnu.org/licenses/gpl-3.0.txt
 */

class NameAuthorityPlugin extends Omeka_Plugin_AbstractPlugin
{
    const ASSET_VERSION = '1.8.4';

    /** Language name => ISO code, for lang attributes. */
    const LANG_CODES = array(
        'english' => 'en', 'yiddish' => 'yi', 'hebrew' => 'he', 'russian' => 'ru', 'german' => 'de',
        'polish' => 'pl', 'french' => 'fr', 'spanish' => 'es', 'aramaic' => 'arc', 'ukrainian' => 'uk',
        'ladino' => 'lad', 'italian' => 'it', 'romanian' => 'ro', 'hungarian' => 'hu', 'czech' => 'cs',
    );

    protected $_hooks = array(
        'install',
        'uninstall',
        'upgrade',
        'initialize',
        'define_acl',
        'config_form',
        'config',
        'public_head',
        'admin_head',
        'public_content_top',
        'items_browse_sql',
    );

    protected $_filters = array(
        'admin_navigation_main',
        'items_browse_params',
        'search_element_texts',
    );

    protected $_options = array(
        'name_authority_element_ids' => '[]',      // Set on install: Dublin Core Creator, Contributor, Subject.
        'name_authority_topic_element_ids' => '[]', // Name fields that mostly hold topics. Set on install: Dublin Core Subject.
        'name_authority_authoritative_element_id' => 0, // Optional field for the authoritative heading.
        'name_authority_language_element_id' => 0, // Field with the item's language. Set on install: Dublin Core Language.
        'name_authority_expand_clicks' => 1,       // "is exactly" searches on a name field find every form of the person.
        'name_authority_expand_search' => 1,       // Item search + site search find search variants.
        'name_authority_show_aka' => 1,            // "Also:" line under names on item pages.
        'name_authority_aka_variants' => 0,        // ...including search variants.
        'name_authority_show_banner' => 1,         // Person banner above results for a name.
        'name_authority_show_ids' => 1,            // VIAF / LC fields and links.
        'name_authority_make_links' => 0,          // Link names ourselves (only if no other plugin does).
        'name_authority_not_duplicates' => '[]',   // Pairs marked "not the same" ("12-345").
        'name_authority_viaf_url' => '',           // Override the VIAF AutoSuggest URL (testing only).
        'name_authority_item_suggest' => 1,        // Suggest names from the records on the item form.
    );

    protected $_checkboxOptions = array(
        'name_authority_expand_clicks', 'name_authority_expand_search', 'name_authority_show_aka',
        'name_authority_aka_variants', 'name_authority_show_banner', 'name_authority_show_ids',
        'name_authority_make_links', 'name_authority_item_suggest',
    );

    // ---- per-request caches -------------------------------------------------
    protected static $_index = null;      // match key => authority id
    protected static $_forms = null;      // authority id => list of form rows
    protected static $_itemKeys = null;   // match key => array(item id => true)
    protected static $_bannerId = null;   // authority for the current results page

    // ==== install / upgrade ==================================================

    public function hookInstall()
    {
        $db = $this->_db;
        $db->query("CREATE TABLE IF NOT EXISTS `{$db->NameAuthority}` (
            `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `authoritative` varchar(500) NOT NULL DEFAULT '',
            `viaf_id` varchar(100) NOT NULL DEFAULT '',
            `lc_id` varchar(100) NOT NULL DEFAULT '',
            `notes` text,
            `added` timestamp NOT NULL DEFAULT '2000-01-01 00:00:00',
            `modified` timestamp NOT NULL DEFAULT '2000-01-01 00:00:00',
            PRIMARY KEY (`id`),
            KEY `authoritative` (`authoritative`(191))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->query("CREATE TABLE IF NOT EXISTS `{$db->NameAuthorityForm}` (
            `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `authority_id` int(10) unsigned NOT NULL,
            `text` varchar(500) NOT NULL,
            `match_key` varchar(500) NOT NULL DEFAULT '',
            `language` varchar(50) DEFAULT NULL,
            `kind` varchar(20) NOT NULL DEFAULT 'form',
            PRIMARY KEY (`id`),
            KEY `authority_id` (`authority_id`),
            KEY `match_key` (`match_key`(191))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->_options['name_authority_element_ids'] = json_encode($this->_defaultElementIds());
        $this->_options['name_authority_topic_element_ids'] = json_encode($this->_dublinCoreIds(array('Subject')));
        $this->_options['name_authority_language_element_id'] = (int) current($this->_dublinCoreIds(array('Language')) ?: array(0));
        $this->_installOptions();
    }

    public function hookUninstall()
    {
        $db = $this->_db;
        $db->query("DROP TABLE IF EXISTS `{$db->NameAuthorityForm}`");
        $db->query("DROP TABLE IF EXISTS `{$db->NameAuthority}`");
        $this->_uninstallOptions();
    }

    public function hookUpgrade($args)
    {
        if (version_compare($args['old_version'], '1.8.0', '<')) {
            if (get_option('name_authority_topic_element_ids') === null) {
                $subject = array_values(array_intersect($this->_dublinCoreIds(array('Subject')), $this->getElementIds()));
                set_option('name_authority_topic_element_ids', json_encode($subject));
            }
            if (get_option('name_authority_language_element_id') === null) {
                set_option('name_authority_language_element_id', (int) current($this->_dublinCoreIds(array('Language')) ?: array(0)));
            }
            if (get_option('name_authority_authoritative_element_id') === null) {
                set_option('name_authority_authoritative_element_id', 0);
            }
        }
        if (version_compare($args['old_version'], '1.7.0', '<') && get_option('name_authority_item_suggest') === null) {
            set_option('name_authority_item_suggest', 1);
        }
        if (version_compare($args['old_version'], '1.1.0', '<')) {
            if (get_option('name_authority_not_duplicates') === null) {
                set_option('name_authority_not_duplicates', '[]');
            }
            if (get_option('name_authority_viaf_url') === null) {
                set_option('name_authority_viaf_url', '');
            }
        }
    }

    public function hookInitialize()
    {
        add_translation_source(dirname(__FILE__) . '/languages');
        // Display filters for every configured name element. Priority 20 so we run
        // after plugins that turn the value into a search link.
        foreach ($this->getElements() as $element) {
            add_filter(
                array('Display', 'Item', $element->getElementSet()->name, $element->name),
                array($this, 'filterDisplayName'),
                20
            );
        }
    }

    public function hookDefineAcl($args)
    {
        $acl = $args['acl'];
        $acl->addResource('NameAuthority_Index');
        $acl->allow('admin', 'NameAuthority_Index');
        // Anyone who can add or edit items gets the item-form suggestions.
        $acl->allow('contributor', 'NameAuthority_Index', array('suggest'));
    }

    // ==== configuration ======================================================

    public function hookConfigForm($args)
    {
        $view = get_view();
        echo $view->partial('plugins/name-authority-config-form.php', array(
            'elementIds' => $this->getElementIds(),
            'topicIds' => self::topicElementIds(false),
            'authoritativeId' => self::authoritativeElementId(),
            'languageId' => self::languageElementId(),
            'elementOptions' => $this->_elementOptions(),
        ));
    }

    public function hookConfig($args)
    {
        $post = $args['post'];
        $ids = isset($post['name_authority_element_ids']) ? array_values(array_filter(array_map('intval', (array) $post['name_authority_element_ids']))) : array();
        set_option('name_authority_element_ids', json_encode($ids));
        // Topic fields and the authoritative-heading field only count if they are name fields too.
        $topics = isset($post['name_authority_topic_element_ids']) ? array_values(array_filter(array_map('intval', (array) $post['name_authority_topic_element_ids']))) : array();
        set_option('name_authority_topic_element_ids', json_encode(array_values(array_intersect($topics, $ids))));
        $auth = isset($post['name_authority_authoritative_element_id']) ? (int) $post['name_authority_authoritative_element_id'] : 0;
        set_option('name_authority_authoritative_element_id', in_array($auth, $ids, true) ? $auth : 0);
        set_option('name_authority_language_element_id', isset($post['name_authority_language_element_id']) ? (int) $post['name_authority_language_element_id'] : 0);
        foreach ($this->_checkboxOptions as $opt) {
            set_option($opt, !empty($post[$opt]) ? 1 : 0);
        }
        self::clearCache();
    }

    public function filterAdminNavigationMain($nav)
    {
        $nav[] = array(
            'label' => __('Name Authority'),
            'uri' => url('name-authority'),
            'resource' => 'NameAuthority_Index',
        );
        return $nav;
    }

    public function hookPublicHead($args)
    {
        queue_css_file('name-authority', 'all', false, 'css', self::ASSET_VERSION);
    }

    public function hookAdminHead($args)
    {
        queue_css_file('name-authority', 'all', false, 'css', self::ASSET_VERSION);
        $request = Zend_Controller_Front::getInstance()->getRequest();
        if ($request && $request->getModuleName() === 'name-authority') {
            queue_js_file('name-authority', 'javascripts', array(), self::ASSET_VERSION);
        }
        if ($request && get_option('name_authority_item_suggest')
            && in_array($request->getModuleName(), array(null, 'default'), true)
            && $request->getControllerName() === 'items'
            && in_array($request->getActionName(), array('add', 'edit'), true)
        ) {
            $this->_queueItemSuggest();
        }
    }

    /** Item form: suggestions from the name records on the name fields (combined with LC Suggest where both apply). */
    protected function _queueItemSuggest()
    {
        $db = $this->_db;
        $elementIds = array_map('intval', $this->getElementIds());
        if (!$elementIds) {
            return;
        }
        $languageId = self::languageElementId();
        $lc = array();
        if (plugin_is_active('LcSuggest')) {
            try {
                foreach ($db->fetchCol("SELECT element_id FROM `{$db->prefix}lc_suggests`") as $eid) {
                    if (in_array((int) $eid, $elementIds, true)) {
                        $lc[(int) $eid] = url('lc-suggest/index/suggest-endpoint-proxy/element-id/' . (int) $eid);
                    }
                }
            } catch (Exception $e) {
                $lc = array();
            }
        }
        $config = array(
            'endpoint' => url('name-authority/index/suggest'),
            'elements' => $elementIds,
            'languageElementId' => $languageId,
            'authoritativeElementId' => self::authoritativeElementId(),
            'lc' => (object) $lc,
            'text' => array(
                'items' => __('%d items'),
                'item' => __('1 item'),
                'matched' => __('matched: %s'),
                'lc' => __('LC'),
                'formsFor' => __('Forms for this item\'s language: %s'),
                'noLanguage' => __('No language set on this item yet: showing every form'),
            ),
        );
        queue_js_string('window.NameAuthoritySuggest = ' . json_encode($config) . ';');
        queue_js_file('name-authority-suggest', 'javascripts', array(), self::ASSET_VERSION);
    }

    // ==== search: clicked names, item search =================================

    /**
     * Turn "is exactly <name>" rows on name fields (what clickable metadata links
     * produce) and ?name_authority=ID into a search for every form of the person.
     */
    public function filterItemsBrowseParams($params)
    {
        $ids = array();
        if (!empty($params['name_authority'])) {
            $ids[] = (int) $params['name_authority'];
        }
        if (get_option('name_authority_expand_clicks') && !empty($params['advanced']) && is_array($params['advanced'])) {
            $elementIds = $this->getElementIds();
            $kept = array();
            $i = 0;
            foreach ($params['advanced'] as $row) {
                $isFirst = ($i === 0);
                $i++;
                if (is_array($row)
                    && isset($row['type'], $row['element_id'], $row['terms'])
                    && $row['type'] === 'is exactly'
                    && in_array((int) $row['element_id'], $elementIds)
                    && ($isFirst || empty($row['joiner']) || $row['joiner'] !== 'or')
                    && ($aid = self::findAuthorityId($row['terms']))
                ) {
                    $ids[] = $aid;
                    continue;
                }
                $kept[] = $row;
            }
            if (count($kept) !== count($params['advanced'])) {
                $params['advanced'] = $kept;
                if (!$kept) {
                    unset($params['advanced']);
                }
            }
        }
        if ($ids) {
            $params['_name_authority_ids'] = array_values(array_unique($ids));
        }
        return $params;
    }

    public function hookItemsBrowseSql($args)
    {
        $select = $args['select'];
        $params = $args['params'];

        if (!empty($params['_name_authority_ids'])) {
            foreach ($params['_name_authority_ids'] as $aid) {
                $itemIds = $this->itemIdsForAuthority($aid);
                $select->where('items.id IN (?)', $itemIds ? $itemIds : array(0));
            }
        }

        // Item search (?search=...): also match items whose names have a form or
        // variant containing the search words.
        if (get_option('name_authority_expand_search') && isset($params['search']) && trim((string) $params['search']) !== '') {
            $itemIds = array();
            foreach (self::findAuthorityIdsContaining($params['search']) as $aid) {
                $itemIds += array_flip($this->itemIdsForAuthority($aid));
            }
            if ($itemIds) {
                $this->_widenSimpleSearch($select, array_keys($itemIds));
            }
        }
    }

    /**
     * Add "items.id IN (...) OR" to the core simple-search condition, so the
     * extra items are alternatives to the core match (not a separate filter).
     */
    protected function _widenSimpleSearch($select, array $itemIds)
    {
        $parts = $select->getPart(Zend_Db_Select::WHERE);
        $in = 'items.id IN (' . implode(',', array_map('intval', $itemIds)) . ') OR ';
        $changed = false;
        foreach ($parts as $k => $part) {
            if (!$changed && strpos($part, '_simple_etx') !== false) {
                $new = preg_replace('/items\.id IN \(SELECT/', $in . 'items.id IN (SELECT', $part, 1, $count);
                if ($count) {
                    $parts[$k] = $new;
                    $changed = true;
                }
            }
        }
        if (!$changed) {
            return;
        }
        $select->reset(Zend_Db_Select::WHERE);
        foreach ($parts as $part) {
            if (preg_match('/^OR\s+(.*)$/s', $part, $m)) {
                $select->orWhere($m[1]);
            } else {
                $select->where(preg_replace('/^AND\s+/', '', $part));
            }
        }
    }

    // ==== search: site-wide index ===========================================

    /**
     * While a record is indexed, add every form and variant of the people named
     * in it, so the site search finds them under any name. Needs a re-index
     * (Name Authority > Re-index search) after names are changed.
     */
    public function filterSearchElementTexts($elementTexts)
    {
        if (!get_option('name_authority_expand_search')) {
            return $elementTexts;
        }
        $elementIds = array_flip($this->getElementIds());
        $extra = array();
        $done = array();
        foreach ($elementTexts as $et) {
            if (!isset($elementIds[(int) $et->element_id])) {
                continue;
            }
            $aid = self::findAuthorityId($et->text);
            if (!$aid || isset($done[$aid])) {
                continue;
            }
            $done[$aid] = true;
            $texts = array();
            foreach (self::formsFor($aid) as $f) {
                $texts[] = $f['text'];
            }
            $o = new stdClass;
            $o->element_id = 0;
            $o->text = implode(' ; ', array_unique($texts));
            $extra[] = $o;
        }
        return array_merge($elementTexts, $extra);
    }

    // ==== display ============================================================

    /** "Also:" line under a name, and optionally a link to the person's items. */
    public function filterDisplayName($text, $args)
    {
        if (empty($args['element_text']) || !is_object($args['element_text'])) {
            return $text;
        }
        $aid = self::findAuthorityId($args['element_text']->text);
        if (!$aid) {
            return $text;
        }
        if (get_option('name_authority_make_links') && stripos($text, '<a ') === false) {
            $text = '<a href="' . html_escape(url('items/browse', array('name_authority' => $aid))) . '">' . $text . '</a>';
        }
        if (!get_option('name_authority_show_aka') || is_admin_theme() || !self::_onItemPage()) {
            return $text;
        }
        $shownKey = self::matchKey($args['element_text']->text);
        $others = array();
        $seen = array($shownKey => true);
        foreach (self::formsFor($aid) as $f) {
            if ($f['kind'] === 'variant' && !get_option('name_authority_aka_variants')) {
                continue;
            }
            if (isset($seen[$f['match_key']])) {
                continue;
            }
            $seen[$f['match_key']] = true;
            $lang = $f['language'] ? self::langCode($f['language']) : '';
            $others[] = array($f['text'], '<span class="name-authority-form" dir="auto"' . ($lang ? ' lang="' . $lang . '"' : '') . '>' . html_escape($f['text']) . '</span>');
        }
        if (!$others) {
            return $text;
        }
        self::sortAlphabetically($others);
        $others = array_map(function ($o) {
            return $o[1];
        }, $others);
        return $text . ' <span class="name-authority-aka" dir="auto">' . __('also:') . ' ' . implode(' <span class="name-authority-sep">·</span> ', $others) . '</span>';
    }

    /**
     * Sort list of array(text, ...) alphabetically by text. Uses the intl
     * collator when available (handles accents, ligatures and
     * vowel points); otherwise sorts by the normalized match key. Either way
     * names group by script: Latin, then Cyrillic, then Hebrew script.
     */
    public static function sortAlphabetically(array &$list)
    {
        if (class_exists('Collator')) {
            $collator = new Collator('root');
            usort($list, function ($a, $b) use ($collator) {
                return $collator->compare(self::cleanText($a[0]), self::cleanText($b[0]));
            });
            return;
        }
        usort($list, function ($a, $b) {
            return strcmp(self::matchKey($a[0]), self::matchKey($b[0]));
        });
    }

    /** True on a public item page (the "also" line is not shown in lists). */
    protected static function _onItemPage()
    {
        $request = Zend_Controller_Front::getInstance()->getRequest();
        return $request && $request->getControllerName() === 'items' && $request->getActionName() === 'show';
    }

    /** Person banner above the results of a name search. */
    public function hookPublicContentTop($args)
    {
        if (!get_option('name_authority_show_banner')) {
            return;
        }
        $request = Zend_Controller_Front::getInstance()->getRequest();
        if (!$request || $request->getControllerName() !== 'items' || $request->getActionName() !== 'browse') {
            return;
        }
        $aid = $this->_authorityFromRequest($request);
        if (!$aid) {
            return;
        }
        $authority = $this->_db->getTable('NameAuthority')->find($aid);
        if (!$authority) {
            return;
        }
        echo get_view()->partial('common/name-authority-banner.php', array(
            'authority' => $authority,
            'showIds' => (bool) get_option('name_authority_show_ids'),
        ));
    }

    protected function _authorityFromRequest($request)
    {
        if ($request->getParam('name_authority')) {
            return (int) $request->getParam('name_authority');
        }
        if (!get_option('name_authority_expand_clicks')) {
            return null;
        }
        $advanced = $request->getParam('advanced');
        if (!is_array($advanced)) {
            return null;
        }
        $elementIds = $this->getElementIds();
        $found = array();
        foreach ($advanced as $row) {
            if (is_array($row) && isset($row['type'], $row['element_id'], $row['terms'])
                && $row['type'] === 'is exactly' && in_array((int) $row['element_id'], $elementIds)
                && ($aid = self::findAuthorityId($row['terms']))) {
                $found[$aid] = true;
            }
        }
        return count($found) === 1 ? key($found) : null;
    }

    // ==== lookups ============================================================

    /** Normalized key used to match item values to name forms. */
    public static function matchKey($text)
    {
        $s = self::cleanText($text);
        if (class_exists('Normalizer')) {
            $n = Normalizer::normalize($s, Normalizer::FORM_D);
            if ($n !== false) {
                $s = $n;
            }
        }
        // Ligatures and presentation forms -> letter sequences (e.g. Hebrew-script ײ -> יי).
        $s = strtr($s, array(
            "\u{05F0}" => "\u{05D5}\u{05D5}", "\u{05F1}" => "\u{05D5}\u{05D9}", "\u{05F2}" => "\u{05D9}\u{05D9}",
            "\u{FB1F}" => "\u{05D9}\u{05D9}\u{05B7}", "\u{FB2E}" => "\u{05D0}\u{05B7}", "\u{FB2F}" => "\u{05D0}\u{05B8}",
            "\u{FB35}" => "\u{05D5}\u{05BC}", "\u{FB4B}" => "\u{05D5}\u{05B9}", "\u{FB44}" => "\u{05E4}\u{05BC}",
            "\u{FB4E}" => "\u{05E4}\u{05BF}", "\u{FB4C}" => "\u{05D1}\u{05BF}", "\u{FB3B}" => "\u{05DB}\u{05BC}",
            "\u{FB4A}" => "\u{05EA}\u{05BC}", "\u{FB1D}" => "\u{05D9}\u{05B4}",
        ));
        $s = preg_replace('/[\[\]]/u', '', $s);
        $s = preg_replace('/[\x{05BE}\-\x{2010}-\x{2015}]/u', ' ', $s);   // maqaf, hyphens, dashes
        $s = preg_replace('/\s+/u', ' ', $s);
        return mb_strtolower(trim($s), 'UTF-8');
    }

    /** Trim spaces (incl. non-breaking), line breaks and direction marks; collapse spaces. */
    public static function cleanText($text)
    {
        $s = (string) $text;
        $s = preg_replace('/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $s);
        $s = preg_replace('/[\s\x{00A0}]+/u', ' ', $s);
        return trim($s);
    }

    public static function clearCache()
    {
        self::$_index = null;
        self::$_forms = null;
        self::$_itemKeys = null;
    }

    protected static function _load()
    {
        if (self::$_index !== null) {
            return;
        }
        self::$_index = array();
        self::$_forms = array();
        $db = get_db();
        foreach ($db->getTable('NameAuthorityForm')->fetchAllForms() as $row) {
            $aid = (int) $row['authority_id'];
            self::$_forms[$aid][] = $row;
            if ($row['match_key'] !== '' && !isset(self::$_index[$row['match_key']])) {
                self::$_index[$row['match_key']] = $aid;
            }
        }
    }

    /** Authority id for an item value, or null. */
    public static function findAuthorityId($text)
    {
        self::_load();
        $key = self::matchKey($text);
        return isset(self::$_index[$key]) ? self::$_index[$key] : null;
    }

    /** Authorities with a form or variant containing every word of the query. */
    /** Match key without vowel points and accents: for loose admin searches. */
    public static function looseKey($text)
    {
        return preg_replace('/\p{Mn}+/u', '', self::matchKey($text));
    }

    /**
     * Records with a form containing every word of $query.
     * $loose also ignores vowel points and accents (admin searches).
     */
    public static function findAuthorityIdsContaining($query, $loose = false)
    {
        self::_load();
        $key = $loose ? self::looseKey($query) : self::matchKey($query);
        $words = array_filter(explode(' ', $key), function ($w) {
            return mb_strlen($w, 'UTF-8') >= 2;
        });
        if (!$words) {
            return array();
        }
        $out = array();
        foreach (self::$_forms as $aid => $rows) {
            foreach ($rows as $row) {
                $all = true;
                $rowKey = $loose ? preg_replace('/\p{Mn}+/u', '', $row['match_key']) : $row['match_key'];
                foreach ($words as $w) {
                    if (mb_strpos($rowKey, $w, 0, 'UTF-8') === false) {
                        $all = false;
                        break;
                    }
                }
                if ($all) {
                    $out[$aid] = true;
                    break;
                }
            }
        }
        return array_keys($out);
    }

    public static function formsFor($aid)
    {
        self::_load();
        return isset(self::$_forms[$aid]) ? self::$_forms[$aid] : array();
    }

    /** Ids of items whose name fields hold any form of the authority. */
    public function itemIdsForAuthority($aid)
    {
        if (self::$_itemKeys === null) {
            self::$_itemKeys = array();
            $ids = $this->getElementIds();
            if ($ids) {
                $db = $this->_db;
                $rows = $db->fetchAll($db->select()
                    ->from($db->ElementText, array('record_id', 'text'))
                    ->where("record_type = 'Item'")
                    ->where('element_id IN (?)', $ids));
                foreach ($rows as $r) {
                    self::$_itemKeys[self::matchKey($r['text'])][(int) $r['record_id']] = true;
                }
            }
        }
        $items = array();
        foreach (self::formsFor($aid) as $f) {
            if (isset(self::$_itemKeys[$f['match_key']])) {
                $items += self::$_itemKeys[$f['match_key']];
            }
        }
        return array_keys($items);
    }

    /** Item ids for an authority, callable from views and controllers. */
    public static function instanceItemIds($aid)
    {
        static $plugin = null;
        if ($plugin === null) {
            $plugin = new self;
        }
        return $plugin->itemIdsForAuthority($aid);
    }

    /** 'hebrew', 'cyrillic', 'greek', 'arabic' or 'latin': the main alphabet of a text. */
    public static function script($text)
    {
        $counts = array(
            'hebrew' => preg_match_all('/\p{Hebrew}/u', (string) $text),
            'cyrillic' => preg_match_all('/\p{Cyrillic}/u', (string) $text),
            'greek' => preg_match_all('/\p{Greek}/u', (string) $text),
            'arabic' => preg_match_all('/\p{Arabic}/u', (string) $text),
            'latin' => preg_match_all('/\p{Latin}/u', (string) $text),
        );
        arsort($counts);
        return key($counts);
    }

    /**
     * The languages the name records use, most-used first, each with the
     * alphabet its forms are mostly in: array(language => script).
     */
    public static function languagesInUse()
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $db = get_db();
        $rows = $db->fetchAll("SELECT `language`, `text` FROM `{$db->NameAuthorityForm}`
            WHERE `kind` = 'form' AND `language` IS NOT NULL AND `language` <> ''");
        $counts = array();
        $scripts = array();
        foreach ($rows as $r) {
            $l = $r['language'];
            $counts[$l] = (isset($counts[$l]) ? $counts[$l] : 0) + 1;
            $sc = self::script($r['text']);
            $scripts[$l][$sc] = (isset($scripts[$l][$sc]) ? $scripts[$l][$sc] : 0) + 1;
        }
        arsort($counts);
        $cache = array();
        foreach ($counts as $l => $n) {
            arsort($scripts[$l]);
            $cache[$l] = key($scripts[$l]);
        }
        return $cache;
    }

    public static function langCode($language)
    {
        $l = strtolower(trim((string) $language));
        $codes = self::LANG_CODES;
        return isset($codes[$l]) ? $codes[$l] : '';
    }

    // ==== elements ===========================================================

    public function getElementIds()
    {
        $ids = json_decode((string) get_option('name_authority_element_ids'), true);
        return is_array($ids) ? array_map('intval', $ids) : array();
    }

    /** @return Element[] */
    public function getElements()
    {
        $out = array();
        $table = $this->_db->getTable('Element');
        foreach ($this->getElementIds() as $id) {
            $el = $table->find($id);
            if ($el) {
                $out[] = $el;
            }
        }
        return $out;
    }

    protected function _defaultElementIds()
    {
        return $this->_dublinCoreIds(array('Creator', 'Contributor', 'Subject'));
    }

    /** Ids of Dublin Core elements by name (these exist on every Omeka site). */
    protected function _dublinCoreIds(array $names)
    {
        $table = $this->_db->getTable('Element');
        $ids = array();
        foreach ($names as $name) {
            $el = $table->findByElementSetNameAndElementName('Dublin Core', $name);
            if ($el) {
                $ids[] = (int) $el->id;
            }
        }
        return $ids;
    }

    /** Name fields that mostly hold topics (listed separately). @param bool $onlyNameFields */
    public static function topicElementIds($onlyNameFields = true)
    {
        $ids = json_decode((string) get_option('name_authority_topic_element_ids'), true);
        $ids = is_array($ids) ? array_map('intval', $ids) : array();
        if ($onlyNameFields) {
            $plugin = new self;
            $ids = array_values(array_intersect($ids, $plugin->getElementIds()));
        }
        return $ids;
    }

    /** Field that holds the authoritative heading (0 = none). */
    public static function authoritativeElementId()
    {
        return (int) get_option('name_authority_authoritative_element_id');
    }

    /** Field with the item's language (0 = none). */
    public static function languageElementId()
    {
        return (int) get_option('name_authority_language_element_id');
    }

    /** Element names for ids, e.g. array(49 => 'Subject'). */
    public static function elementNames(array $ids)
    {
        $ids = array_filter(array_map('intval', $ids));
        if (!$ids) {
            return array();
        }
        $db = get_db();
        $names = $db->fetchPairs("SELECT id, name FROM `{$db->Element}` WHERE id IN (" . implode(',', $ids) . ")");
        $out = array();
        foreach ($ids as $id) {
            if (isset($names[$id])) {
                $out[$id] = __($names[$id]);
            }
        }
        return $out;
    }

    protected function _elementOptions()
    {
        $db = $this->_db;
        $rows = $db->fetchAll("SELECT e.id, e.name, s.name AS set_name FROM `{$db->Element}` e JOIN `{$db->ElementSet}` s ON s.id = e.element_set_id ORDER BY s.name, e.name");
        $out = array();
        foreach ($rows as $r) {
            $out[$r['set_name']][(int) $r['id']] = $r['name'];
        }
        return $out;
    }
}
