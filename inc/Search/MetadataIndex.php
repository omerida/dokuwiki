<?php
namespace dokuwiki\Search;


/**
 * Class DokuWiki Metadata Index (Singleton)
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Andreas Gohr <andi@splitbrain.org>
 * @author Tom N Harris <tnharris@whoopdedo.org>
 */
class MetadataIndex extends AbstractIndex
{
    /** @var MetadataIndex */
    protected static $instance = null;

    /**
     * MetadataIndex constructor. Singleton, thus protected!
     */
    protected function __construct() {}

    /**
     * Get new or existing singleton instance of the MetadataIndex
     *
     * @return MetadataIndex
     */
    public static function getInstance()
    {
        if (is_null(static::$instance)) {
            static::$instance = new static();
        }
        return static::$instance;
    }

    /**
     * Add/update keys to/of the metadata index
     *
     * Adding new keys does not remove other keys for the page.
     * An empty value will erase the key.
     * The $key parameter can be an array to add multiple keys. $value will
     * not be used if $key is an array.
     *
     * @param string    $page   a page name
     * @param mixed     $key    a key string or array of key=>value pairs
     * @param mixed     $value  the value or list of values
     * @return bool  if the function completed successfully
     *
     * @author Tom N Harris <tnharris@whoopdedo.org>
     * @author Michael Hamann <michael@content-space.de>
     */
    public function addMetaKeys($page, $key, $value = null)
    {
        if (!is_array($key)) {
            $key = array($key => $value);
        } elseif (!is_null($value)) {
            // $key is array, but $value is not null
            trigger_error("array passed to addMetaKeys but value is not null", E_USER_WARNING);
        }

        if (!$this->lock()) return false;  // set $errors property

        // load known documents
        $pid = $this->getPIDNoLock($page);
        if ($pid === false) {
            $this->unlock();
            return false;
        }

        // Special handling for titles so the index file is simpler
        if (array_key_exists('title', $key)) {
            $value = $key['title'];
            if (is_array($value)) {
                $value = $value[0];
            }
            $this->saveIndexKey('title', '', $pid, $value);
            unset($key['title']);
        }

        foreach ($key as $name => $values) {
            $metaname = $this->cleanName($name);
            $this->addIndexKey('metadata', '', $metaname);
            $metaidx = $this->getIndex($metaname.'_i', '');
            $metawords = $this->getIndex($metaname.'_w', '');
            $addwords = false;

            if (!is_array($values)) $values = array($values);

            $val_idx = $this->getIndexKey($metaname.'_p', '', $pid);
            if ($val_idx !== '') {
                $val_idx = explode(':', $val_idx);
                // -1 means remove, 0 keep, 1 add
                $val_idx = array_combine($val_idx, array_fill(0, count($val_idx), -1));
            } else {
                $val_idx = array();
            }

            foreach ($values as $val) {
                $val = (string)$val;
                if ($val !== '') {
                    $id = array_search($val, $metawords, true);
                    if ($id === false) {
                        // didn't find $val, so we'll add it to the end of metawords
                        // and create a placeholder in metaidx
                        $id = count($metawords);
                        $metawords[$id] = $val;
                        $metaidx[$id] = '';
                        $addwords = true;
                    }
                    // test if value is already in the index
                    if (isset($val_idx[$id]) && $val_idx[$id] <= 0) {
                        $val_idx[$id] = 0;
                    } else { // else add it
                        $val_idx[$id] = 1;
                    }
                }
            }

            if ($addwords) {
                $this->saveIndex($metaname.'_w', '', $metawords);
            }
            $vals_changed = false;
            foreach ($val_idx as $id => $action) {
                if ($action == -1) {
                    $metaidx[$id] = $this->updateTuple($metaidx[$id], $pid, 0);
                    $vals_changed = true;
                    unset($val_idx[$id]);
                } elseif ($action == 1) {
                    $metaidx[$id] = $this->updateTuple($metaidx[$id], $pid, 1);
                    $vals_changed = true;
                }
            }

            if ($vals_changed) {
                $this->saveIndex($metaname.'_i', '', $metaidx);
                $val_idx = implode(':', array_keys($val_idx));
                $this->saveIndexKey($metaname.'_p', '', $pid, $val_idx);
            }

            unset($metaidx);
            unset($metawords);
        }

        $this->unlock();
        return true;
    }

    /**
     * Delete keys of the page from metadata index
     *
     * @param string    $page   a page name
     * @param mixed     $keys   a key string or array of keys
     * @param bool      $requireLock
     * @return bool  If renaming the value has been successful, false on error
     *
     * @author Tom N Harris <tnharris@whoopdedo.org>
     * @author Satoshi Sahara <sahara.satoshi@gmail.com>
     */
    public function deleteMetaKeys($page, $keys = [], $requireLock = true)
    {
        if ($requireLock && !$this->lock()) return false;  // set $errors property

        // load known documents
        $pid = $this->getPIDNoLock($page);
        if ($pid === false) {
            return false;
        }

        $knownKeys = $this->getIndex('metadata', '');
        $knownKeys[] = 'title';

        // remove all metadata keys of the page when $keys is empty
        $keys = (empty($keys)) ? $knownKeys : (array)$keys;

        foreach ($keys as $metaname) {
            if ($metaname == 'title') {
                // Special handling for titles so the index file is simpler
                $this->saveIndexKey('title', '', $pid, '');
            } elseif (in_array($metaname, $knownKeys)) {
                $meta_idx = $this->getIndex($metaname.'_i', '');
                $val_idx = explode(':', $this->getIndexKey($metaname.'_p', '', $pid));
                foreach ($val_idx as $id) {
                    if ($id === '') continue;
                    $meta_idx[$id] = $this->updateTuple($meta_idx[$id], $pid, 0);
                }
                $this->saveIndex($metaname.'_i', '', $meta_idx);
                $this->saveIndexKey($metaname.'_p', '', $pid, '');
            }
        }

        if ($requireLock) $this->unlock();
        return true;
    }

    /**
     * Remove keys of the page from metadata index without locking the index
     * only use this function if the index is already locked
     *
     * @param string    $page   a page name
     * @param mixed     $keys   a key string or array of keys
     * @return bool  If renaming the value has been successful, false on error
     *
     * @author Tom N Harris <tnharris@whoopdedo.org>
     * @author Satoshi Sahara <sahara.satoshi@gmail.com>
     */
    public function deleteMetaKeysNoLock($page, $keys = [])
    {
        return $this->deleteMetaKeys($page, $keys, false);
    }

    /**
     * Find pages containing a metadata key
     *
     * The metadata values are compared as case-sensitive strings. Pass a
     * callback function that returns true or false to use a different
     * comparison function. The function will be called with the $value being
     * searched for as the first argument, and the word in the index as the
     * second argument. The function preg_match can be used directly if the
     * values are regexes.
     *
     * @param string    $key    name of the metadata key to look for
     * @param string    $value  search term to look for, must be a string or array of strings
     * @param callback  $func   comparison function
     * @return array            lists with page names, keys are query values if $value is array
     *
     * @author Tom N Harris <tnharris@whoopdedo.org>
     * @author Michael Hamann <michael@content-space.de>
     */
    public function lookupKey($key, &$value, $func = null)
    {
        if (!is_array($value)) {
            $value_array = array($value);
        } else {
            $value_array =& $value;
        }

        // the matching ids for the provided value(s)
        $value_ids = array();

        $metaname = $this->cleanName($key);

        // get all words in order to search the matching ids
        if ($key == 'title') {
            $words = $this->getIndex('title', '');
        } else {
            $words = $this->getIndex($metaname.'_w', '');
        }

        if (!is_null($func)) {
            foreach ($value_array as $val) {
                foreach ($words as $i => $word) {
                    if (call_user_func_array($func, array($val, $word))) {
                        $value_ids[$i][] = $val;
                    }
                }
            }
        } else {
            foreach ($value_array as $val) {
                $xval = $val;
                $caret = '^';
                $dollar = '$';
                // check for wildcards
                if (substr($xval, 0, 1) == '*') {
                    $xval = substr($xval, 1);
                    $caret = '';
                }
                if (substr($xval, -1, 1) == '*') {
                    $xval = substr($xval, 0, -1);
                    $dollar = '';
                }
                if (!$caret || !$dollar) {
                    $re = $caret.preg_quote($xval, '/').$dollar;
                    foreach (array_keys(preg_grep('/'.$re.'/', $words)) as $i) {
                        $value_ids[$i][] = $val;
                    }
                } else {
                    if (($i = array_search($val, $words, true)) !== false) {
                        $value_ids[$i][] = $val;
                    }
                }
            }
        }

        unset($words); // free the used memory

        // initialize the result so it won't be null
        $result = array();
        foreach ($value_array as $val) {
            $result[$val] = array();
        }

        $page_idx = $this->getIndex('page', '');

        // Special handling for titles
        if ($key == 'title') {
            foreach ($value_ids as $pid => $val_list) {
                $page = $page_idx[$pid];
                foreach ($val_list as $val) {
                    $result[$val][] = $page;
                }
            }
        } else {
            // load all lines and pages so the used lines can be taken
            // and matched with the pages
            $lines = $this->getIndex($metaname.'_i', '');

            foreach ($value_ids as $value_id => $val_list) {
                // parse the tuples of the form page_id*1:page2_id*1 and so on,
                // return value is an array with page_id => 1, page2_id => 1 etc.
                // so take the keys only
                $pages = array_keys($this->parseTuples($page_idx, $lines[$value_id]));
                foreach ($val_list as $val) {
                    $result[$val] = array_merge($result[$val], $pages);
                }
            }
        }
        if (!is_array($value)) $result = $result[$value];
        return $result;
    }

    /**
     * Renames a meta value in the index
     * This doesn't change the meta value in the pages, it assumes that
     * all pages will be updated.
     *
     * @param string $key       The metadata key of which a value shall be changed
     * @param string $oldvalue  The old value that shall be renamed
     * @param string $newvalue  The new value to which the old value shall be renamed,
     *                          if exists values will be merged
     * @return bool  If renaming the value has been successful, false on error
     */
    public function renameMetaValue($key, $oldvalue, $newvalue)
    {
        if (!$this->lock()) return false;  // set $errors property

        // change the relation references index
        $metavalues = $this->getIndex($key, '_w');
        $oldid = array_search($oldvalue, $metavalues, true);
        if ($oldid !== false) {
            $newid = array_search($newvalue, $metavalues, true);
            if ($newid !== false) {
                // free memory
                unset($metavalues);

                // okay, now we have two entries for the same value. we need to merge them.
                $indexline = $this->getIndexKey($key.'_i', '', $oldid);
                if ($indexline != '') {
                    $newindexline = $this->getIndexKey($key.'_i', '', $newid);
                    $pagekeys     = $this->getIndex($key.'_p', '');
                    $parts = explode(':', $indexline);
                    foreach ($parts as $part) {
                        list($id, $count) = explode('*', $part);
                        $newindexline = $this->updateTuple($newindexline, $id, $count);

                        $keyline = explode(':', $pagekeys[$id]);
                        // remove old meta value
                        $keyline = array_diff($keyline, array($oldid));
                        // add new meta value when not already present
                        if (!in_array($newid, $keyline)) {
                            array_push($keyline, $newid);
                        }
                        $pagekeys[$id] = implode(':', $keyline);
                    }
                    $this->saveIndex($key.'_p', '', $pagekeys);
                    unset($pagekeys);
                    $this->saveIndexKey($key.'_i', '', $oldid, '');
                    $this->saveIndexKey($key.'_i', '', $newid, $newindexline);
                }
            } else {
                $metavalues[$oldid] = $newvalue;
                if (!$this->saveIndex($key.'_w', '', $metavalues)) {
                    $this->unlock();
                    return false;
                }
            }
        }

        $this->unlock();
        return true;
    }

    /**
     * Clear the Metadata Index
     *
     * @param bool   $requireLock
     * @return bool  If the index has been cleared successfully
     */
    public function clear($requireLock = true)
    {
        global $conf;

        if ($requireLock && !$this->lock()) return false;

        $dir = @opendir($conf['indexdir']);
        if ($dir !== false) {
            while (($f = readdir($dir)) !== false) {
                if (in_array(substr($f, -6), ['_w.idx','_i.idx','_p.idx'])) {
                    // metadata index
                    @unlink($conf['indexdir']."/$f");
                }
            }
        }
        @unlink($conf['indexdir'].'/title.idx');
        @unlink($conf['indexdir'].'/metadata.idx');

        if ($requireLock) $this->unlock();
        return true;
    }
}