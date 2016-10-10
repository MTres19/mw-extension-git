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
            $output->addWikiText($this->msg("gitaccess-desc"));
            $output->addWikiText($this->msg("gitaccess-specialpagehome-loggedin-info"));
        }
        
        else if ($subpath && isset($_GET["service"])) // Generate git repo
        {
            $token = strtok($subpath, "/");
            $path_objects = array();
            while ($token)
            {
                $token = strtok("/");
                array_push($path_objects, $token);
            }
            
            $repo = new GitRepository($path_objects);
            
            if ($_GET["service"] = "git-upload-pack")
            {
            
            }
            
            else if ($_GET["service"] = "git-receive-pack")
            {
            
            }
        }
        
        else
        {
            $output->setPageTitle($this->msg("gitaccess"));
            $output->addWikiText($this->msg("gitaccess-error-dumbhttpaccess"));
        }
    }
    
    public function doesWrites()
    {
        return true; // Overload class to show that this may perform database writes
    }
}



?>
