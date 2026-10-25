<?php
/**
 * One spelling of a name: the authoritative name, a language form, or a search variant.
 *
 * @copyright Raphael Halff
 * @license GPLv3 http://www.gnu.org/licenses/gpl-3.0.txt
 */
class NameAuthorityForm extends Omeka_Record_AbstractRecord
{
    public $authority_id;
    public $text = '';
    public $match_key = '';
    public $language;
    public $kind = 'form';   // authoritative | form | variant
}
