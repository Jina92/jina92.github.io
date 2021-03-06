<?php
require_once('../vendor/autoload.php');
require_once('./db.php');
require_once('./se.php');
require_once('./ft.php');

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Attribute\AttributeBag;
use Symfony\Component\HttpFoundation\Session\Storage\NativeSessionStorage;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Dotenv\Dotenv;

$dotenv = new Dotenv();   
$dotenv->overload('./.env');  

/*  $dbUser = getenv('DBUSER');  ==> getenv didn't work. You can use $_ENV
 *  $dotenv->load() automatically set the env variable from .env to $_ENV 
 *  This should be before "new mwModel", because mwModel constructor use it.  
 */

/*****************************************************************
 * Instantiated the database class
 * The main job of API is connecting and manipulating data of the database.
 * mwModel is the class of Database connection and manipulation
 * The mwDB variable is instanciated of the mwModel class.
 * Most of API will use this variable as a global to connect the database from anywhere in these API. 
 * Therefore it needs to be instanciated at the first part of API. 
 ******************************************************************/
$mwDB = new mwModel;   // mwModel: user-define class for database connection
$request = Request::createFromGlobals();
$response = new Response();

/*****************************************************************
 * Instantiated the session class
 * All API functions need to be managed by session including database connection functions. 
 * The session object needs to be instantiated 
 ******************************************************************/
$session = new Session();

$response->headers->set('Content-Type', 'application/json');
$response->headers->set('Access-Control-Allow-Headers', 'origin, content-type, accept');
$response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
//$response->headers->set('Access-Control-Allow-Origin', $_ENV['ORIGIN']);
$response->headers->set('Access-Control-Allow-Origin', "http://localhost");
$response->headers->set('Access-Control-Allow-Credentials', 'true');
$requestMethod = $request->getMethod();
$selectedAction = null;


/* Origin blocing */
/* permit only the connection from the whitelist */
/* whiltelist: moniweb.herokuapp.com */ 
$originHost = $request->headers->get('ORIGIN');
$originPass = false;
if (isset($originHost)) {
    if (strpos($request->headers->get('ORIGIN'), "localhost") !== false) 
        $originPass = true; 
} 
if ($originPass === false) {
    if (strpos($request->headers->get('referer'), "localhost") !== false) {
        $originPass = true; 
    }
}
if ($originPass === false) { 
    $response->setStatusCode(403);
    //$thisSession->logEvent($request->getClientIp(), $request->cookies->get('PHPSESSID'), "originblocked", $response->getStatusCode());
    $response->send();
    return;
} 


/********************************************************
 * Session start 
 ********************************************************/
$session->start();
// If a session object is not set,  it creates new session object. 
// If a session object is already set, it means an session is connected 
// and you can use exiting session object 
// FYI, login status will be checked in each function of the session object 
if(!$session->has('sessionObj')) {
    $session->set('sessionObj', new mwSession);   // If a session object is not defined, create a new session object                                               // user-define class 
}

$thisSession = $session->get('sessionObj');
if(empty($request->query->all())) {   // Error if there are no GET parameters
    $response->setStatusCode(400);   
} 
elseif (($request->query->has('action')) and ($request->query->getAlpha('action') == 'check')) {  // Check an URL
    if($thisSession->isRateLimited()) { // check two conditions and limit the access. 
        $response->setStatusCode(429); // 429 Too Many Requests
    }
    else {
        // Check a single URL, login is not necessary 
        // This is not logged 
        if($request->query->has('url')) { 
            $urlVal = testInput($request->query->get('url'));
            $res = checkURL($urlVal); 
            $response->setStatusCode(200);
            switch($res) { // returned HTTP Status code
                case 200: 
                case 302: 
                    $response->setContent(json_encode("The site works well."));
                    break;
                case 0:  
                    $response->setContent(json_encode("The site does not work."));
                    break;
                default: 
                    $response->setContent(json_encode("Please input exact URL form such as www.sitename.com"));
                    break;
            }
        } 
        else {
            $response->setStatusCode(400);
            $response->setContent(json_encode("Check your input"));
        }
    }
    $response->send();
    return;
}
elseif (($request->query->has('action')) and ($request->query->getAlpha('action') == 'login')) { // Login
    if($thisSession->isRateLimited()) { // check two conditions and limit the access. 
        $response->setStatusCode(429); // 429 Too Many Requests
    } 
    else {
        if($request->request->has('loginEmailAddress') and $request->request->has('loginPassword')) {
            $loginEmailVal = testInput($request->request->get('loginEmailAddress'));
            $loginPassVal = testInput($request->request->get('loginPassword'));
            $res = $session->get('sessionObj')->login($loginEmailVal, $loginPassVal);
            if ($res == false) {
                $response->setStatusCode(401); // 401 Unauthorised 
            // } elseif(count($res) == 1) {
            //     $response->setStatusCode(203);
            //     $response->setContent(json_encode($res));
            } elseif(count($res) > 0) {
                $response->setStatusCode(200); // 200 OK Request was successful
                $response->setContent(json_encode($res));
            }
        } else {
            $response->setStatusCode(400); // 400 Bad Request
        }
    }
    $thisSession->logEvent($request->getClientIp(), $session->getId(), $request->query->getAlpha('action'), $response->getStatusCode());
    $response->send();
    return;
} 
elseif ($request->query->getAlpha('action') == 'adminlogin') { // Admin Login, No session 
    // $request->toArray(); // for JSON format 
    if($request->request->has('email') and
        $request->request->has('password')) {
            $emailVal = testInput($request->request->get('email'));
            $passVal = $request->request->get('password');
            $res = $session->get('sessionObj')->loginAdmin($emailVal, $passVal);
        if ($res == false) {
            $response->setStatusCode(401); // 401 Unauthorised 
        // } elseif(count($res) == 1) {
        //     $response->setStatusCode(203);
        //     $response->setContent(json_encode($res));
        } elseif(count($res) > 0) {
            $response->setStatusCode(200);  // 200 OK Request was successful
            $response->setContent(json_encode($res));
        }
    } else {
        $response->setStatusCode(400); // 400 Bad Request 
    }
} 
elseif($request->cookies->has('PHPSESSID')) {
    
    // check too frequent visits to detect cyber attack
    if($thisSession->isRateLimited()) { // check two conditions and limit the access. 
        $response->setStatusCode(429); // 429 Too Many Requests
    }
    else {
        $selectedAction = $request->query->getAlpha('action');
            
        if($requestMethod == 'POST') { // register, login
            // Register User 
            if($selectedAction == 'register') {  
                if ($request->request->has('email')) { // check the email registered already.
                    //$res = $session->get('sessionObj')->emailExist($request->request->get('email'));
                    $res = $session->get('sessionObj')->emailExist($request->request->filter('email', null, FILTER_VALIDATE_EMAIL));
                        // filter(): the 2nd para is null --> if the $_POST is null, return this 2nd para value 
                    if($res) {
                        $response->setStatusCode(206);  // 206 partial content. Email is registered already 
                        $response->setContent(json_encode("Email exists. You cannot register with this email."));
                    } 
                    else {  // no registered emails, new profile will be registered 
                        if ($request->request->has('firstname') and
                            $request->request->has('lastname') and
                            $request->request->has('password') and 
                            $request->request->has('confirmpassword') and 
                            $request->request->has('phoneno') and 
                            $request->request->has('address') and 
                            $request->request->has('suburb') and
                            $request->request->has('state') and
                            $request->request->has('postcode')) {
                            
                            if (($request->request->get('password')) == ($request->request->get('confirmpassword'))) {
                            
                                $res = $session->get('sessionObj')->register(
                                    $request->request->getAlpha('firstname'), // getAlpha(): Returns the alphabetic characters of the parameter value
                                    $request->request->getAlpha('lastname'),
                                    //$request->request->get('email'),
                                    $request->request->filter('email', null, FILTER_VALIDATE_EMAIL),
                                    testInput($request->request->get('password')),
                                    testInput($request->request->get('phoneno')),
                                    testInput($request->request->get('address')),
                                    $request->request->getAlpha('suburb'),
                                    $request->request->getAlpha('state'), 
                                    $request->request->getDigits('postcode')
                                );
                                
                                if ($res === true) {
                                    $response->setStatusCode(201);  // 201 Created 
                                } elseif ($res === false) {
                                    $response->setStatusCode(403); // 403 Forbidden
                                } elseif ($res === 0) {  
                                    $response->setStatusCode(500);  // 500 Internal Server Error
                                }
                            } else {
                                $response->setStatusCode(500); 
                            }
                        } else {
                            $response->setStatusCode(400); // 400 Bad request
                        }
                    }
                }
            } 

            // Get user profile 
            elseif($selectedAction == 'getProfile') {
                if ($thisSession->isLoggedIn() == false) {
                    $response->setStatusCode(401);  
                }
                elseif($thisSession->getEmail()) {
                    $res = $thisSession->getProfile($thisSession->getEmail());
                    if ($res == false) {
                        $response->setStatusCode(404);  // NOT FOUND
                    } elseif(count($res) > 0) {
                        $response->setStatusCode(200);
                        $response->setContent(json_encode($res));
                    }
                } else {
                    $response->setStatusCode(400);  // BAD Request
                }
            } 
            // update user profile 
            elseif($selectedAction == 'updateProfile') {
                if ($thisSession->isLoggedIn() == false) {
                    $response->setStatusCode(401);  
                }
                elseif( $request->request->has('updateEmail') and 
                    ($thisSession->getEmail() !== $request->request->get('updateEmail')) and 
                    $thisSession->emailExist($request->request->filter('updateEmail', null, FILTER_VALIDATE_EMAIL))) { 
                        $response->setStatusCode(206);  // 206 partial content. New email is registered already 
                        $response->setContent(json_encode("Email exists"));
                } 
                else {  // update profile except password. changing password is separated

                    if ($request->request->has('updateFirstName') and
                        $request->request->has('updateLastName') and
                        $request->request->has('updateEmail') and
                        $request->request->has('updatePhoneNo') and 
                        $request->request->has('updateAddress') and 
                        $request->request->has('updateSuburb') and
                        $request->request->has('updateState') and
                        $request->request->has('updatePostcode')) {

                        $res = $thisSession->updateProfile(
                            $thisSession->getEmail(),  // old email, which saved in a session variable
                            $request->request->getAlpha('updateFirstName'), 
                            $request->request->getAlpha('updateLastName'),
                            $request->request->filter('updateEmail', null, FILTER_VALIDATE_EMAIL), // new email, which is from form 
                            testInput($request->request->get('updatePhoneNo')),
                            testInput($request->request->get('updateAddress')),
                            testInput($request->request->get('updateSuburb')),
                            testInput($request->request->get('updateState')), 
                            $request->request->getDigits('updatePostcode')); 
                        if ($res === true) {
                            $response->setStatusCode(200); 
                        } 
                        elseif ($res === false) {
                            $response->setStatusCode(400); // BAD Request
                        } 
                        elseif ($res === 0) {  
                            $response->setStatusCode(500);  // 500 Internal Server Error
                        }
                    } else {
                        $response->setStatusCode(400);  // BAD Request
                    }
                }
            } 
            elseif($selectedAction == 'changePassword') {
                if ($thisSession->isLoggedIn() == false) {
                    $response->setStatusCode(401);  
                }
                else {  // change  password 
                    if ($request->request->has('currentPassword') and
                        $request->request->has('newPassword') and
                        $request->request->has('confirmNewPassword')) {

                        $res = $thisSession->changePassword(
                            testInput($request->request->get('currentPassword')), 
                            testInput($request->request->get('newPassword')),
                            testInput($request->request->get('confirmNewPassword'))); 
                        if ($res === true) {
                            $response->setStatusCode(200);
                        } 
                        elseif ($res === false) {
                            $response->setStatusCode(400);  // BAD Request
                        }
                        else  {  // none
                            $response->setStatusCode(500);  // 500 Internal Server Error
                        }
                    } 
                    else {
                        $response->setStatusCode(400);  // BAD Request
                    }
                }
            } else {
                $response->setStatusCode(400);
            }
        } 
        if($requestMethod  == 'GET') { // check URL
            if($selectedAction == 'myplan') {
                if ($thisSession->isLoggedIn() == false) {
                    $response->setStatusCode(401);
                }
                else {
                    $res = $thisSession->getPlan();
                    if ($res) {
                        $response->setStatusCode(200);
                        $response->setContent(json_encode($res));
                    } 
                    else {
                        $response->setStatusCode(500);
                    }
                }
            } 
            elseif($selectedAction == 'updateURL') {
                if ($thisSession->isLoggedIn() == false) {
                    $response->setStatusCode(401);  
                }
                elseif ($request->query->has('url') ) {
                    $array_url = testInput(explode("_", $request->query->get('url')));
                    $res = $thisSession->updateURL($array_url); 
                    if ($res) $response->setStatusCode(200);
                    else $response->setStatusCode(400);
                } 
                else {
                    $response->setStatusCode(400);
                }
            }
            elseif($selectedAction == 'upgrade') {
                if ($thisSession->isLoggedIn() == false) {
                    $response->setStatusCode(401);  
                }
                elseif ($request->query->has('level') ) {
                    $levelVar = testInput($request->query->get('level'));  // sanitising
                    $res = $thisSession->upgradePlan($levelVar); 
                    if ($res) $response->setStatusCode(200);
                    else $response->setStatusCode(400);
                }  
            }
            elseif($selectedAction == 'report') {
                if ($thisSession->isLoggedIn() == false) {
                    $response->setStatusCode(401);  
                }
                else {
                    $res = $thisSession->getReport(); 
                    if ($res) 
                        setSessionMessage($response, 200, $res);
                    else 
                        setSessionMessage($response, 400, "No data, please add URLs in your plan");
                }
            }
            elseif($selectedAction == 'logout') {
                $session->clear();
                $session->invalidate();
                $response->setStatusCode(200);
            } 
            elseif($selectedAction == 'isloggedin') {
                if ($thisSession->isLoggedIn() == true) {
                    $response->setStatusCode(200);
                    $response->setContent(json_encode(Array('loggedin'=>'true')));
                }
                else 
                    $response->setStatusCode(401); 
            } 
            else {
                $response->setStatusCode(400);
            }
        }
        if($request->getMethod() == 'DELETE') {           
            $response->setStatusCode(400);
        }
        if($request->getMethod() == 'PUT') {             
            $response->setStatusCode(400);
        }
    }
    $thisSession->logEvent($request->getClientIp(), $request->cookies->get('PHPSESSID'), $selectedAction, $response->getStatusCode());
} 
else {
    // $redirect = new RedirectResponse($_SERVER['REQUEST_URI']);
    $response->setStatusCode(500);
    $response->setContent(json_encode("Please, contact to Moniweb administrator"));
}

$response->send();

?>
