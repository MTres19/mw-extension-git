<?php

class SpecialGitAccess extends SpecialPage
{
    public function __construct()
    {
        parent::__construct("GitAccess");
    }
    
    public function execute($subpath)
    {
        $output = $this->getOutput();
        
        if (!$subpath) // Show information page
        {
            $output->setPageTitle("Git Access");
            $output->addWikiText("You may access the content of this wiki via Git like this:");
            $output->addWikiText("<code>git clone {{SERVER}}/Special:GitAccess/{{SITENAME}}.git</code>");
        }
        
        else if ($subpath) // Generate git repository objects
        {
            
        }
    }
}



?>
