<?php
/**
 * Finds name records that may be the same person, and merges them.
 *
 * Reasons, strongest first:
 *   form     - the same name form (any spelling, form or variant) is in both records
 *   viaf/lc  - the same VIAF or LC ID
 *   nodates  - the same name once dates are ignored ("Smith, Jane" / "Smith, Jane, 1950-")
 *   initials - same surname, and the first given names match or one is an initial of the other
 *              ("Smith, J." / "Smith, Jane"). Weakest; often worth a look,
 *              sometimes two different people (Singer, I. B. / Singer, I. J.).
 *
 * Pairs marked "not the same" are kept in option name_authority_not_duplicates.
 *
 * @copyright Raphael Halff
 * @license GPLv3 http://www.gnu.org/licenses/gpl-3.0.txt
 */
class NameAuthority_Duplicates
{
    protected static $_pairs = null;

    public static function reasonLabel($reason)
    {
        $labels = array(
            'form' => __('same name form'),
            'viaf' => __('same VIAF ID'),
            'lc' => __('same LC ID'),
            'nodates' => __('same name apart from dates'),
            'initials' => __('same surname, matching first name or initial'),
        );
        return isset($labels[$reason]) ? $labels[$reason] : $reason;
    }

    protected static $_strength = array('form' => 4, 'viaf' => 4, 'lc' => 4, 'nodates' => 3, 'initials' => 1);

    /**
     * All suggested pairs, strongest first.
     *
     * @return array list of array('a' => id, 'b' => id, 'reasons' => array(reason => example text))
     */
    public static function pairs()
    {
        if (self::$_pairs !== null) {
            return self::$_pairs;
        }
        $db = get_db();
        $ignored = self::ignored();
        $pairs = array();
        $add = function ($a, $b, $reason, $example) use (&$pairs, $ignored) {
            $a = (int) $a;
            $b = (int) $b;
            if ($a === $b) {
                return;
            }
            if ($a > $b) {
                list($a, $b) = array($b, $a);
            }
            $key = $a . '-' . $b;
            if (isset($ignored[$key])) {
                return;
            }
            if (!isset($pairs[$key])) {
                $pairs[$key] = array('a' => $a, 'b' => $b, 'reasons' => array());
            }
            if (!isset($pairs[$key]['reasons'][$reason])) {
                $pairs[$key]['reasons'][$reason] = $example;
            }
        };

        // Bucket every form by three keys.
        $byKey = $byNoDates = $bySurname = array();
        foreach ($db->getTable('NameAuthorityForm')->fetchAllForms() as $row) {
            $aid = (int) $row['authority_id'];
            $key = (string) $row['match_key'];
            if ($key === '') {
                continue;
            }
            $byKey[$key][$aid] = $row['text'];
            if ($row['kind'] === 'variant') {
                continue;   // variants are often bare surnames or initials: too loose for the fuzzy rules
            }
            $nd = self::noDatesKey($key);
            if (mb_strlen(str_replace(' ', '', $nd), 'UTF-8') >= 5) {
                $byNoDates[$nd][$aid] = $row['text'];
            }
            $split = self::splitName($nd);
            if ($split) {
                $bySurname[$split[0]][] = array($aid, $split[1], $row['text']);
            }
        }
        foreach ($byKey as $ids) {
            self::_pairUp($ids, 'form', $add);
        }
        foreach ($byNoDates as $ids) {
            self::_pairUp($ids, 'nodates', $add);
        }
        foreach ($bySurname as $list) {
            $n = count($list);
            if ($n < 2 || $n > 200) {
                continue;
            }
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    if ($list[$i][0] !== $list[$j][0] && self::givenCompatible($list[$i][1], $list[$j][1])) {
                        $add($list[$i][0], $list[$j][0], 'initials', $list[$i][2] . ' / ' . $list[$j][2]);
                    }
                }
            }
        }

        // Same identifiers.
        foreach (array('viaf' => 'viaf_id', 'lc' => 'lc_id') as $reason => $col) {
            $rows = $db->fetchAll("SELECT `$col` AS v, GROUP_CONCAT(id) AS ids FROM `{$db->NameAuthority}`
                WHERE `$col` IS NOT NULL AND `$col` <> '' GROUP BY `$col` HAVING COUNT(*) > 1");
            foreach ($rows as $r) {
                $ids = array_flip(explode(',', $r['ids']));
                foreach ($ids as $id => $x) {
                    $ids[$id] = $r['v'];
                }
                self::_pairUp($ids, $reason, $add);
            }
        }

        // Strongest first, then by id.
        $strength = self::$_strength;
        foreach ($pairs as &$p) {
            $p['strength'] = max(array_map(function ($r) use ($strength) {
                return $strength[$r];
            }, array_keys($p['reasons'])));
        }
        unset($p);
        uasort($pairs, function ($x, $y) {
            return $y['strength'] - $x['strength'] ?: $x['a'] - $y['a'] ?: $x['b'] - $y['b'];
        });
        return self::$_pairs = array_values($pairs);
    }

    /** Ids of records that appear in any suggestion => number of suggestions. */
    public static function flaggedIds()
    {
        $ids = array();
        foreach (self::pairs() as $p) {
            $ids[$p['a']] = isset($ids[$p['a']]) ? $ids[$p['a']] + 1 : 1;
            $ids[$p['b']] = isset($ids[$p['b']]) ? $ids[$p['b']] + 1 : 1;
        }
        return $ids;
    }

    public static function pairsFor($id)
    {
        $id = (int) $id;
        return array_values(array_filter(self::pairs(), function ($p) use ($id) {
            return $p['a'] === $id || $p['b'] === $id;
        }));
    }

    protected static function _pairUp(array $idToText, $reason, $add)
    {
        $ids = array_keys($idToText);
        $n = count($ids);
        if ($n < 2 || $n > 50) {
            return;   // very common keys ("anonymous") are noise
        }
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $add($ids[$i], $ids[$j], $reason, $idToText[$ids[$i]] . ($reason === 'form' || $reason === 'viaf' || $reason === 'lc' ? '' : ' / ' . $idToText[$ids[$j]]));
            }
        }
    }

    /** Match key without dates and punctuation: "smith, jane a., 1950" -> "smith, jane a" */
    public static function noDatesKey($key)
    {
        $s = preg_replace('/\b(?:b|d|fl|ca)\.?\s*(?=\d)/u', ' ', $key);   // "b. 1948", "fl. 1900"
        $s = preg_replace('/\d+\??\s+or\s+(?=\d)/u', ' ', $s);              // "1851 or 1852"
        $s = preg_replace('/[\d?]+/u', ' ', $s);
        $s = preg_replace('/[.()"\'\x{05F3}\x{05F4}]/u', ' ', $s);
        $s = preg_replace('/\s*,[\s,]*/u', ', ', $s);
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim($s, " ,");
    }

    /** "smith, jane a" -> array("smith", array("jane", "a")); "jane smith" -> array("smith", array("jane")) */
    public static function splitName($nd)
    {
        if (strpos($nd, ',') !== false) {
            list($surname, $given) = explode(',', $nd, 2);
        } else {
            $parts = explode(' ', $nd);
            if (count($parts) < 2 || count($parts) > 3) {
                return null;
            }
            $surname = array_pop($parts);
            $given = implode(' ', $parts);
        }
        $surname = trim($surname);
        $given = array_values(array_filter(preg_split('/[\s,]+/u', $given), 'strlen'));
        if (mb_strlen($surname, 'UTF-8') < 3 || !$given) {
            return null;
        }
        return array($surname, $given);
    }

    /** First given names equal, or one is the other's initial. */
    public static function givenCompatible(array $a, array $b)
    {
        $x = $a[0];
        $y = $b[0];
        if ($x === $y) {
            return true;
        }
        $lx = mb_strlen($x, 'UTF-8');
        $ly = mb_strlen($y, 'UTF-8');
        if ($lx === 1 && $ly > 1) {
            return mb_substr($y, 0, 1, 'UTF-8') === $x;
        }
        if ($ly === 1 && $lx > 1) {
            return mb_substr($x, 0, 1, 'UTF-8') === $y;
        }
        return false;
    }

    // ---- not-the-same list ---------------------------------------------------

    public static function ignored()
    {
        $list = json_decode((string) get_option('name_authority_not_duplicates'), true);
        return is_array($list) ? array_flip($list) : array();
    }

    public static function ignore($a, $b)
    {
        $a = (int) $a;
        $b = (int) $b;
        if ($a > $b) {
            list($a, $b) = array($b, $a);
        }
        $list = array_keys(self::ignored());
        $list[] = $a . '-' . $b;
        set_option('name_authority_not_duplicates', json_encode(array_values(array_unique($list))));
        self::$_pairs = null;
    }

    public static function clearCache()
    {
        self::$_pairs = null;
    }

    // ---- merge -----------------------------------------------------------------

    /**
     * Move everything from $source into $target and delete $source.
     * The target keeps its authoritative name and its language forms; the
     * source's names it doesn't already have become forms (for languages the
     * target lacks) or search variants. Item metadata is not changed: every
     * name the items use still belongs to the (merged) record.
     *
     * @return string summary for the flash message
     */
    public static function merge(NameAuthority $target, NameAuthority $source)
    {
        $forms = $target->getLanguageForms();
        $variants = $target->getVariants();
        $haveLang = array();
        $haveKey = array(NameAuthorityPlugin::matchKey($target->authoritative) => true);
        foreach ($forms as $f) {
            $haveLang[strtolower((string) $f['language'])] = true;
            $haveKey[NameAuthorityPlugin::matchKey($f['text'])] = true;
        }
        foreach ($variants as $v) {
            $haveKey[NameAuthorityPlugin::matchKey($v)] = true;
        }
        $newVariant = function ($text) use (&$variants, &$haveKey) {
            $k = NameAuthorityPlugin::matchKey($text);
            if ($k !== '' && !isset($haveKey[$k])) {
                $variants[] = $text;
                $haveKey[$k] = true;
            }
        };

        if (trim((string) $target->authoritative) === '' && trim((string) $source->authoritative) !== '') {
            $target->authoritative = $source->authoritative;
            $haveKey[NameAuthorityPlugin::matchKey($source->authoritative)] = true;
        } else {
            $newVariant($source->authoritative);
        }
        foreach ($source->getLanguageForms() as $f) {
            $lang = strtolower((string) $f['language']);
            $k = NameAuthorityPlugin::matchKey($f['text']);
            if (isset($haveKey[$k])) {
                continue;
            }
            if ($lang !== '' && !isset($haveLang[$lang])) {
                $forms[] = $f;
                $haveLang[$lang] = true;
                $haveKey[$k] = true;
            } else {
                $newVariant($f['text']);
            }
        }
        foreach ($source->getVariants() as $v) {
            $newVariant($v);
        }

        $notes = array();
        foreach (array('viaf_id' => 'VIAF', 'lc_id' => 'LC') as $field => $label) {
            $s = trim((string) $source->$field);
            if ($s === '') {
                continue;
            }
            if (trim((string) $target->$field) === '') {
                $target->$field = $s;
            } elseif ($target->$field !== $s) {
                $notes[] = __('%1$s ID of the merged record was %2$s.', $label, $s);
            }
        }
        $line = __('Merged with "%1$s" (#%2$d) on %3$s.', $source->getDisplayName(), $source->id, date('Y-m-d'));
        $target->notes = trim(implode("\n", array_filter(array(
            trim((string) $target->notes),
            $line . ($notes ? ' ' . implode(' ', $notes) : ''),
            trim((string) $source->notes) !== '' ? __('Its notes: %s', trim((string) $source->notes)) : '',
        ))));

        $db = get_db();
        $db->beginTransaction();
        try {
            $target->save();
            $target->saveForms($forms, $variants);
            $source->delete();
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
        self::clearCache();
        NameAuthorityPlugin::clearCache();
        return $line;
    }
}
