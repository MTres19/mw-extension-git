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
 
class GitClientCommunication
{
    protected $output;
    protected $requst;
    protected $response;
    protected $path;
    protected $type;
    
    const TYPE_REF_DISCOVERY_UPLOAD = 0;
    const TYPE_REF_DISCOVERY_RECEIVE = 1;
    const TYPE_UPLOAD_PACK = 2;
    const TYPE_RECEIVE_PACK = 3;
    
    public function __construct(&$output, &$request, &$response, $path, $type)
    {
        $this->output = $output;
        $this->request = $request;
        $this->response = $response;
        $this->path = $path;
        $this->type = $type;
        
        $this->output->disable(); // Take over output
    }
    
    public function auth()
    {
        // Auth
        $authManagerSingleton = MediaWiki\Auth\AuthManager::singleton();
        $user = User::newFromName($_SERVER["PHP_AUTH_USER"]);
        
        if (empty($user) || empty($_SERVER["PHP_AUTH_PW"])) /* Missing username or password */
        {
            $this->response->header('WWW-Authenticate: Basic realm="MediaWiki"');
            $this->response->header("HTTP/1.1 401 Unauthorized");
        }
        else
        {
            /* Create AuthenticationRequest---add username and password to object using loadRequestsFromSubmission()
             * 
             * The User parameter is needed because there is no session data provided by the git client.
             */
            $authenticationRequest = MediaWiki\Auth\AuthenticationRequest::loadRequestsFromSubmission(
                $authManagerSingleton->getAuthenticationRequests($authManagerSingleton::ACTION_LOGIN, $user),
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
                $this->response->header("HTTP/1.1 403 Forbidden");
                
                echo "Sorry, accessing this wiki with Git requires permissions which you do not have." . PHP_EOL;
                return false;
            }
            elseif ($authResult->status == $authResult::FAIL)
            {
                $this->response->header('WWW-Authenticate: Basic realm="MediaWiki"');
                $this->response->header("HTTP/1.1 401 Unauthorized");
                
                return false;
            }
            else
            {
                $this->response->header('WWW-Authenticate: Basic realm="MediaWiki"');
                $this->response->header("HTTP/1.1 501 Not Implemented");
                
                echo "Your request cannot be processed as authentication requires further information." . PHP_EOL;
                return false;
            }
        }
    }
    
    /* Doesn't work for last pkt-line, but that's easy because it's always 0000.
     * Each pkt-line is a 4-character hex number denoting the length of that line,
     * including its LF/CRLF character(s), and the length of the next hex number
     * (which is always 4 except on the last line, or, depending on interpretation,
     * the hex number itself.
     */
    public static function pktLineEncode($string)
    {
        $num = strlen($string) + 4;
        return dechex($num) . $string;
    }
    
    public static function pktLineDecode($string)
    {
        $length = hexdec(substr($string, 0, 3));
        $pktLineContent = substr($string, 4);
        
        if ($length - 4 === strlen($pktLineContent))
        {
            return $pktLineContent;
        }
        else
        {
            return false;
        }
    }
}
