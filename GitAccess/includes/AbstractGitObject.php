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
    
    abstract public function addToRepo();
    abstract public function export();
    
    public function __construct()
    {
        $this->repo = &GitRepository::singleton();
        $this->dbw = wfGetDB(DB_MASTER);
    }
    
    public function getHash($binary = false)
    {
        if (!$this->hash)
        {
            $this->hash = hash('sha1', $this->export(), true);
        }
        return $binary ? $this->hash : bin2hex($this->hash);
    }
    
    abstract public static function newFromData();
}
