<?php

class SpecialGitAccess extends SpecialPage
{
    public function __construct()
    {
        parent::__construct("GitAccess", "gitaccess"); // Sysops only
    }
    
    public function execute($subpath)
    {
        $output = $this->getOutput();
        $request = $this->getRequest();
        
        if (!isset($subpath) && !isset($request->getText("service"))) // Show information page
        {
            $output->setPageTitle($this->msg("gitaccess"));
            $output->addWikiText($this->msg("gitaccess-desc"));
            $output->addWikiText($this->msg("gitaccess-specialpagehome-loggedin-info"));
            
            // Check permissions
            if (!$this->getUser()->isAllowed("gitaccess"))
            {
                throw new PermissionsError("gitaccess");
            }
            
            if (wfReadOnly())
            {
                throw new ReadOnlyError;
            }
            
            if ($this->getUser()->isBlocked())
            {
                throw new UserBlockedError($this->getUser()->mBlock);
            }
        }
        
        else if ($subpath && isset($request->getText("service"))) // Generate git repo
        {
            $output->disable(); // Take over output
            
            $token = strtok($subpath, "/");
            $path_objects = array();
            while ($token)
            {
                $token = strtok("/");
                array_push($path_objects, $token);
            }
            
            $repo = new GitRepository($path_objects);
            
            if ($request->getText("service") = "git-upload-pack")
            {
            
            }
            
            else if ($request->getText("service") = "git-receive-pack")
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
