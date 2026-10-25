<?php
/**
 * Name look-up in VIAF (viaf.org AutoSuggest). Each result carries the VIAF
 * cluster ID and, when the Library of Congress contributes to the cluster,
 * the LC name/subject ID.
 *
 * id.loc.gov is not queried directly: it puts automated requests behind a
 * bot check, so the admin page links to its search page instead.
 *
 * @copyright Raphael Halff
 * @license GPLv3 http://www.gnu.org/licenses/gpl-3.0.txt
 */
class NameAuthority_Viaf
{
    const DEFAULT_URL = 'https://viaf.org/viaf/AutoSuggest';
    const MAX_RESULTS = 12;

    /** Base URL; option name_authority_viaf_url can override it (for testing). */
    public static function baseUrl()
    {
        $url = trim((string) get_option('name_authority_viaf_url'));
        return $url !== '' ? $url : self::DEFAULT_URL;
    }

    /**
     * Search VIAF for a name.
     *
     * @return array('results' => list of array(name, type, viaf, lc, also => [other headings]),
     *               'error' => string|null)
     */
    public static function search($query)
    {
        $query = NameAuthorityPlugin::cleanText($query);
        if ($query === '') {
            return array('results' => array(), 'error' => null);
        }
        // AutoSuggest matches the start of a heading. A full heading with dates
        // often finds only works ("Name, 1835-1917. | Title"), so also try the
        // name without dates, and the surname alone if nothing else matched.
        $tries = array($query);
        $noDates = self::stripDates($query);
        if ($noDates !== '' && $noDates !== $query) {
            $tries[] = $noDates;
        }
        $byViaf = array();
        $error = null;
        foreach ($tries as $i => $q) {
            list($rows, $err) = self::_fetch($q);
            if ($err !== null) {
                $error = $err;
                break;
            }
            self::_collect($rows, $byViaf);
            if (count($byViaf) >= self::MAX_RESULTS) {
                break;
            }
        }
        if (!$byViaf && $error === null && strpos($noDates, ',') !== false) {
            $surname = trim(substr($noDates, 0, strpos($noDates, ',')));
            if (mb_strlen($surname, 'UTF-8') >= 3) {
                list($rows, $error) = self::_fetch($surname);
                if ($error === null) {
                    self::_collect($rows, $byViaf);
                }
            }
        }
        return array('results' => array_slice(array_values($byViaf), 0, self::MAX_RESULTS), 'error' => $error);
    }

    /** "Smith, Jane A., 1950-" -> "Smith, Jane A." */
    public static function stripDates($name)
    {
        $s = preg_replace('/[,\s]*\(?\b(?:b|d|fl|ca)\.\s*(?=\d)/u', ' ', (string) $name);
        $s = preg_replace('/[,\s]*\(?\d{3,4}\??(?:\s*(?:-|–|or)\s*\d{0,4}\??)*\)?\.?/u', '', $s);
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim($s, " ,");
    }

    /** One AutoSuggest request. @return array(rows, error) */
    protected static function _fetch($q)
    {
        $url = self::baseUrl() . (strpos(self::baseUrl(), '?') === false ? '?' : '&') . 'query=' . rawurlencode($q);
        list($data, $error) = self::_getJson($url);
        if ($error !== null) {
            return array(array(), $error);
        }
        if (!array_key_exists('result', $data)) {
            return array(array(), __('VIAF sent an answer this plugin does not understand. The VIAF service may have changed.'));
        }
        return array(is_array($data['result']) ? $data['result'] : array(), null);
    }

    /** GET a VIAF URL as JSON. @return array(decoded array|null, error|null) */
    protected static function _getJson($url)
    {
        try {
            $config = array(
                'timeout' => 15,
                'maxredirects' => 3,
                'useragent' => 'Omeka NameAuthority plugin (' . WEB_ROOT . ')',
            );
            if (extension_loaded('curl')) {
                $config['adapter'] = 'Zend_Http_Client_Adapter_Curl';
                $config['curloptions'] = array(CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
                    CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 20);
            }
            $client = new Zend_Http_Client($url, $config);
            $client->setHeaders('Accept', 'application/json');
            $response = $client->request('GET');
        } catch (Exception $e) {
            return array(null, __('Could not reach VIAF: %s', $e->getMessage()));
        }
        $status = $response->getStatus();
        if ($status === 404) {
            return array(null, __('VIAF has no record with this ID.'));
        }
        if ($status === 429) {
            return array(null, __('VIAF\'s daily limit for this server has been reached. Try again tomorrow.'));
        }
        if ($status !== 200) {
            return array(null, __('VIAF answered with HTTP %s.', $status));
        }
        $data = json_decode($response->getBody(), true);
        if (!is_array($data)) {
            return array(null, __('VIAF sent an answer this plugin does not understand. The VIAF service may have changed.'));
        }
        return array($data, null);
    }

    /** Base URL of VIAF records, derived from the AutoSuggest URL: https://viaf.org/viaf/ */
    public static function recordBaseUrl()
    {
        return preg_replace('#AutoSuggest/?(\?.*)?$#i', '', self::baseUrl());
    }

    /**
     * LC's own heading for a VIAF record, exactly as LC has it
     * ("Twain, Mark, 1835-1910"), plus LC's ID.
     *
     * @return array('heading' => string, 'lc' => string, 'error' => string|null)
     */
    public static function lcHeading($viafId)
    {
        $out = array('heading' => '', 'lc' => '', 'error' => null);
        if (!preg_match('/^\d+$/', (string) $viafId)) {
            $out['error'] = __('Not a VIAF ID.');
            return $out;
        }
        list($data, $error) = self::_getJson(self::recordBaseUrl() . $viafId);
        if ($error !== null) {
            $out['error'] = $error;
            return $out;
        }
        $cluster = isset($data['ns1:VIAFCluster']) ? $data['ns1:VIAFCluster'] : (isset($data['VIAFCluster']) ? $data['VIAFCluster'] : null);
        if (!is_array($cluster)) {
            $out['error'] = __('VIAF sent an answer this plugin does not understand. The VIAF service may have changed.');
            return $out;
        }
        $headings = self::_get($cluster, 'mainHeadings');
        foreach (self::_list(self::_get($headings, 'mainHeadingEl')) as $el) {
            $sources = self::_get($el, 'sources');
            if (!in_array('LC', self::_list(self::_get($sources, 's')), true)) {
                continue;
            }
            foreach (self::_list(self::_get($sources, 'sid')) as $sid) {
                if (strpos((string) $sid, 'LC|') === 0) {
                    $out['lc'] = str_replace(' ', '', substr($sid, 3));
                }
            }
            $field = self::_get($el, 'datafield');
            $parts = array();
            foreach (self::_list(self::_get($field, 'subfield')) as $sf) {
                // Letters only: a, b, c, d, q, t ...; digits are control subfields.
                if (is_array($sf) && isset($sf['code'], $sf['content']) && preg_match('/^[a-z]$/', (string) $sf['code'])) {
                    $parts[] = trim((string) $sf['content']);
                }
            }
            $out['heading'] = NameAuthorityPlugin::cleanText(implode(' ', $parts));
            break;
        }
        return $out;
    }

    /** $a['ns1:key'] or $a['key']. */
    protected static function _get($a, $key)
    {
        if (!is_array($a)) {
            return null;
        }
        return isset($a['ns1:' . $key]) ? $a['ns1:' . $key] : (isset($a[$key]) ? $a[$key] : null);
    }

    /** VIAF's JSON gives a lone value instead of a one-element list. */
    protected static function _list($v)
    {
        if ($v === null || $v === array()) {
            return array();
        }
        return (is_array($v) && array_keys($v) === range(0, count($v) - 1)) ? $v : array($v);
    }

    /** Keep people, families, organisations and places; skip titles of works. */
    protected static function _collect(array $rows, array &$byViaf)
    {
        foreach ($rows as $r) {
            if (!is_array($r) || empty($r['viafid'])) {
                continue;
            }
            $type = isset($r['nametype']) ? (string) $r['nametype'] : '';
            if (stripos($type, 'uniformtitle') === 0) {
                continue;
            }
            $name = isset($r['displayForm']) ? $r['displayForm'] : (isset($r['term']) ? $r['term'] : '');
            $name = NameAuthorityPlugin::cleanText($name);
            $id = (string) $r['viafid'];
            if (!isset($byViaf[$id])) {
                $byViaf[$id] = array(
                    'name' => $name,
                    'type' => $type,
                    'viaf' => $id,
                    'lc' => isset($r['lc']) ? (string) $r['lc'] : '',
                    'also' => array(),
                );
            } elseif ($name !== '' && $name !== $byViaf[$id]['name'] && !in_array($name, $byViaf[$id]['also'])) {
                $byViaf[$id]['also'][] = $name;
                if ($byViaf[$id]['lc'] === '' && !empty($r['lc'])) {
                    $byViaf[$id]['lc'] = (string) $r['lc'];
                }
            }
        }
    }

    /** Search page on id.loc.gov (opened in the editor's own browser). */
    public static function lcSearchUrl($query)
    {
        return 'https://id.loc.gov/search/?q=' . rawurlencode(self::stripDates($query))
            . '&q=cs%3Ahttp%3A%2F%2Fid.loc.gov%2Fauthorities%2Fnames';
    }

    /** viaf.org's own search page does not take the search terms from the URL. */
    public static function viafSearchUrl($query)
    {
        return 'https://viaf.org/';
    }
}
