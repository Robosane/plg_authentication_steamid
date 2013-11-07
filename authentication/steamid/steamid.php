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
        $return_url = JRoute::_($this->_getReturnURL(), true, -1);
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
                ->select('id, password')
                ->from('#__users')
                ->where('username=' . $db->quote($steamid));

            $db->setQuery($query);
            $result = $db->loadObject();

            if ($result) {
                $user = JUser::getInstance($result->id);
                $response->username = $user->username;
                $response->email    = $user->email;
                $response->fullname = $user->name;
            } else {
                // Use steamid as new username
                $response->username = $steamid;

                // Get names from Steam API
                $player_summaries = json_decode(file_get_contents('http://api.steampowered.com/ISteamUser/GetPlayerSummaries/v0002/?key=E6C7134FF86C803B2A04D974976AE561&steamids='.$steamid), true);
                $player_summary = $player_summaries['response']['players'][0];

                if ($player_summary['realname'])
                    $response->fullname = $player_summary['realname'];
                elseif ($player_summary['personaname'])
                    $response->fullname = $player_summary['personaname'];
                else
                    $response->fullname = $steamid;

                // Generate random password
                // NOTE Don't know why Joomla doesn't use these when creating new user
                $response->password = substr(str_shuffle("0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ"), 0, 10);
                $response->password_clear = $response->password;

                // Generate email
                $response->email = $steamid . '@steampowered.com';
            }

            // Return success status
            $response->status = JAuthentication::STATUS_SUCCESS;
            break;
        case Auth_OpenID_FAILURE:
        case Auth_OpenID_CANCEL:
        default:
            $response->status = JAuthentication::STATUS_FAILURE;
            $response->error_message = JText::_('PLG_AUTH_STEAMID_FAILURE');
            break;
        }
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
