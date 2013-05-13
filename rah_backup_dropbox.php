<?php

/**
 * Dropbox module for rah_backup.
 *
 * Backups your site backups to your Dropbox account.
 *
 * @author  Jukka Svahn
 * @license GNU GPLv2
 * @link    https://github.com/gocom/rah_backup_dropbox
 *
 * Copyright (C) 2013 Jukka Svahn http://rahforum.biz
 * Licensed under GNU General Public License version 2
 * http://www.gnu.org/licenses/gpl-2.0.html
 */

class rah_backup_dropbox
{	
	/**
	 * User's consumer key.
	 *
	 * @var string
	 */

	protected $key;

	/**
	 * User's consumer secret.
	 *
	 * @var string
	 */

	protected $secret;

	/**
	 * Authentication token.
	 *
	 * @var string
	 */

	protected $token;

	/**
	 * Authentication callback endpoint URL.
	 *
	 * @var string
	 */

	protected $callback_uri;

	/**
	 * An instance of session handler.
	 *
	 * @var \Dropbox\OAuth\Storage\Session
	 */

	protected $storage;

	/**
	 * An instance of OAuth.
	 *
	 * @var \Dropbox\OAuth\Consumer\Curl
	 */

	protected $oauth;

	/**
	 * An instance of Dropbox API.
	 *
	 * @var \Dropbox\API
	 */

	protected $dropbox;

	/**
	 * Whether user is connected to Dropbox.
	 *
	 * @var bool
	 */

	protected $connected = false;

	/**
	 * Installer.
	 */

	public function install()
	{
		$position = 250;

		foreach (
			array(
				'key'    => array('rah_backup_dropbox_key', ''),
				'secret' => array('rah_backup_dropbox_key', ''),
				'token'  => array('rah_backup_dropbox_token', ''),
			) as $name => $val
		)
		{
			$n = 'rah_backup_dropbox_'.$name;

			if (get_pref($n, false) === false)
			{
				set_pref($n, $val[1], 'rah_bckp_db', PREF_ADVANCED, $val[0], $position);
			}

			$position++;
		}
	}

	/**
	 * Uninstaller.
	 */

	public function uninstall()
	{
		safe_delete('txp_prefs', "name like 'rah\_backup\_dropbox\_%'");
	}

	/**
	 * Constructor.
	 */

	public function __construct()
	{
		add_privs('plugin_prefs.rah_backup_dropbox', '1');
		add_privs('prefs.rah_bckp_db', '1');
		register_callback(array($this, 'sync'), 'rah_backup.created');
		register_callback(array($this, 'sync'), 'rah_backup.deleted');
		register_callback(array($this, 'authentication'), 'textpattern');
		register_callback(array($this, 'unlink_account'), 'prefs');
		register_callback(array($this, 'prefs'), 'plugin_prefs.rah_backup_dropbox');
		register_callback(array($this, 'install'), 'plugin_lifecycle.rah_backup_dropbox', 'installed');
		register_callback(array($this, 'uninstall'), 'plugin_lifecycle.rah_backup_dropbox', 'deleted');
		register_callback(array($this, 'requirements'), 'rah_backup');

		$this->callback_uri = hu.'?rah_backup_dropbox_oauth=accesstoken';

		foreach (array('key', 'secret', 'token') as $name)
		{
			$this->$name = get_pref('rah_backup_dropbox_'.$name);
		}
	}

	/**
	 * Requirements check.
	 */

	public function requirements()
	{
		if (!$this->token && has_privs('prefs.rah_bckp_db'))
		{
			echo announce(gTxt('rah_backup_dropbox_link_account'));
		}
	}

	/**
	 * Unlinks account.
	 */

	public function unlink_account()
	{
		global $prefs;

		if (!gps('rah_backup_dropbox_unlink') || !has_privs('prefs.rah_bckp_db'))
		{
			return;
		}

		foreach (array('key', 'secret', 'token') as $name)
		{
			$name = 'rah_backup_dropbox_'.$name;
			set_pref($name, '');
			$prefs[$name] = '';
			$this->$name = '';
		}
	}

	/**
	 * Authentication handler.
	 */
	
	public function authentication()
	{
		$auth = (string) gps('rah_backup_dropbox_oauth');
		$method = 'auth_'.$auth;

		if (!$auth || $this->token || !$this->key || !$this->secret || !method_exists($this, $method))
		{
			return;
		}

		$this->$method();
	}

	/**
	 * Redirects user to Dropbox authentication web endpoint.
	 */

	protected function auth_authorize()
	{
		try
		{
			$this->connect();
		}
		catch (Exception $e)
		{
			die(gTxt('rah_backup_dropbox_connection_error'));
		}
	}

	/**
	 * Gets Dropbox access token and writes it to the database.
	 */

	protected function auth_accesstoken()
	{
		try
		{
			$this->connect();
			$token = $this->storage->get('access_token');
		}
		catch (Exception $e)
		{
			$token = false;
		}

		if (!$token)
		{
			die(gTxt('rah_backup_dropbox_token_error'));
		}

		set_pref('rah_backup_dropbox_token', json_encode($token), 'rah_bckp_db', 2, '', 0);
		die(gTxt('rah_backup_dropbox_authenticated'));
	}

	/**
	 * Connects to Dropbox.
	 *
	 * @return bool
	 */

	public function connect()
	{
		if (!$this->key || !$this->secret)
		{
			throw new Exception('Secret and key are needed to connect to a Dropbox account.');
		}

		if ($this->connected)
		{
			return true;
		}

		try
		{
			$this->storage = new \Dropbox\OAuth\Storage\Session();

			if ($this->token)
			{
				$this->storage->set(json_decode($this->token), 'access_token');
			}

			$this->oauth = new \Dropbox\OAuth\Consumer\Curl(
				$this->key,
				$this->secret,
				$this->storage,
				$this->callback_uri
			);

			if ($this->token)
			{
				$this->dropbox = new \Dropbox\API($this->oauth);
			}
		}
		catch (Exception $e)
		{
			throw new Exception('Dropbox SDK said: '.$e->getMessage());
		}

		$this->connected = true;
		return true;
	}

	/**
	 * Syncs backups.
	 *
	 * @param string $event
	 * @param string $step
	 * @param array  $data
	 */

	public function sync($event, $step, $data)
	{
		if (!$this->token || !$this->connect())
		{
			return;
		}

		try
		{
			if ($event === 'rah_backup.deleted')
			{
				foreach ($data['files'] as $name => $path)
				{
					$this->dropbox->delete($name);
				}
			}
			else
			{
				foreach ($data['files'] as $name => $path)
				{
					$this->dropbox->putFile($path, $name);
				}
			}
		}
		catch (Exception $e)
		{
			throw new Exception('Dropbox SDK said: '.$e->getMessage());
		}
	}

	/**
	 * Redirect to the admin-side preferences panel.
	 */

	public function prefs()
	{
		header('Location: ?event=prefs#prefs-rah_backup_dropbox_api_key');
		echo graf(href(gTxt('continue'), 'event=prefs#prefs-rah_backup_dropbox_api_key'));
	}
}

new rah_backup_dropbox();

/**
 * Options controller for Dropbox access token.
 *
 * @return string HTML
 */

function rah_backup_dropbox_token()
{
	global $event;

	if (!get_pref('rah_backup_dropbox_key', '', true) || !get_pref('rah_backup_dropbox_secret', '', true))
	{
		return 
			n.span(gTxt('rah_backup_dropbox_authorize'), array(
				'class' => 'navlink-disabled',
			)).
			n.span(gTxt('rah_backup_dropbox_set_keys', array('{save}' => gTxt('save'))), array(
				'class' => 'information',
			));
	}

	if (get_pref('rah_backup_dropbox_token'))
	{
		return 
			n.href(gTxt('rah_backup_dropbox_unlink'), array(
				'event'                     => $event,
				'rah_backup_dropbox_unlink' => 1,
			), array('class' => 'navlink'));
	}

	return 
		n.href(gTxt('rah_backup_dropbox_authorize'), hu.'?rah_backup_dropbox_oauth=authorize', array(
			'class' => 'navlink',
		)).
		n.href(gTxt('rah_backup_dropbox_reset'), array(
			'event'                     => $event,
			'rah_backup_dropbox_unlink' => 1,
		));
}

/**
 * Options controller for the application key.
 *
 * @param  string $name  Field name
 * @param  string $value Current value
 * @return string HTML
 */

function rah_backup_dropbox_key($name, $value)
{
	if ($value !== '')
	{
		$value = str_pad('', strlen($value), '*');
		$value = text_input($name.'_null', $value, INPUT_REGULAR);
		return str_replace('<input', '<input disabled="disabled"', $value);
	}

	return text_input($name, $value, INPUT_REGULAR);
}