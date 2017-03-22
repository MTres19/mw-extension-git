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
 * 
 * @file
 */

// Don't reveal path when PHP fails to extend class
if (!defined('MEDIAWIKI'))
{
    die('Not a valid entry point.');
}

/**
 * Immutable class representing a Git blob object.
 */
class GitBlob extends AbstractGitObject
{
    /** @var string Contains the data of the the blob */
    protected $data;
    
    /**
     * Return the raw data, without the blob prefix
     */
    public function getData()
    {
        return $this->data;
    }
    
    public function getHash($binary = false)
    {
        if (!$this->hash)
        {
            $this->hash = hash('sha1', $this->export(), true);
        }
        return $binary ? $this->hash : bin2hex($this->hash);
    }
    
    public function export()
    {
        return 'blob ' . strlen($this->data) . "\0" . $this->data;
    }
    
    public function addToRepo()
    {
        $this->repo->blobs[$this->getHash()] = &$this;
    }
    
    public static function newFromData($blob)
    {
        sscanf($blob, "blob %d\0", $length);
        $data = substr(
            $blob,
            strpos($blob, "\0") + 1,
            $length
        );
        return new self($data);
    }
    
    /**
     * Create a new GitBlob from raw data, like what one might find in the text table.
     * @param string The raw data (without Git headers) to make the blob from
     */
    public static function newFromRaw($data)
    {
        $instance = new self();
        $instance->data = $data;
        return $instance;
    }
}
