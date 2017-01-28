<?php
/**
 * GitAccess MediaWiki Extension---Access wiki content with Git.
 * Copyright (C) 2017  Matthew Trescott
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published
 * by the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 * 
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

class GitTree extends AbstractGitObject
{
    public $tree_data;
    
    const T_NORMAL_FILE = 100644;
    const T_EXEC_FILE = 100755;
    const T_SYMLINK = 120000;
    const T_TREE = 40000;
    
    
    public function addToRepo()
    {
        $this->repo->trees[$this->getHash()] = &$this;
    }
    
    public function export()
    {
        $tree = '';
        
        foreach ($this->tree_data as $entry)
        {
            $tree = $tree . $entry['type'] . ' ' . $entry['name'] . "\0" . $entry['object']->getHash(true);
        }
        
        $length = strlen($tree);
        $tree = 'tree ' . $length . "\0" . $tree;
        
        return $tree;
    }
    
    public function processSubpages($ns_id)
    {
        if (!MWNamespace::hasSubpages($ns_id)) { return; }
        
        $subpages = array();
        foreach ($this->tree_data as $key => $entry)
        {
            // Find the part before the first slash
            if(preg_match('~^(.[^\/]*)\/(.+)$~', $entry['name'], $matches) === 0)
            {
                continue;
            }
            
            /* Make sure there's an entry in the array of subpage directories
             * that matches the containing part.
             */
            if (!$subpages[$matches[1]]) { $subpages[$matches[1]] = array(); }
            $new_entry = array(
                'name' => $matches[2],
                'type' => self::T_NORMAL_FILE,
                'object' => &$entry['object'] // Blob doesn't change
            );
            array_push($subpages[$matches[1]], $new_entry); // Add the entry to the list files under the page
            
            unset($this->tree_data[$key]); // Remove the original entry from the main tree
        }
        
        foreach ($subpages as $name => $entry)
        {
            $subpage_tree = new self();
            $subpage_tree->tree_data = $entry;
            $subpage_tree->processSubpages();
            //Illegal characters and capitalization passes...?
            $subpage_tree->addToRepo();
            $subpage_tree_entry = array(
                'name' => $name,
                'type' => self::T_TREE,
                'object' => &$subpage_tree
            );
            array_push($this->tree_data, $subpage_tree_entry);
        }
    }
    
    public static function parse($tree)
    {
        sscanf($tree, "tree %d\0", $length);
        $raw_entries = substr(
            $tree,
            strpos($tree, "\0") + 1,
            $length
        );
        $raw_bytes = str_split($raw_entries);
        
        $tree_data = array();
        
        for ($i = 0; isset($raw_entries[$i]); ++$i)
        {
            /* Either the null is marking the beginning of the hash,
             * or it is part of the hash itself. However, the latter
             * case should not appear, since $i will be pushed past
             * the hash automatically upon encountering any NUL char.
             */
            if ($raw_bytes[$i] === "\0")
            {
                if (!isset($beginning_of_entry))
                {
                    /* "Rewind" to beginning of string to get type and name.
                     * This relies on a subtle difference between isset() and
                     * empty().
                     */
                    $beginning_of_entry = 0;
                }
                
                sscanf(
                    substr($raw_entries, $beginning_of_entry, $i - $beginning_of_entry),
                    "%d %s[^\t\n]",
                    $type_id,
                    $filename
                );
                
                $hash_bin = substr($raw_entries, $i + 1, 20);
                
                $file_entry = array('type' => $type_id, 'name' => $filename, 'hash_bin' => $hash_bin);
                array_push($tree_data, $file_entry);
                
                $i = $i + 21; // Push $i past the hash
                $beginning_of_entry = $i;
            }
        }
        // Fetch the object in object form
        foreach ($tree_data as $key => $entry)
        {
            switch $entry['type'] {
                case self::T_NORMAL_FILE:
                case self::T_EXEC_FILE:
                    $tree_data[$key]['object'] = $this->repo->&fetchBlob(bin2hex($entry['hash_bin']));
                    break;
                case self::T_TREE:
                    $tree_data[$key]['object'] = $this->repo->&fetchTree(bin2hex($entry['hash_bin']));
                    break;
                default:
                    // Panic/convulsions...? Who knows?
            }
        }
        
        return $tree_data;
    }
    
    public static function newFromData($data)
    {
        $instance = new self();
        $instance->tree_data = self::parse($data);
    }
    
    public static function newRoot($rev_id, $log_id,)
    {
        $instance = self::newFromNamespace($rev_id, $log_id, NS_GITACCESS_ROOT);
        
        $namespaces = array_flip(MWNamespace::getCanonicalNamespaces());
        $namespaces = array_fill_keys(array_keys($namespaces), 1); // All namespaces included
        $namespaces = array_merge($namespaces, $GLOBALS['wgGitAccessNSIncluded']); // Un-include some namespaces
        $namespaces['Media'] = false; // Un-include dynamic namespaces
        $namespaces['Special'] = false;
        
        foreach ($namespaces as $name => $isIncluded)
        {
            if (!$isIncluded) { continue; }
            
            $ns_tree = self::newFromNamespace(
                $rev_id,
                $log_id,
                MWNamespace::getCanonicalIndex(strtolower($name))
            );
            $ns_tree->addToRepo();
            
            array_push(
                $instance->tree_data,
                array(
                    'type' => self::T_TREE,
                    'name' => $name ?: '(Main)',
                    'object' => &$instance
                )
            );
        }
        
        return $instance;
    }
    
    public static function newFromNamespace($rev_id, $log_id, $ns_id)
    {
        $dbw = wfGetDB(DB_MASTER);
        
        /* {{{ SQL stuff */
        $sql = $dbw->selectSQLText(
            array('page', 'revision'),
            array(
                'is_archive' => '\'false\'',
                'page.page_id',
                'page.page_namespace',
                'rev_id' => 'MAX(revision.rev_id)'
            ),
            array(
                'rev_id <= ' . $rev_id,
                'page_namespace' => $ns_id
            ),
            __METHOD__,
            array(
                'GROUP BY' => array('\'false\'', 'page_id', 'page_namespace')
            ),
            array(
                'revision' => array('INNER JOIN', 'page_id = rev_page')
            )
        );
        $sql .= ' UNION ';
        $sql .= $dbw->selectSQLText(
            'archive',
            array(
                'is_archive' => '\'true\'',
                'page_id' => 'ar_page_id',
                'page_namespace' => 'ar_namespace',
                'rev_id' => 'MAX(ar_rev_id)'
            ),
            array(
                'ar_rev_id <= ' . $rev_id,
                'ar_namespace' => $ns_id
            ),
            __METHOD__,
            array(
                'GROUP BY' => array('\'true\'', 'ar_page_id', 'ar_namespace')
            )
        );
        $result = $dbw->query($sql);
        /* }}}  End SQL stuff*/
        
        $mimeTypesRepo = new Dflydev\ApacheMimeTypes\FlatRepository(
            "$IP/extensions/GitAccess/vendor/dflydev-apache-mimetypes/mime.types"
        );
        $tree_data = array();
        do
        {
            $row = $result->fetchRow();
            if ($row)
            {
                // Fetch Revision
                if ($row['is_archive'] === 'true')
                {
                    $ar_row = $dbw->selectRow(
                        'archive',
                        Revision::selectArchiveFields(),
                        array('ar_rev_id' => $row['rev_id'])
                    );
                    $revision = Revision::newFromArchiveRow($ar_row);
                }
                else
                {
                    $revision = Revision::newFromId($row['rev_id'], Revision::READ_LATEST);
                }
                
                $titleValue = self::getTitleAtRevision($revision, $log_id);
                $blob = GitBlob::newFromRaw($revision->getContent(Revision::RAW)->serialize());
                $blob->addToRepo();
                array_push(
                    $tree_data,
                    array(
                        'type' => self::T_NORMAL_FILE,
                        'name' => $titleValue->getDBKey() . ($ns_id != NS_FILE) 
                                        ? self::determineFileExt($titleValue, $revision)
                                        : self::determineFileExt($titleValue, $revision, true),
                        'object' => &$blob
                    )
                );
                
                if ($ns_id == NS_FILE) { self::fetchFile($tree_data, $revision, $titleValue); }
            }
        }
        while ($row);
        
        $instance = new self();
        $instance->tree_data = $tree_data;
        
        return $instance;
    }
    
    public static function fetchFile(&$tree_data, Revision $revision, TitleValue $title)
    {
        $file = RepoGroup::singleton()->getLocalRepo()->newFile(
            $revision->getTitle(),
            $revision->getTimestamp()
        );
        if (!$file) { return; }
        /* newFile() always returns an OldLocalFile instance,
         * so OldLocalFile::getRel() always returns a path containing
         * 'archive'. However if the file is actually the current
         * version, getArchiveName() will return NULL.
         */
        $fileIsOld = $file->getArchiveName() ? true : false;
        if ($fileIsOld)
        {
            $path = $IP . '/images/' . $file->getRel() . $file->getArchiveName();
        }
        else
        {
            preg_match('~^archive\\/(.*)$~', $file->getRel(), $matches);
            $path = $IP . '/images/' . $matches[1] . $file->getName();
        }
        $blob = GitBlob::newFromRaw(file_get_contents($path));
        $blob->addToRepo();
        
        array_push(
            $tree_data,
            array(
                'type' => ($file->getMediaType == MEDIATYPE_EXECUTABLE)
                            ? self::T_EXEC_FILE
                            : self::T_NORMAL_FILE,
                'name' => $title->getDBKey(),
                'object' => &$blob
            )
        );
    }
    
    public static function getTitleAtRevision(Revision $revision, $log_id = null)
    {
        $dbw = wfGetDB(DB_MASTER);
        $conds = array(
            'log_page' => $revision->getPage(),
            'log_action' => 'move',
            'log_timestamp <= ' . $revision->getTimestamp(),
        );
        if ($log_id) { array_push($conds, 'log_id <= ' . $log_id); }
        
        $result = $dbw->selectRow(
            'logging',
            array(
                'log_id' => 'MAX(log_id)'
            ),
            $conds
        );
        if ($result->log_id)
        {
            $titleText = DatabaseLogEntry::newFromRow(
                $dbw->selectRow(
                    'logging',
                    '*',
                    'log_id=' . $result->log_id
                )
            )->getParameters()['4::target'];
            
            return MediaWikiServices::getInstance()->getTitleParser()->parseTitle($titleText, NS_MAIN);
        }
        else
        {
            return new TitleValue($revision->getTitle()->getNamespace(), $revision->getTitle()->getDBKey());
        }
    }
    
    public static function determineFileExt(TitleValue $title, Revision $rev, $is_file_page = false)
    {
        $mimeTypesRepo = new Dflydev\ApacheMimeTypes\FlatRepository(
            "$IP/extensions/GitAccess/vendor/dflydev-apache-mimetypes/mime.types"
        );
        
        preg_match('~^.*\.(.[^\.]*)$~', $title->getDBKey(), $matches);
        $extFromTitle = !empty($matches[1]) ? $matches[1] : null;
        
        if (!$is_file_page && $extFromTitle && $mimeTypesRepo->findType($extFromTitle))
        {
            return $extFromTitle;
        }
        else
        {
            return $mimeTypesRepo->findExtensions($rev->getContentFormat())[0];
        }
    }
}
