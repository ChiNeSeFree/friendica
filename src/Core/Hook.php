<?php
/**
 * @file src/Core/Hook.php
 */
namespace Friendica\Core;

use Friendica\App;
use Friendica\BaseObject;
use Friendica\Database\DBA;

/**
 * Some functions to handle hooks
 */
class Hook extends BaseObject
{
	/**
	 * Array of registered hooks
	 *
	 * Format:
	 * [
	 *		["<hook name>"] => [
	 *			0 => "<hook file>",
	 *			1 => "<hook function name>"
	 *		],
	 *		...
	 * ]
	 *
	 * @var array
	 */
	private static $hooks = [];

	/**
	 * Load hooks
	 */
	public static function loadHooks()
	{
		self::$hooks = [];
		$stmt = DBA::select('hook', ['hook', 'file', 'function'], [], ['order' => ['priority' => 'desc', 'file']]);

		while ($hook = DBA::fetch($stmt)) {
			self::add($hook['hook'], $hook['file'], $hook['function']);
		}
		DBA::close($stmt);
	}

	/**
	 * @brief Adds a new hook to the hooks array.
	 *
	 * This function is meant to be called by modules on each page load as it works after loadHooks has been called.
	 *
	 * @param type $hook
	 * @param type $file
	 * @param type $function
	 */
	public static function add($hook, $file, $function)
	{
		if (!array_key_exists($hook, self::$hooks)) {
			self::$hooks[$hook] = [];
		}
		self::$hooks[$hook][] = [$file, $function];
	}

	/**
	 * @brief Registers a hook.
	 *
	 * This function is meant to be called once when an addon is enabled for example as it doesn't add to the current hooks.
	 *
	 * @param string $hook     the name of the hook
	 * @param string $file     the name of the file that hooks into
	 * @param string $function the name of the function that the hook will call
	 * @param int    $priority A priority (defaults to 0)
	 * @return mixed|bool
	 */
	public static function register($hook, $file, $function, $priority = 0)
	{
		$file = str_replace(self::getApp()->getBasePath() . DIRECTORY_SEPARATOR, '', $file);

		$condition = ['hook' => $hook, 'file' => $file, 'function' => $function];
		if (DBA::exists('hook', $condition)) {
			return true;
		}

		$result = DBA::insert('hook', ['hook' => $hook, 'file' => $file, 'function' => $function, 'priority' => $priority]);

		return $result;
	}

	/**
	 * Unregisters a hook.
	 *
	 * @param string $hook     the name of the hook
	 * @param string $file     the name of the file that hooks into
	 * @param string $function the name of the function that the hook called
	 * @return boolean
	 */
	public static function unregister($hook, $file, $function)
	{
		$relative_file = str_replace(self::getApp()->getBasePath() . DIRECTORY_SEPARATOR, '', $file);

		// This here is only needed for fixing a problem that existed on the develop branch
		$condition = ['hook' => $hook, 'file' => $file, 'function' => $function];
		DBA::delete('hook', $condition);

		$condition = ['hook' => $hook, 'file' => $relative_file, 'function' => $function];
		$result = DBA::delete('hook', $condition);
		return $result;
	}

	/**
	 * Returns the list of callbacks for a single hook
	 *
	 * @param  string $name Name of the hook
	 * @return array
	 */
	public static function getByName($name)
	{
		$return = [];

		if (isset(self::$hooks[$name])) {
			$return = self::$hooks[$name];
		}

		return $return;
	}

	/**
	 * @brief Forks a hook.
	 *
	 * Use this function when you want to fork a hook via the worker.
	 *
	 * @param integer $priority of the hook
	 * @param string  $name     of the hook to call
	 * @param mixed   $data     to transmit to the callback handler
	 */
	public static function fork($priority, $name, $data = null)
	{
		if (array_key_exists($name, self::$hooks)) {
			foreach (self::$hooks[$name] as $hook) {
				// Call a hook to check if this hook call needs to be forked
				if (array_key_exists('hook_fork', self::$hooks)) {
					$hookdata = ['name' => $name, 'data' => $data, 'execute' => true];

					foreach (self::$hooks['hook_fork'] as $fork_hook) {
						if ($hook[0] != $fork_hook[0]) {
							continue;
						}
						self::callSingle(self::getApp(), 'hook_fork', $fork_hook, $hookdata);
					}

					if (!$hookdata['execute']) {
						continue;
					}
				}

				Worker::add($priority, 'ForkHook', $name, $hook, $data);
			}
		}
	}

	/**
	 * @brief Calls a hook.
	 *
	 * Use this function when you want to be able to allow a hook to manipulate
	 * the provided data.
	 *
	 * @param string       $name  of the hook to call
	 * @param string|array &$data to transmit to the callback handler
	 */
	public static function callAll($name, &$data = null)
	{
		if (array_key_exists($name, self::$hooks)) {
			foreach (self::$hooks[$name] as $hook) {
				self::callSingle(self::getApp(), $name, $hook, $data);
			}
		}
	}

	/**
	 * @brief Calls a single hook.
	 *
	 * @param App $a
	 * @param string         $name of the hook to call
	 * @param array          $hook Hook data
	 * @param string|array   &$data to transmit to the callback handler
	 */
	public static function callSingle(App $a, $name, $hook, &$data = null)
	{
		// Don't run a theme's hook if the user isn't using the theme
		if (strpos($hook[0], 'view/theme/') !== false && strpos($hook[0], 'view/theme/' . $a->getCurrentTheme()) === false) {
			return;
		}

		@include_once($hook[0]);
		if (function_exists($hook[1])) {
			$func = $hook[1];
			$func($a, $data);
		} else {
			// remove orphan hooks
			$condition = ['hook' => $name, 'file' => $hook[0], 'function' => $hook[1]];
			DBA::delete('hook', $condition, ['cascade' => false]);
		}
	}

	/**
	 * Checks if an app_menu hook exist for the provided addon name.
	 * Return true if the addon is an app
	 *
	 * @param string $name Name of the addon
	 * @return boolean
	 */
	public static function isAddonApp($name)
	{
		if (array_key_exists('app_menu', self::$hooks)) {
			foreach (self::$hooks['app_menu'] as $hook) {
				if ($hook[0] == 'addon/' . $name . '/' . $name . '.php') {
					return true;
				}
			}
		}

		return false;
	}
}
