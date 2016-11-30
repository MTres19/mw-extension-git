<?php
/**
 * GitAccess MediaWiki Extension---Access wiki content with Git.
 * Copyright (C) 2016  Matthew Trescott
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
 
/**
 * Hooks for GitAccess extension
 * 
 * @file
 */

/**
 * Hooks for GitAccess extension
 */
class GitAccessHooks
{
    /**
     * Hook to create needed database tables.
     * 
     * Run by update.php.
     * 
     * @param DatabaseUpdater $dbUpdater The DatabaseUpdater object
     */
    public static function onLoadExtensionSchemaUpdates($dbUpdater = null)
    {
        if (!$dbUpdater->tableExists('git_hash'))
        {
            $dbUpdater->addExtensionTable(
                'git_hash',
                dirname(__FILE__) . '/sql/git_hash_schema_mysql.sql'
            );
        }
        
        if (!$dbUpdater->tableExists('git_edit_hash'))
        {
            $dbUpdater->addExtensionTable(
                'git_edit_hash',
                dirname(__FILE__) . '/sql/git_edit_hash_schema_mysql.sql'
            );
        }
        
        if (!$dbUpdater->tableExists('git_status_modify_hash'))
        {
            $dbUpdater->addExtensionTable(
                'git_status_modify_hash',
                dirname(__FILE__) . '/sql/git_status_modify_hash_schema_mysql.sql'
            );
        }
        
        if (!$dbUpdater->tableExists('git_tag'))
        {
            $dbUpdater->addExtensionTable(
                'git_tag',
                dirname(__FILE__) . '/sql/git_tag_schema_mysql.sql'
            );
        }
        
        return true;
    }
}       
