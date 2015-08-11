<?php
/**
 * Part of the Joomla Framework Session Package
 *
 * @copyright  Copyright (C) 2005 - 2015 Open Source Matters, Inc. All rights reserved.
 * @license    GNU General Public License version 2 or later; see LICENSE
 */

namespace Joomla\Session;

use Joomla\Event\DispatcherAwareInterface;
use Joomla\Event\DispatcherAwareTrait;
use Joomla\Event\DispatcherInterface;
use Joomla\Input\Input;
use Joomla\Session\Handler\FilesystemHandler;
use Joomla\Session\Storage\NativeStorage;

/**
 * Class for managing HTTP sessions
 *
 * Provides access to session-state values as well as session-level settings and lifetime management methods.
 * Based on the standard PHP session handling mechanism it provides more advanced features such as expire timeouts.
 *
 * @since  1.0
 */
class Session implements SessionInterface, DispatcherAwareInterface
{
	use DispatcherAwareTrait;

	/**
	 * Internal state.
	 * One of 'inactive'|'active'|'expired'|'destroyed'|'closed'|'error'
	 *
	 * @var    string
	 * @see    getState()
	 * @since  1.0
	 */
	protected $state = 'inactive';

	/**
	 * The Input object.
	 *
	 * @var    Input
	 * @since  1.0
	 */
	private $input;

	/**
	 * Maximum age of unused session in minutes
	 *
	 * @var    integer
	 * @since  1.0
	 */
	protected $expire = 15;

	/**
	 * The session store object.
	 *
	 * @var    StorageInterface
	 * @since  1.0
	 */
	protected $store;

	/**
	 * Security policy.
	 * List of checks that will be done.
	 *
	 * Possible values:
	 * - fix_browser
	 * - fix_address
	 *
	 * @var    array
	 * @since  1.0
	 */
	protected $security = array('fix_browser');

	/**
	 * Constructor
	 *
	 * @param   Input                $input       The input object
	 * @param   StorageInterface     $store       A StorageInterface implementation
	 * @param   DispatcherInterface  $dispatcher  DispatcherInterface for the session to use.
	 * @param   array                $options     Optional parameters
	 *
	 * @since   1.0
	 */
	public function __construct(Input $input, StorageInterface $store = null, DispatcherInterface $dispatcher = null, array $options = array())
	{
		$this->store = $store ?: new NativeStorage(new FilesystemHandler);

		if ($dispatcher)
		{
			$this->setDispatcher($dispatcher);
		}

		$this->input = $input;

		$this->setOptions($options);

		$this->setState('inactive');
	}

	/**
	 * Get expiration time in minutes
	 *
	 * @return  integer  The session expiration time in minutes
	 *
	 * @since   1.0
	 */
	public function getExpire()
	{
		return $this->expire;
	}

	/**
	 * Get current state of session
	 *
	 * @return  string  The session state
	 *
	 * @since   1.0
	 */
	public function getState()
	{
		return $this->state;
	}

	/**
	 * Get a session token, if a token isn't set yet one will be generated.
	 *
	 * Tokens are used to secure forms from spamming attacks. Once a token has been generated the system will check the post request to see if
	 * it is present, if not it will invalidate the session.
	 *
	 * @param   boolean  $forceNew  If true, force a new token to be created
	 *
	 * @return  string  The session token
	 *
	 * @since   1.0
	 */
	public function getToken($forceNew = false)
	{
		$token = $this->get('session.token');

		// Create a token
		if ($token === null || $forceNew)
		{
			$token = $this->createToken(12);
			$this->set('session.token', $token);
		}

		return $token;
	}

	/**
	 * Method to determine if a token exists in the session. If not the session will be set to expired
	 *
	 * @param   string   $tCheck       Hashed token to be verified
	 * @param   boolean  $forceExpire  If true, expires the session
	 *
	 * @return  boolean
	 *
	 * @since   1.0
	 */
	public function hasToken($tCheck, $forceExpire = true)
	{
		// Check if a token exists in the session
		$tStored = $this->get('session.token');

		// Check token
		if (($tStored !== $tCheck))
		{
			if ($forceExpire)
			{
				$this->setState('expired');
			}

			return false;
		}

		return true;
	}

	/**
	 * Retrieve an external iterator.
	 *
	 * @return  \ArrayIterator  Return an ArrayIterator of $_SESSION.
	 *
	 * @since   1.0
	 */
	public function getIterator()
	{
		return new \ArrayIterator($this->all());
	}

	/**
	 * Get session name
	 *
	 * @return  string  The session name
	 *
	 * @since   1.0
	 */
	public function getName()
	{
		return $this->store->getName();
	}

	/**
	 * Set the session name
	 *
	 * @param   string  $name  The session name
	 *
	 * @return  $this
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	public function setName($name)
	{
		$this->store->setName($name);

		return $this;
	}

	/**
	 * Get session id
	 *
	 * @return  string  The session name
	 *
	 * @since   1.0
	 */
	public function getId()
	{
		return $this->store->getId();
	}

	/**
	 * Set the session ID
	 *
	 * @param   string  $id  The session ID
	 *
	 * @return  $this
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	public function setId($id)
	{
		$this->store->setId($id);

		return $this;
	}

	/**
	 * Get the available session handlers
	 *
	 * @return  array  An array of available session handlers
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	public static function getHandlers()
	{
		$connectors = array();

		// Get an iterator and loop trough the handler classes.
		$iterator = new \DirectoryIterator(__DIR__ . '/Handler');

		foreach ($iterator as $file)
		{
			$fileName = $file->getFilename();

			// Only load for PHP files.
			if (!$file->isFile() || $file->getExtension() != 'php')
			{
				continue;
			}

			// Derive the class name from the type.
			$class = str_ireplace('.php', '', '\\Joomla\\Session\\Handler\\' . ucfirst(trim($fileName)));

			// If the class doesn't exist we have nothing left to do but look at the next type. We did our best.
			if (!class_exists($class))
			{
				continue;
			}

			// Sweet!  Our class exists, so now we just need to know if it passes its test method.
			if ($class::isSupported())
			{
				// Connector names should not have file the handler suffix or the file extension.
				$connectors[] = str_ireplace('Handler.php', '', $fileName);
			}
		}

		return $connectors;
	}

	/**
	 * Check if the session is active
	 *
	 * @return  boolean
	 *
	 * @since   1.0
	 */
	public function isActive()
	{
		if ($this->getState() === 'active')
		{
			return $this->store->isActive();
		}

		return false;
	}

	/**
	 * Check whether this session is currently created
	 *
	 * @return  boolean  True on success.
	 *
	 * @since   1.0
	 */
	public function isNew()
	{
		$counter = $this->get('session.counter');

		return (bool) ($counter === 1);
	}

	/**
	 * Check if the session is started
	 *
	 * @return  boolean
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	public function isStarted()
	{
		return $this->store->isStarted();
	}

	/**
	 * Get data from the session store
	 *
	 * @param   string  $name     Name of a variable
	 * @param   mixed   $default  Default value of a variable if not set
	 *
	 * @return  mixed  Value of a variable
	 *
	 * @since   1.0
	 */
	public function get($name, $default = null)
	{
		return $this->store->get($name, $default);
	}

	/**
	 * Set data into the session store.
	 *
	 * @param   string  $name   Name of a variable.
	 * @param   mixed   $value  Value of a variable.
	 *
	 * @return  mixed  Old value of a variable.
	 *
	 * @since   1.0
	 */
	public function set($name, $value = null)
	{
		return $this->store->set($name, $value);
	}

	/**
	 * Check whether data exists in the session store
	 *
	 * @param   string  $name  Name of variable
	 *
	 * @return  boolean  True if the variable exists
	 *
	 * @since   1.0
	 */
	public function has($name)
	{
		return $this->store->has($name);
	}

	/**
	 * Unset a variable from the session store
	 *
	 * @param   string  $name  Name of variable
	 *
	 * @return  mixed   The value from session or NULL if not set
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	public function remove($name)
	{
		return $this->store->remove($name);
	}

	/**
	 * Clears all variables from the session store
	 *
	 * @return  void
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	public function clear()
	{
		$this->store->clear();
	}

	/**
	 * Retrieves all variables from the session store
	 *
	 * @return  array
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	public function all()
	{
		return $this->store->all();
	}

	/**
	 * Start a session.
	 *
	 * @return  void
	 *
	 * @since   1.0
	 */
	public function start()
	{
		if ($this->isStarted())
		{
			return;
		}

		$this->store->start();

		$this->setState('active');

		// Initialise the session
		$this->setCounter();
		$this->setTimers();

		// Perform security checks
		$this->validate();

		if ($this->dispatcher instanceof DispatcherInterface)
		{
			$event = new SessionEvent('onAfterSessionStart', $this);
			$this->dispatcher->triggerEvent($event);
		}
	}

	/**
	 * Frees all session variables and destroys all data registered to a session
	 *
	 * This method resets the $_SESSION variable and destroys all of the data associated
	 * with the current session in its storage (file or DB). It forces new session to be
	 * started after this method is called.
	 *
	 * @return  boolean  True on success
	 *
	 * @see     session_destroy()
	 * @see     session_unset()
	 * @since   1.0
	 */
	public function destroy()
	{
		// Session was already destroyed
		if ($this->getState() === 'destroyed')
		{
			return true;
		}

		$this->clear();
		$this->fork(true);

		$this->setState('destroyed');

		return true;
	}

	/**
	 * Restart an expired or locked session.
	 *
	 * @return  boolean  True on success
	 *
	 * @see     destroy
	 * @since   1.0
	 */
	public function restart()
	{
		// Backup existing session data
		$data = $this->all();

		$this->destroy();

		if ($this->getState() !== 'destroyed')
		{
			// @TODO :: generated error here
			return false;
		}

		// Restart the session
		$this->store->start();

		$this->setCounter();
		$this->setTimers();
		$this->validate(true);

		// Restore the data
		foreach ($data as $key => $value)
		{
			$this->set($key, $value);
		}

		if ($this->dispatcher instanceof DispatcherInterface)
		{
			$event = new SessionEvent('onAfterSessionRestart', $this);
			$this->dispatcher->triggerEvent($event);
		}

		return true;
	}

	/**
	 * Create a new session and copy variables from the old one
	 *
	 * @param   boolean  $destroy  Whether to delete the old session or leave it to garbage collection.
	 *
	 * @return  boolean  True on success
	 *
	 * @since   1.0
	 */
	public function fork($destroy = false)
	{
		if ($this->getState() !== 'active')
		{
			// @TODO :: generated error here
			return false;
		}

		$this->store->regenerate($destroy);

		if ($destroy)
		{
			$this->setCounter();
			$this->setTimers();
		}

		return true;
	}

	/**
	 * Writes session data and ends session
	 *
	 * Session data is usually stored after your script terminated without the need
	 * to call {@link Session::close()}, but as session data is locked to prevent concurrent
	 * writes only one script may operate on a session at any time. When using
	 * framesets together with sessions you will experience the frames loading one
	 * by one due to this locking. You can reduce the time needed to load all the
	 * frames by ending the session as soon as all changes to session variables are
	 * done.
	 *
	 * @return  void
	 *
	 * @see     session_write_close()
	 * @since   1.0
	 */
	public function close()
	{
		$this->store->close();
		$this->setState('closed');
	}

	/**
	 * Create a token-string
	 *
	 * @param   integer  $length  Length of string
	 *
	 * @return  string  Generated token
	 *
	 * @since   1.0
	 */
	protected function _createToken($length = 32)
	{
		static $chars = '0123456789abcdef';
		$max = strlen($chars) - 1;
		$token = '';
		$name = session_name();

		for ($i = 0; $i < $length; ++$i)
		{
			$token .= $chars[(rand(0, $max))];
		}

		return md5($token . $name);
	}

	/**
	 * Set counter of session usage
	 *
	 * @return  boolean  True on success
	 *
	 * @since   1.0
	 */
	protected function setCounter()
	{
		$counter = $this->get('session.counter', 0);
		++$counter;

		$this->set('session.counter', $counter);

		return true;
	}

	/**
	 * Set the session expiration
	 *
	 * @param   integer  $expire  Maximum age of unused session in minutes
	 *
	 * @return  $this
	 *
	 * @since   1.3.0
	 */
	protected function setExpire($expire)
	{
		$this->expire = $expire;

		return $this;
	}

	/**
	 * Set the session state
	 *
	 * @param   string  $state  Internal state
	 *
	 * @return  $this
	 *
	 * @since   1.3.0
	 */
	protected function setState($state)
	{
		$this->state = $state;

		return $this;
	}

	/**
	 * Set the session timers
	 *
	 * @return  boolean  True on success
	 *
	 * @since   1.0
	 */
	protected function setTimers()
	{
		if (!$this->has('session.timer.start'))
		{
			$start = time();

			$this->set('session.timer.start', $start);
			$this->set('session.timer.last', $start);
			$this->set('session.timer.now', $start);
		}

		$this->set('session.timer.last', $this->get('session.timer.now'));
		$this->set('session.timer.now', time());

		return true;
	}

	/**
	 * Set additional session options
	 *
	 * @param   array  $options  List of parameter
	 *
	 * @return  boolean  True on success
	 *
	 * @since   1.0
	 */
	protected function setOptions(array $options)
	{
		// Set name
		if (isset($options['name']))
		{
			$this->setName($options['name']);
		}

		// Set id
		if (isset($options['id']))
		{
			$this->setId($options['id']);
		}

		// Set expire time
		if (isset($options['expire']))
		{
			$this->setExpire($options['expire']);
		}

		// Get security options
		if (isset($options['security']))
		{
			$this->security = explode(',', $options['security']);
		}

		// Sync the session maxlifetime
		ini_set('session.gc_maxlifetime', $this->getExpire());

		return true;
	}

	/**
	 * Do some checks for security reason
	 *
	 * - timeout check (expire)
	 * - ip-fixiation
	 * - browser-fixiation
	 *
	 * If one check failed, session data has to be cleaned.
	 *
	 * @param   boolean  $restart  Reactivate session
	 *
	 * @return  boolean  True on success
	 *
	 * @see     http://shiflett.org/articles/the-truth-about-sessions
	 * @since   1.0
	 */
	protected function validate($restart = false)
	{
		// Allow to restart a session
		if ($restart)
		{
			$this->setState('active');

			$this->set('session.client.address', null);
			$this->set('session.client.forwarded', null);
			$this->set('session.client.browser', null);
		}

		// Check if session has expired
		if ($this->expire)
		{
			$curTime = $this->get('session.timer.now', 0);
			$maxTime = $this->get('session.timer.last', 0) + $this->expire;

			// Empty session variables
			if ($maxTime < $curTime)
			{
				$this->setState('expired');

				return false;
			}
		}

		// Record proxy forwarded for in the session in case we need it later
		$proxyForwarded = $this->input->server->getString('HTTP_X_FORWARDED_FOR', null);

		if ($proxyForwarded)
		{
			$this->set('session.client.forwarded', $proxyForwarded);
		}

		// Check for client address
		$remoteServerAddress = $this->input->server->getString('REMOTE_ADDR', null);

		if (in_array('fix_address', $this->security) && $remoteServerAddress)
		{
			$ip = $this->get('session.client.address');

			if ($ip === null)
			{
				$this->set('session.client.address', $remoteServerAddress);
			}
			elseif ($remoteServerAddress !== $ip)
			{
				$this->setState('error');

				return false;
			}
		}

		// Check for clients browser
		$userAgent = $this->input->server->getString('HTTP_USER_AGENT', null);

		if (in_array('fix_browser', $this->security) && $userAgent)
		{
			$browser = $this->get('session.client.browser');

			if ($browser === null)
			{
				$this->set('session.client.browser', $userAgent);
			}
			elseif ($userAgent !== $browser)
			{
				$this->setState('error');

				return false;
			}
		}

		return true;
	}
}
