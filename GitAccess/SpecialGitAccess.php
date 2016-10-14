<?php

class SpecialGitAccess extends SpecialPage
{
    protected $output;
    protected $request;
    protected $response;
    
    public function __construct()
    {
        parent::__construct("GitAccess", "gitaccess"); // Sysops only
        $this->output = $this->getOutput();
        $this->request = $this->getRequest();
        $this->response = $request->response();
    }
    
    public function execute($subpath)
    {
        $request_service = $this->request->getText("service");
        
        if (!isset($subpath) && !isset($request_service)) // Show information page
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
        
        else if ($subpath && isset($request_service)) // Generate git repo
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
            
            if ($request_service = "git-upload-pack")
            {
            
            }
            
            else if ($request_service = "git-receive-pack")
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
    
    protected function auth()
    {
        // Auth
        $authManagerSingleton = \MediaWiki\Auth\AuthManager::singleton();
        
        $user = User::newFromName($_SERVER["PHP_AUTH_USER"]); // Create User instance and fetch data
        
        if ($user->getID() = 0) /* No username sent */
        {
            $response->header('WWW-Authenticate: Basic realm="MediaWiki"');
            $response->header("HTTP/1.1 401 Unauthorized");
        }
        else
        {
            // Create AuthenticationRequest---add username and password to object using loadRequestsFromSubmission()
            $authenticationRequest = \MediaWiki\Auth\AuthenticationRequest::loadRequestsFromSubmission(
                $authManagerSingleton->getAuthenticationRequests($authManagerSingleton->ACTION_LOGIN),
                [
                    "username" => $_SERVER["PHP_AUTH_USER"],
                    "password" => $_SERVER["PHP_AUTH_PW"],
                ]
            );
            
            // Check password
            $authResult = $authManagerSingleton->beginAuthentication($authenticationRequest, ':null');
            
            if ($authResult->status == $authResult::PASS && $user->isAllowed("gitaccess"))
            {
                return true;
            }
            elseif ($authResult->status == $authResult::PASS && !$user->isAllowed("gitaccess"))
            {
                $response->header('WWW-Authenticate: Basic realm="MediaWiki"');
                $response->header("HTTP/1.1 401 Unauthorized");
                
                echo "Sorry, accessing this wiki with Git requires permissions which you do not have.";
                return false;
            }
            elseif ($authResult->status == $authResult::FAIL)
            {
                $response->header('WWW-Authenticate: Basic realm="MediaWiki"');
                $response->header("HTTP/1.1 401 Unauthorized");
                
                echo "Invalid username or password.";
                return false;
            }
            else
            {
                echo "Unknown authentication error.";
                return false;
            }
        }
    }
}



?>
