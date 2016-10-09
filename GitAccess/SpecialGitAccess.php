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
        
        if (!isset($subpath) && !isset($_GET["service"])) // Show information page
        {
            $output->setPageTitle($this->msg("gitaccess"));
            $output->addWikiText($this->msg("gitaccess-specialpagehome-loggedin-info"));
        }
        
        else if ($subpath) // Generate git repository objects
        {
            
        }
    }
    
    public function doesWrites()
    {
        return true; // Overload class to show that this may perform database writes
    }
}



?>
