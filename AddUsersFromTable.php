<?php
/**
 * A JApplicationCli application built on the Joomla Platform
 *
 * To run this place it in the cli folder of your Joomla CMS installation. (Or adjust the re
 *
 * @package    Joomla.AddUsers
 * @copyright  Copyright (C) 2013 Elin Waring. All rights reserved.
 * @license    GNU General Public License version 2 or later; see LICENSE
 */


/*
 * This application assumes that the users to be imported are in the table #__user_import which includes fields 
  * with the same name as fields in the user table or profile plugin form. 
  * You need to customize the creation of the profile array to match your profile fields.
  * Make sure that any profile fields are in an enabled profile plugin.
  * 
  * 
  * To run from the command line type with profile fields address1 and address2
  * 	php addusers.php -p='address1,address2'
  * If a password field is not supplied, a random password will be created.  You will not be able to decrypt this password.
  * To access their accounts when a random password has been created users will need to use the password reset function.
  * Users will be enabled.
  */


if (!defined('_JEXEC'))
{
	// Initialize Joomla framework
	define('_JEXEC', 1);
}

@ini_set('zend.ze1_compatibility_mode', '0');
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Load system defines
if (file_exists(dirname(__DIR__) . '/defines.php'))
{
	require_once dirname(__DIR__) . '/defines.php';
}

if (!defined('JPATH_BASE'))
{
	define('JPATH_BASE', dirname(__DIR__));
}

if (!defined('_JDEFINES'))
{
	require_once JPATH_BASE . '/includes/defines.php';
}

// Get the framework.
require_once JPATH_LIBRARIES . '/import.php';
// Get the framework.
require_once JPATH_LIBRARIES . '/import.legacy.php';

// Bootstrap the CMS libraries.
require_once JPATH_LIBRARIES . '/cms.php';

// Import the configuration.
require_once JPATH_CONFIGURATION . '/configuration.php';

// Uncomment this if you want to log
/*
	// Include the JLog class.
	jimport('joomla.log.log');

	// Add the logger.
	JLog::addLogger(
	 
	// Pass an array of configuration options
	array(
		
		
		// Set the name of the log file
		'text_file' => 'test.log.php',
		// (optional) you can change the directory
		'text_file_path' => 'logs'
	 )
	);

	// start logging...
	JLog::add('Starting to log');	
*/

/**
 * Add user
 *
 * @package  Joomla.Shell
 *
 * @since    1.0
 */
class AddUsersFromTable extends JApplicationCli
{
	public function __construct()
	{
		// We're a cli; we don't have a request_uri or a http_host so we have to fake it.
		$_SERVER['HTTP_HOST'] = 'domain.com';
		$_SERVER['REQUEST_URI'] = '/request/';
		

		// Note, this will throw an exception if there is an error
		// System configuration.
		$config = new JConfig;

		// Creating the database connection.
		$this->db = JDatabase::getInstance(
			array(
				'driver' => $config->dbtype,
				'host' => $config->host,
				'user' => $config->user,
				'password' => $config->password,
				'database' => $config->db,
				'prefix' => $config->dbprefix,
			)
		);

		// Fool the system into thinking we are running as JSite
		$this->app = JFactory::getApplication('site');

		define('JPATH_COMPONENT_ADMINISTRATOR', JPATH_ADMINISTRATOR . '/components/com_users');

		// Call the parent __construct method so it bootstraps the application class.
		parent::__construct();
		require_once JPATH_CONFIGURATION . '/configuration.php';
	}

	/**
	 * Entry point for the script
	 *
	 * Note that the profile plugin must be enabled and fields names must match the profile field names in the plugin.
	 * New users are in a table called #__user_import
	 *
	 * @return  void
	 *
	 * @since   1.0
	 */
	public function doExecute()
	{
		// Long args
		$profileFields = $this->input->get('profilefields', null, 'STRING');
		
		// Short args
		if (!$profileFields)
		{
			$profileFields = $this->input->get('p', null, 'STRING');
		}

		$this->createSession();

		// username, name, email are required values.
		// Make sure other field names match the fields in #__users or else add special handling in the foreach below.
		// Note that if your table does not contain passwords random passwords will be created.
		// Change the table name if	required.
		$db = JFactory::getDbo();

		$tableNames = $db->getTableList();
		$importTableName = $db->getPrefix() . 'user_import';

		if (!in_array($importTableName, $tableNames))
		{
			$this->out('An error occured. You do not have a table called #__user_import.');
			$this->out();
			$this->close();
		}

		$query = $db->getQuery(true)
			->select('*')
			->from('#__user_import');
		$db->setQuery($query);
		$newusers = $db->loadObjectList();

		// We're going to use the default group if there is no groups field in the import table or the field is blank.
		$userParams  = JComponentHelper::getParams('com_users');
		$defaultGroup[] =  $userParams->get('new_usertype', 2);

		foreach ($newusers as $newuser)
		{			
			$user = new JUser();

			$array = array();
			$array['username'] = $newuser->username;
			$array['name'] = $newuser->name;
			$array['email'] = $newuser->email;

			if (!empty($newuser->password))
			{
					$array['password'] = $newuser->password;
			}

			if (!empty($newuser->optKey))
			{
					$array['optKey'] = $newuser->optKey;
			}

			if (!empty($newuser->otep))
			{
					$array['otep'] = $newuser->otep;
			}

			if (!empty($newuser->params))
			{
					$array['params'] = $newuser->params;
			}

			$profile = array();

			if (!empty($profileFields))
			{
				$profileFieldsExploded = explode(',', $profileFields);

				foreach ($profileFieldsExploded as $profileField)
				{
					$profile[$profileField] = $newuser->$profileField;
				}
			}

			if (empty($newuser->profile))
			{			
				$array['profile'] = $profile;
			}

			if (empty($newuser->groups))
			{
				$array['groups'] = $defaultGroup;
			}

			if (!$user->bind($array))
			{
				$this->out('Something went wrong in binding the data.');
				$this->out();
			}

			if (!$user->save())
			{
				$this->out('Something went wrong in storing the data.'); 
				$this->out('You may want to check whether username and email are unique for user ' . $newuser->username);
				$this->out();
			}
		}
	}

	/**
	 * Create a session for a superuser who will be creating the users
	 *
	 * @param   integer  $superuserid  Id for user who is a super user and will be creating the new users.
	 * 
	 * @return  null
	 *
	 * @since   1.0
	 */
	public function createSession()
	{
	
		// Get a valid userid
		$db = JFactory::getDbo();
		$query = $db->getQuery(true)
			->select('id')
			->from('#__users');
		$db->setQuery($query);

		$userid = $db->loadResult();
		
		$superuser = new JUser($userid);

		// Make the user a root user
		if (!$superuser->get('isRoot'))
		{
			$superuser->set('isRoot', 1);
		}
	

		// Replace with the admin user
		$session = JFactory::getSession();
		$session->set('user', $superuser);

		// Check to see if the session already exists.
		$this->app->checkSession();

		// Update the user related fields for the Joomla sessions table.
		$query = $this->db->getQuery(true)
			->update($this->db->quoteName('#__session'))
			->set($this->db->quoteName('guest') . ' = ' . 0)
			->set($this->db->quoteName('username') . ' = ' . $this->db->quote($superuser->username))
			->set($this->db->quoteName('userid') . ' = ' . (int) $superuser->id)
			->where($this->db->quoteName('session_id') . ' = ' . $this->db->quote($session->getId()));
		$this->db->setQuery($query)->execute();		
	}
}

JApplicationCli::getInstance('AddUsersFromTable')->execute();
