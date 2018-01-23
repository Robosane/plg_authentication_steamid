<?php
/**
* @version     1.0.0
* @package     Joomla.Plugin
* @subpackage  Authentication.SteamID
* @copyright   Copyright (C) 2013. All rights reserved.
* @license     GNU General Public License version 2 or later; see LICENSE.txt
* @author      DZ Team <dev@dezign.vn> - dezign.vn
*/

defined('_JEXEC') or die;

jimport('joomla.log.log');
JLog::addLogger(
    array(
         // Sets file name
         'text_file' => 'plg_steamid.log.php'
    ),
    // Sets messages of all log levels to be sent to the file
    JLog::ALL,
    // The log category/categories which should be recorded in this file
    // In this case, it's just the one category from our extension, still
    // we need to put it inside an array
    array('plg_steamid')
);
//JLog::add(JText::_('JTEXT_ERROR_MESSAGE'), JLog::WARNING, 'plg_steamid');
// XXX

/**
 * Generate a random string, using a cryptographically secure
 * pseudorandom number generator (random_int)
 *
 * For PHP 7, random_int is a PHP core function
 * For PHP 5.x, depends on https://github.com/paragonie/random_compat
 *
 * @param int $length      How many characters do we want?
 * @param string $keyspace A string of all possible characters to select from
 * @return string
 */
function random_str(
    $length,
    $keyspace = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ'
) {
    $str = '';
    $max = mb_strlen($keyspace, '8bit') - 1;
    if ($max < 1) {
        throw new Exception('$keyspace must be at least two characters long');
    }
    for ($i = 0; $i < $length; ++$i) {
        $str .= $keyspace[random_int(0, $max)];
    }
    return $str;
}

// OpenID library classes
if (file_exists(JPATH_LIBRARIES.'/openid')) {
    // OpenID library include path
    $path = ini_get('include_path');
    $path_extra = JPATH_LIBRARIES.'/openid';
    $path = $path_extra . PATH_SEPARATOR . $path;
    ini_set('include_path', $path);

    // Required classes for the plugin
    require_once 'Auth/OpenID/Consumer.php';
    require_once 'Auth/OpenID/JDatabaseStore.php';
}

class PlgAuthenticationSteamID extends JPlugin
{
    protected $_type='authentication';
    protected $_name='steamid';

    /**
     * Return 1 if https url, 2 if http,
     * -1 if neither.
     * Used when creating callback URL with JRoute().
     */
    protected function usessl_int() {
        $uri = JUri::getInstance();
        if ($uri->getScheme() == 'https') {
            return 1;
        } else if ($uri-getScheme() == 'http') {
            return 2;
        } else {
            return -1;
        }
    }

    /**
     * This method should handle any authentication and report back to the subject
     *
     * @param   array   $credentials  Array holding the user credentials
     * @param   array   $options      Array of extra options
     * @param   object  &$response    Authentication response object
     *
     * @return  boolean
     *
     * @since   1.5
     */
    public function onUserAuthenticate($credentials, $options, &$response)
    {
        // Check for library existance first
        if (!class_exists('Auth_OpenID_Consumer'))
            return; // Bail
        $response->type = 'SteamID';

        $identifier = 'http://steamcommunity.com/openid';

        $store = new Auth_OpenID_JDatabaseStore();
        $consumer = new Auth_OpenID_Consumer($store);
        $return_url = JRoute::_($this->_getReturnURL(), true, $this->usessl_int() );
        //JLog::add(JText::_('RETURN_URL CREATED: ' . $return_url ), JLog::DEBUG, 'plg_steamid'); // XXX
        try {
            $op_response = $consumer->complete($return_url);
        } catch (Exception $e) {

        }

        switch ($op_response->status) {
            case Auth_OpenID_SUCCESS:
            // Get the SteamID
            $openid = $op_response->getDisplayIdentifier();
            preg_match('/(\d+)\D*$/', $openid, $matches);
            $steamid = $matches[1];

            // Check for user existance in database
            $db     = JFactory::getDbo();
            $query  = $db->getQuery(true)
                ->select('u.id')
                ->from('#__users as u')
                ->join('left', '#__steamid as s ON s.user_id = u.id')
                ->where('steamid = ' . $db->quote($steamid));

            $db->setQuery($query);
            $result = $db->loadObject();

            if ($result) {
                $user = JUser::getInstance($result->id);
                $response->username = $user->username;
                $response->email    = $user->email;
                $response->fullname = $user->name;

                $session = &JFactory::getSession();
                $session->set('user.steamid_connected', true);
            } else {
                // Get names from Steam API
                $player_summaries = json_decode(file_get_contents('http://api.steampowered.com/ISteamUser/GetPlayerSummaries/v0002/?key=E6C7134FF86C803B2A04D974976AE561&steamids='.$steamid), true);
                $player_summary = $player_summaries['response']['players'][0];
                $personaname = $player_summary['personaname'];
                $realname = !empty($player_summary['realname']) ? $player_summary['realname'] : '';
                $avatar = $player_summary['avatarfull'];
                $profileurl = $player_summary['profileurl'];

                // Check for steamid existence
                $query = $db->getQuery(true)
                    ->select('COUNT(*)')
                    ->from('#__steamid')
                    ->where('steamid = ' . $db->quote($steamid));
                $db->setQuery($query);
                $count = $db->loadResult();

                if (!$count) {
                    // Store credentials into Steam ID table
                    $query = $db->getQuery(true)
                        ->insert('#__steamid')
                        ->columns(array('steamid', 'personaname', 'realname', 'avatar', 'profileurl'))
                        ->values(implode(',', array(
                            $db->quote($steamid),
                            $db->quote($personaname),
                            $db->quote($realname),
                            $db->quote($avatar),
                            $db->quote($profileurl)
                        )));
                    $db->setQuery($query);
                    $db->query();
                }

                // Set fullname
                $response->fullname = $personaname;

                // Set user name
                if ($personaname) {
                    setlocale(LC_CTYPE, 'vi_VN');
                    $response->username = strtolower(iconv("UTF-8", "ASCII//TRANSLIT//IGNORE", $personaname));
                    $response->username = preg_replace(array('/\s+/', '/\W/', '/_+/') , array('_', '', '_'), $response->username);

                    if (!$response->username) {
                        $response->username = $steamid;
                    } else {
                        // Ensure unique username
                        $response->username .= ("_" . substr($steamid, -3, 3));
                    }
                } else {
                    $response->username = $steamid;
                }

                JLog::add(JText::_('SteamID auth from user: ' . $response->username ), JLog::DEBUG, 'plg_steamid'); // XXX

                // Generate random password
                // NOTE Don't know why Joomla doesn't use these when creating new user
                $response->password = random_str(24);
                $response->password_clear = $response->password;

                // Generate email
                $response->email = $steamid . '@steampowered.com';

                // Set steamid on session
                $session = &JFactory::getSession();
                $session->set('user.steamid', $steamid);
                $session->set('user.steamid_connected', false);
            }

            // Return success status
            $response->status = JAuthentication::STATUS_SUCCESS;
            break;
        case Auth_OpenID_FAILURE:
        case Auth_OpenID_CANCEL:
        default:
            $response->status = JAuthentication::STATUS_FAILURE;
            $response->error_message = JText::sprintf('PLG_AUTH_STEAMID_FAILURE', $op_response->message);
            JLog::add(JText::_('AUTH_STEAMID_FAIL: ' . $op_response->message ), JLog::WARNING, 'plg_steamid');
            JLog::add(JText::_('RESPONSE ERRMSG: ' . $op_response->error_message ), JLog::DEBUG, 'plg_steamid'); // XXX
            JLog::add(JText::_('RESPONSE STATUS: ' . $op_response->status ), JLog::DEBUG, 'plg_steamid'); // XXX
            break;
        }
    }

    /**
     * Connect SteamID to User entry
     */
    public function onUserAfterLogin($options)
    {
        $session = &JFactory::getSession();
        if ($session->get('user.steamid_connected', true) == false) {
            // Connect steamid to user
            $user = $options['user'];
            $db = JFactory::getDbo();
            $query = $db->getQuery(true)
                ->update('#__steamid')
                ->set('user_id = ' . $user->id)
                ->where('steamid = ' . $db->quote($session->get('user.steamid', 'not set steamid')));
            $db->setQuery($query);
            $db->query();

            // Clear session
            $session->clear('user.steamid');

            // Indicate first time login
            $session->set('user.first_connect', true);
        }
        $session->clear('user.steamid_connected');

        return true;
    }

    private function _getReturnURL()
    {
        $app    = JFactory::getApplication();
        $router = $app->getRouter();
        $url = null;

        // Stay on the same page
        $uri = clone JUri::getInstance();
        $vars = $router->parse($uri);
        unset($vars['lang']);
        if ($router->getMode() == JROUTER_MODE_SEF)
        {
            if (isset($vars['Itemid']))
            {
                $itemid = $vars['Itemid'];
                $menu = $app->getMenu();
                $item = $menu->getItem($itemid);
                unset($vars['Itemid']);
                if (isset($item) && $vars == $item->query)
                {
                    $url = 'index.php?Itemid='.$itemid;
                }
                else {
                    $url = 'index.php?'.JUri::buildQuery($vars).'&Itemid='.$itemid;
                }
            }
            else
            {
                $url = 'index.php?'.JUri::buildQuery($vars);
            }
        }
        else
        {
            $url = 'index.php?'.JUri::buildQuery($vars);
        }


        return $url;
    }
}
