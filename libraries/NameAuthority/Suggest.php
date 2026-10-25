<?php
/**
 * Suggestions for the item form: name records matching what's typed, offering
 * the form in the item's language (or the authoritative name, in the field
 * chosen for authoritative headings in the settings, if any).
 *
 * @copyright Raphael Halff
 * @license GPLv3 http://www.gnu.org/licenses/gpl-3.0.txt
 */
class NameAuthority_Suggest
{
    const MAX_RECORDS = 10;
    const MAX_SUGGESTIONS = 15;

    /**
     * @param string $term what has been typed
     * @param array $languages the item's Language values ("German", "ger", ...)
     * @param int $elementId the field being filled
     * @return array list of array(value, label, language, record, record_id, items, matched)
     */
    public static function suggest($term, array $languages, $elementId)
    {
        $term = NameAuthorityPlugin::cleanText($term);
        if (mb_strlen($term, 'UTF-8') < 2) {
            return array();
        }
        $ids = NameAuthorityPlugin::findAuthorityIdsContaining($term, true);
        if (!$ids) {
            return array();
        }
        $wantAuthoritative = self::_isAuthoritativeElement($elementId);
        $langKeys = array();
        $split = array();
        foreach ($languages as $l) {
            foreach (preg_split('/[,;\/\n]+/', strip_tags((string) $l)) as $part) {
                if (trim($part) !== '') {
                    $split[] = trim($part);
                }
            }
        }
        $languages = $split;
        foreach ($languages as $l) {
            $k = self::_langKey($l);
            if ($k !== '' && !in_array($k, $langKeys)) {
                $langKeys[] = $k;
            }
        }

        // Rank: a name starting with what was typed first, then most-used, then by name.
        $termKey = NameAuthorityPlugin::looseKey($term);
        $ranked = array();
        $table = get_db()->getTable('NameAuthority');
        foreach ($ids as $id) {
            $forms = NameAuthorityPlugin::formsFor($id);
            $prefix = 1;
            $matched = '';
            foreach ($forms as $f) {
                $k = preg_replace('/\p{Mn}+/u', '', $f['match_key']);
                if (self::_startsWithWords($k, $termKey)) {
                    $prefix = 0;
                    $matched = $f['text'];
                    break;
                }
                if ($matched === '' && self::_containsWords($k, $termKey)) {
                    $matched = $f['text'];
                }
            }
            $ranked[] = array('id' => (int) $id, 'prefix' => $prefix, 'matched' => $matched,
                'items' => count(NameAuthorityPlugin::instanceItemIds($id)));
        }
        usort($ranked, function ($a, $b) {
            return $a['prefix'] - $b['prefix'] ?: $b['items'] - $a['items'] ?: $a['id'] - $b['id'];
        });

        $out = array();
        foreach (array_slice($ranked, 0, self::MAX_RECORDS) as $r) {
            $record = $table->find($r['id']);
            if (!$record) {
                continue;
            }
            $display = $record->getDisplayName();
            $forms = $record->getLanguageForms();
            $choices = array();   // list of array(text, language label)
            if ($wantAuthoritative) {
                if ($record->authoritative !== '') {
                    $choices[] = array($record->authoritative, __('Authoritative'));
                } elseif ($forms) {
                    $choices[] = array($forms[0]['text'], $forms[0]['language'], __('record has no authoritative name'));
                }
            } elseif ($langKeys) {
                foreach ($langKeys as $k) {
                    foreach ($forms as $f) {
                        if (self::_langKey($f['language']) === $k) {
                            $choices[] = array($f['text'], $f['language']);
                            break;
                        }
                    }
                }
                if (!$choices) {
                    // No form in the item's language: offer what there is.
                    $note = __('no %s form', implode(' / ', array_map('trim', $languages)));
                    foreach ($forms as $f) {
                        $choices[] = array($f['text'], $f['language'], $note);
                    }
                }
            } else {
                // No item language: every form, those in the alphabet being typed first.
                $typed = self::_script($term);
                usort($forms, function ($a, $b) use ($typed) {
                    return (self::_script($a['text']) === $typed ? 0 : 1) - (self::_script($b['text']) === $typed ? 0 : 1);
                });
                foreach ($forms as $f) {
                    $choices[] = array($f['text'], $f['language']);
                }
            }
            if (!$choices) {
                $choices[] = array($display, __('Authoritative'));
            }
            $seen = array();
            foreach ($choices as $c) {
                if (isset($seen[$c[0]])) {
                    continue;
                }
                $seen[$c[0]] = true;
                $out[] = array(
                    'value' => $c[0],
                    'label' => $c[0],
                    'language' => (string) $c[1],
                    'note' => isset($c[2]) ? (string) $c[2] : '',
                    'record' => $display,
                    'record_id' => $r['id'],
                    'items' => $r['items'],
                    'matched' => ($r['matched'] !== '' && NameAuthorityPlugin::looseKey($r['matched']) !== NameAuthorityPlugin::looseKey($c[0])
                        && NameAuthorityPlugin::looseKey($r['matched']) !== NameAuthorityPlugin::looseKey($display)) ? $r['matched'] : '',
                );
                if (count($out) >= self::MAX_SUGGESTIONS) {
                    break 2;
                }
            }
        }
        return $out;
    }

    /** "German", "german", "ger", "deu", "de" -> "de"; unknown names compare by lower case. */
    protected static function _langKey($language)
    {
        $l = strtolower(trim((string) $language));
        if ($l === '') {
            return '';
        }
        $code = NameAuthorityPlugin::langCode($l);
        if ($code !== '') {
            return $code;
        }
        $iso3 = array('yid' => 'yi', 'heb' => 'he', 'eng' => 'en', 'rus' => 'ru', 'ger' => 'de', 'deu' => 'de',
            'pol' => 'pl', 'fre' => 'fr', 'fra' => 'fr', 'spa' => 'es', 'ukr' => 'uk', 'lad' => 'lad');
        if (isset($iso3[$l])) {
            return $iso3[$l];
        }
        return $l;
    }

    protected static function _isAuthoritativeElement($elementId)
    {
        $id = NameAuthorityPlugin::authoritativeElementId();
        return $id && (int) $elementId === $id;
    }

    /** 'hebrew', 'cyrillic', 'greek', 'arabic' or 'latin': the main alphabet of a text. */
    protected static function _script($text)
    {
        return NameAuthorityPlugin::script($text);
    }

    /** Every word of $term starts some word of $key, and the first word starts the name. */
    protected static function _startsWithWords($key, $term)
    {
        $words = array_filter(explode(' ', $term), 'strlen');
        if (!$words || mb_strpos($key, reset($words), 0, 'UTF-8') !== 0) {
            return false;
        }
        return self::_containsWords($key, $term);
    }

    protected static function _containsWords($key, $term)
    {
        foreach (array_filter(explode(' ', $term), 'strlen') as $w) {
            if (mb_strpos($key, $w, 0, 'UTF-8') === false) {
                return false;
            }
        }
        return true;
    }
}
