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

abstract class AbstractGitObject
{
    protected $dbw;
    protected $hash;
    protected $repo;
    
    /**
     * Add the object to the GitRepository singleton.
     * Calls AbstractGitObject::getHash(), so be make sure any filter passes
     * have already been run when using on a GitTree object.
     */
    abstract public function addToRepo();
    
    /**
     * Get the serialized form of the object in Git's format.
     * This is the data that is hashed to get the object's hash.
     * 
     * @return string The serialized form of the object, ready to be used in a real repository
     */
    abstract public function export();
    
    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->repo = &GitRepository::singleton();
        $this->dbw = wfGetDB(DB_MASTER);
    }
    
    /**
     * Get the SHA-1 hash of the exported version of the object.
     * If the $binary parameter is set to false (default), the 40-character hexadecimal
     * representation of the hash will be returned.
     * 
     * @param bool $binary (optional) Return the binary 20-byte checksum in a binary string.
     */
    public function getHash($binary = false)
    {
        if (!$this->hash)
        {
            $this->hash = hash('sha1', $this->export(), true);
        }
        return $binary ? $this->hash : bin2hex($this->hash);
    }
    
    /**
     * Parse/de-serialize a raw Git object, as perhaps sent in a packfile.
     * 
     * @param string $data The raw (but uncompressed) Git object to parse
     */
    abstract public static function newFromData($data);
}
