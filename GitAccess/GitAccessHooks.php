<?php
class GitAccessHooks
{
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
        
        return true;
    }
}       
