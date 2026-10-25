-- Name Authority: export the names used on your items, to start an import sheet.
--
-- Lists every distinct value of the chosen fields with the number of items using
-- it and those items' languages. Read-only.
--
--   mysql -u USER -p DATABASE --default-character-set=utf8mb4 -B < export-names.sql > names.tsv
--
-- If your tables don't start with "omeka_", change every "omeka_" below.
-- To export other fields, edit the list in the last WHERE line, e.g.
-- ('Creator', 'Contributor', 'Subject').

SET NAMES utf8mb4;
SET SESSION group_concat_max_len = 10000;

SELECT
    TRIM(et.text)                                   AS name,
    e.name                                          AS field,
    COUNT(DISTINCT et.record_id)                    AS items,
    GROUP_CONCAT(DISTINCT TRIM(lang.text) ORDER BY lang.text SEPARATOR '; ') AS item_languages
FROM omeka_element_texts et
JOIN omeka_elements e      ON e.id = et.element_id
JOIN omeka_element_sets s  ON s.id = e.element_set_id AND s.name = 'Dublin Core'
LEFT JOIN omeka_element_texts lang
       ON lang.record_type = 'Item'
      AND lang.record_id = et.record_id
      AND lang.element_id = (SELECT e2.id FROM omeka_elements e2
                             JOIN omeka_element_sets s2 ON s2.id = e2.element_set_id
                             WHERE e2.name = 'Language' AND s2.name = 'Dublin Core')
WHERE et.record_type = 'Item'
  AND TRIM(et.text) <> ''
  AND e.name IN ('Creator', 'Contributor')
GROUP BY TRIM(et.text), e.name
ORDER BY items DESC, name;
