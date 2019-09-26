<?php declare(strict_types=1);
/*
 * This file is part of the Shieldon package.
 *
 * (c) Terry L. <contact@terryl.in>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Shieldon;

use Shieldon\Firewall;
use Shieldon\Driver\FileDriver;
use Shieldon\Driver\MysqlDriver;
use Shieldon\Driver\RedisDriver;
use Shieldon\Driver\SqliteDriver;
use Shieldon\Log\LogParser;
use Shieldon\Shieldon;
use Shieldon\FirewallTrait;

use ReflectionObject;

/**
 * Firewall's Control Panel
 * 
 * Display a Control Panel UI for developers or administrators.
 *
 * @since 3.0.0
 */
class FirewallPanel
{
	use FirewallTrait;

	/**
	 * LogPaeser instance.
	 *
	 * @var object
	 */
	protected $parser;

	/**
	 * Messages.
	 *
	 * @var array
	 */
	protected $messages = [];

	/**
	 * self: Shieldon | managed: Firewall
	 *
	 * @var string
	 */
	protected $mode = 'self';

	/**
	 * Check Page availability.
	 *
	 * @var array
	 */
	protected $pageAvailability = [
		'logs' => false,
	];

	/**
	 * Constructor.
	 *
	 * @param object $instance Shieldon | Firewall
	 */
	public function __construct(object $instance) 
	{
		if ($instance instanceof Shieldon) {
			$this->mode = 'self';
			$this->shieldon = $instance;

		} elseif ($instance instanceof Firewall) {
			$this->mode          = 'managed';
			$this->shieldon      = $instance->getShieldon();
			$this->configuration = $instance->getConfiguration();
			$this->directory     = $instance->getDirectory();
			$this->filename      = $instance->getFilename();
		}

		if (! empty($this->shieldon->logger)) {

			// We need to know where the logs stored in.
			$logDirectory = $this->shieldon->logger->getDirectory();

			// Load logParser for parsing log files.
			$this->parser = new LogParser($logDirectory);

			$this->pageAvailability['logs'] = true;

		} else {

			array_push($this->messages, [
				'type' => 'error',
				'text' => 'ActionLogger is not implemented with the Shieldon instance.',
			]);
		}

		if ($this->mode !== 'self') {
			$this->httpAuth();
		}
	}

	 // @codeCoverageIgnoreStart

	/**
	 * Display pages.
	 *
	 * @param string $slug
	 *
	 * @return void
	 */
	public function entry()
	{
		$slug = $_GET['so_page'] ?? '';

		switch($slug) {

			case 'overview':
				$this->overview();
				break;

			case 'settings':
				$this->setting();
				break;

			case 'ip_manager':
				$this->ipManager();
				break;

			case 'exclusion':
				$this->exclusion();
				break;

			case 'authentication':
				$this->authentication();
				break;

			case 'xss_protection':
				$this->xssProtection();
				break;

			case 'session_table':
				$this->sessionTable();
				break;

			case 'ip_log_table':
				$this->ipLogTable();
				break;

			case 'ip_rule_table':
				$this->ruleTable();
				break;

			case 'dashboard':
				$this->dashboard();
				break;

			default:
				header('Location: ' . $this->url('settings'));
				break;
		}
	}

	/**
	 * Setting page.
	 *
	 * @return void
	 */
	public function setting(): void
	{
		$data[] = [];

		if (isset($_POST['tab'])) {
			unset($_POST['tab']);
			$this->saveConfig();
		}

		$this->renderPage('panel/setting', $data);
	}

	/**
	 * Shieldon operating information.
	 *
	 * @return void
	 */
	public function overview(): void
	{
		/*
		|--------------------------------------------------------------------------
		| Logger
		|--------------------------------------------------------------------------
		|
		| All logs were recorded by ActionLogger.
		| Get the summary information from those logs.
		|
		*/

		$data['action_logger'] = false;

		if (! empty($this->shieldon->logger)) {
			$loggerInfo = $this->shieldon->logger->getCurrentLoggerInfo();
			$data['action_logger'] = true;
		}

		$data['logger_started_working_date'] = 'No record';
		$data['logger_work_days'] = '0 day';
		$data['logger_total_size'] = '0 MB';

		if (! empty($loggerInfo)) {

			$i = 0;
			ksort($loggerInfo);

			foreach ($loggerInfo as $date => $size) {
				if (0 === $i) {
					$data['logger_started_working_date'] = date('Y-m-d', strtotime((string) $date));
				}
				$i += (int) $size;
			}

			$data['logger_work_days'] = count($loggerInfo);
			$data['logger_total_size'] = round($i / (1024 * 1024), 5) . ' MB';
		}

		/*
		|--------------------------------------------------------------------------
		| Data circle
		|--------------------------------------------------------------------------
		|
		| A data circle includes the primary data tables of Shieldon.
		| They are ip_log_table, ip_rule_table and session_table.
		|
		*/

		// Data circle.
		$data['rule_list'] = $this->shieldon->driver->getAll('rule');
		$data['ip_log_list'] = $this->shieldon->driver->getAll('log');
		$data['session_list'] = $this->shieldon->driver->getAll('session');

		/*
		|--------------------------------------------------------------------------
		| Shieldon status
		|--------------------------------------------------------------------------
		|
		| 1. Components.
		| 2. Filters.
		| 3. Configuration.
		|
		*/

		$data['components'] = [
			'Ip'         => (! empty($this->shieldon->component['Ip']))         ? true : false,
			'TrustedBot' => (! empty($this->shieldon->component['TrustedBot'])) ? true : false,
			'Header'     => (! empty($this->shieldon->component['Header']))     ? true : false,
			'Rdns'       => (! empty($this->shieldon->component['Rdns']))       ? true : false,
			'UserAgent'  => (! empty($this->shieldon->component['UserAgent']))  ? true : false,
		];

        $reflection = new ReflectionObject($this->shieldon);
        $t = $reflection->getProperty('enableCookieCheck');
        $t->setAccessible(true);
		$enableCookieCheck = $t->getValue($this->shieldon);

		$reflection = new ReflectionObject($this->shieldon);
        $t = $reflection->getProperty('enableSessionCheck');
        $t->setAccessible(true);
		$enableSessionCheck = $t->getValue($this->shieldon);

		$reflection = new ReflectionObject($this->shieldon);
        $t = $reflection->getProperty('enableFrequencyCheck');
        $t->setAccessible(true);
		$enableFrequencyCheck = $t->getValue($this->shieldon);

		$reflection = new ReflectionObject($this->shieldon);
        $t = $reflection->getProperty('enableRefererCheck');
        $t->setAccessible(true);
		$enableRefererCheck = $t->getValue($this->shieldon);

		$data['filters'] = [
			'cookie'    => $enableCookieCheck,
			'session'   => $enableSessionCheck,
			'frequency' => $enableFrequencyCheck,
			'referer'   => $enableRefererCheck,
		];

		$reflection = new ReflectionObject($this->shieldon);
        $t = $reflection->getProperty('properties');
        $t->setAccessible(true);
		$properties = $t->getValue($this->shieldon);
		
		$data['configuration'] = $properties;

		$data['driver'] = [
			'mysql'  => ($this->shieldon->driver instanceof MysqlDriver),
			'redis'  => ($this->shieldon->driver instanceof RedisDriver),
			'file'   => ($this->shieldon->driver instanceof FileDriver),
			'sqlite' => ($this->shieldon->driver instanceof SqliteDriver),
		];

		$reflection = new ReflectionObject($this->shieldon);
        $t = $reflection->getProperty('captcha');
        $t->setAccessible(true);
		$captcha = $t->getValue($this->shieldon);

		$data['captcha'] = [
			'recaptcha'    => (isset($captcha['Recaptcha']) ? true : false),
			'imagecaptcha' => (isset($captcha['ImageCaptcha']) ? true : false),
		];

		$this->renderPage('panel/overview', $data);
	}

	/**
	 * IP manager.
	 *
	 * @return void
	 */
	public function ipManager()
	{
		if (isset($_POST['ip']) && filter_var(explode('/', $_POST['ip'])[0], FILTER_VALIDATE_IP)) {

			$url = $_POST['url'];
			$ip = $_POST['ip'];
			$rule = $_POST['action'];
			$order = (int) $_POST['order'];

			if ($order > 0) {
				$order--;
			}

			$ipList = $this->getConfig('ip_manager');

			if ('allow' === $rule || 'deny' === $rule) {

				$newIpList = [];

				if (! empty($ipList)) {
					foreach ($ipList as $i => $ipInfo) {
						$key = $i + 1;
						if ($order === $i) {
							$newIpList[$key] = $ipInfo;

							$newIpList[$i]['url'] = $url;
							$newIpList[$i]['ip'] = $ip;
							$newIpList[$i]['rule'] = $rule;
						} else {
							$newIpList[$key] = $ipInfo;
						}
					}
				} else {
					$newIpList[0]['url'] = $url;
					$newIpList[0]['ip'] = $ip;
					$newIpList[0]['rule'] = $rule;
				}

				$newIpList = array_values($newIpList);

				$this->setConfig('ip_manager', $newIpList);

			} elseif ('remove' === $rule) {
				unset($ipList[$order]);
				$ipList = array_values($ipList);
				$this->setConfig('ip_manager', $ipList);
			}

			if (isset($_POST['url']))    unset($_POST['url']);
			if (isset($_POST['ip']))     unset($_POST['ip']);
			if (isset($_POST['action'])) unset($_POST['action']);
			if (isset($_POST['order']))  unset($_POST['order']);

			$this->saveConfig();
		}

		$data['ip_list'] = $this->getConfig('ip_manager');

		$this->renderPage('panel/ip_manager', $data);
	}

	/**
	 * Exclude the URLs that they don't need protection.
	 *
	 * @return void
	 */
	public function exclusion(): void
	{
		if (isset($_POST['url'])) {

			$url = $_POST['url'] ?? '';
			$action = $_POST['action'] ?? '';
			$order = (int) $_POST['order'];

			$excludedUrls = $this->getConfig('excluded_urls');

			if ('add' === $action) {
				array_push($excludedUrls, [
					'url' => $url
				]);

			} elseif ('remove' === $action) {
				unset($excludedUrls[$order]);

				$excludedUrls = array_values($excludedUrls);
			}

			$this->setConfig('excluded_urls', $excludedUrls);

			if (isset($_POST['url']))    unset($_POST['url']);
			if (isset($_POST['action'])) unset($_POST['action']);
			if (isset($_POST['order']))  unset($_POST['order']);

			$this->saveConfig();
		}

		$data['exclusion_list'] = $this->getConfig('excluded_urls');

		$this->renderPage('panel/exclusion', $data);
	}

	/**
	 * WWW-Authenticate.
	 *
	 * @return void
	 */
	public function authentication(): void
	{
		if (isset($_POST['url']) && isset($_POST['user']) && isset($_POST['pass'])) {

			$url = $_POST['url'] ?? '';
			$user = $_POST['user'] ?? '';
			$pass = $_POST['pass'] ?? '';
			$action = $_POST['action'] ?? '';
			$order = (int) $_POST['order'];

			$authenticatedList = $this->getConfig('www_authenticate');

			if ('add' === $action) {
				array_push($authenticatedList, [
					'url' => $url,
					'user' => $user,
					'pass' => password_hash($pass, PASSWORD_BCRYPT),
				]);

			} elseif ('remove' === $action) {
				unset($authenticatedList[$order]);
				$authenticatedList = array_values($authenticatedList);
			}

			$this->setConfig('www_authenticate', $authenticatedList);

			if (isset($_POST['url']))    unset($_POST['url']);
			if (isset($_POST['user']))   unset($_POST['user']);
			if (isset($_POST['pass']))   unset($_POST['pass']);
			if (isset($_POST['action'])) unset($_POST['action']);
			if (isset($_POST['order']))  unset($_POST['order']);

			$this->saveConfig();
		}

		$data['authentication_list'] = $this->getConfig('www_authenticate');

		$this->renderPage('panel/authentication', $data);
	}


	/**
	 * XSS Protection.
	 *
	 * @return void
	 */
	public function xssProtection(): void
	{
		if (isset($_POST['xss'])) {
			unset($_POST['xss']);

			$type = $_POST['type'] ?? '';
			$variable = $_POST['variable'] ?? '';
			$action = $_POST['action'] ?? '';
			$order = (int) $_POST['order'];

			// Check variable name. Should be mixed with a-zA-Z and underscore.
			if (! ctype_alnum(str_replace('_', '', $variable))) {

				// Ignore the `add` process.
				$action = 'undefined';
			}

			$xssProtectedList = $this->getConfig('xss_protected_list');

			if (empty($xssProtectedList)) {
				$xssProtectedList = [];
			}

			if ('add' === $action) {

				switch ($type) {
					case 'post':
					case 'get':
					case 'cookie':
						array_push($xssProtectedList, ['type' => $type, 'variable' => $variable]);
						break;

					default:
					// endswitch.
				}

			} elseif ('remove' === $xssProtectedList) {
				unset($xssProtectedList[$order]);
				$xssProtectedList = array_values($xssProtectedList);
			}

			$this->setConfig('xss_protected_list', $xssProtectedList);

			if (isset($_POST['type']))     unset($_POST['type']);
			if (isset($_POST['variable'])) unset($_POST['variable']);
			if (isset($_POST['action']))   unset($_POST['action']);
			if (isset($_POST['order']))    unset($_POST['order']);

			$this->saveConfig();
		}

		$data['xss_protected_list'] = $this->getConfig('xss_protected_list');

		$this->renderPage('panel/xss_protection', $data);
	}

	/**
	 * Dsiplay dashboard.
	 *
	 * @return void
	 */
	public function dashboard(): void
	{
		$tab = $_GET['tab'] ?? 'today';

		switch ($tab) {
			case 'yesterday':
			case 'this_month':
			case 'last_month':
			case 'past_seven_days':
			case 'today':
				$type = $tab;
				break;

			default:
				$type = 'today';
		}

		$data['ip_details'] = [];
		$data['period_data'] = [];

		$data['past_seven_hour'] = [];

		if (! empty($this->parser)) {
			$this->parser->prepare($type);

			$data['ip_details'] = $this->parser->getIpData();
			$data['period_data'] = $this->parser->getParsedPeriodData();

			if ('today' === $type ) {
				$this->parser->prepare('past_seven_hours');
				$data['past_seven_hour'] = $this->parser->getParsedPeriodData();
			}
		}

		$data['page_availability'] = $this->pageAvailability['logs'];

		$data['page_url'] = $this->url('dashboard');

		$this->renderPage('panel/log_' . $type, $data);
	}

	/**
	 * Rule table for current cycle.
	 *
	 * @param string
	 *
	 * @return void
	 */
	public function ruleTable(): void
	{
		if (isset($_POST['ip'])) {

			$ip = $_POST['ip'];
			$action = $_POST['action'];

			$actionCode['temporarily_ban'] = $this->shieldon::ACTION_TEMPORARILY_DENY;
			$actionCode['permanently_ban'] = $this->shieldon::ACTION_DENY;
			$actionCode['allow'] = $this->shieldon::ACTION_ALLOW;

			switch ($action) {
				case 'temporarily_ban':
				case 'permanently_ban':
				case 'allow':
					$logData['log_ip'] = $ip;
					$logData['ip_resolve'] = gethostbyaddr($ip);
					$logData['time'] = time();
					$logData['type'] = $actionCode[$action];
					$logData['reason'] = $this->shieldon::REASON_MANUAL_BAN;

					$this->shieldon->driver->save($ip, $logData, 'rule');
					break;

				case 'remove':
					$this->shieldon->driver->delete($ip, 'rule');
					break;
			}
		}

		$reasons = [
			$this->shieldon::REASON_MANUAL_BAN           => 'Added manually by administrator',
			$this->shieldon::REASON_IS_SEARCH_ENGINE     => 'Search engine bot',
			$this->shieldon::REASON_IS_GOOGLE            => 'Google bot',
			$this->shieldon::REASON_IS_BING              => 'Bing bot',
			$this->shieldon::REASON_IS_YAHOO             => 'Yahoo bot',
			$this->shieldon::REASON_TOO_MANY_SESSIONS    => 'Too many sessions',
			$this->shieldon::REASON_TOO_MANY_ACCESSES    => 'Too many accesses',
			$this->shieldon::REASON_EMPTY_JS_COOKIE      => 'Cannot create JS cookies',
			$this->shieldon::REASON_EMPTY_REFERER        => 'Empty referrer',
			$this->shieldon::REASON_REACHED_LIMIT_DAY    => 'Daily limit reached',
			$this->shieldon::REASON_REACHED_LIMIT_HOUR   => 'Hourly limit reached',
			$this->shieldon::REASON_REACHED_LIMIT_MINUTE => 'Minutely limit reached',
			$this->shieldon::REASON_REACHED_LIMIT_SECOND => 'Secondly limit reached',
		];

		$types = [
			$this->shieldon::ACTION_DENY             => 'DENY',
			$this->shieldon::ACTION_ALLOW            => 'ALLOW',
			$this->shieldon::ACTION_TEMPORARILY_DENY => 'CAPTCHA',
		];

		$data['rule_list'] = $this->shieldon->driver->getAll('rule');

		$data['reason_mapping'] = $reasons;
		$data['type_mapping'] = $types;

		$this->renderPage('panel/table_rules', $data);
	}

	/**
	 * IP log table for current cycle.
	 *
	 * @param string
	 *
	 * @return void
	 */
	public function ipLogTable(): void
	{
		$data['ip_log_list'] = $this->shieldon->driver->getAll('log');

		$this->renderPage('panel/table_ip_logs', $data);
	}

	/**
	 * Session table for current cycle.
	 *
	 * @param string
	 *
	 * @return void
	 */
	public function sessionTable(): void
	{
		$data['session_list'] = $this->shieldon->driver->getAll('session');

		$data['is_session_limit'] = false;
		$data['session_limit_count'] = 0;
		$data['session_limit_period'] = 0;
		$data['online_count'] = 0;
		$data['expires'] = 0;

		$reflection = new ReflectionObject($this->shieldon);
        $t = $reflection->getProperty('isLimitSession');
        $t->setAccessible(true);
		$isLimitSession = $t->getValue($this->shieldon);

		$data['is_session_limit'] = (empty($isLimitSession) ? false : true);
		$data['session_limit_count'] = ($isLimitSession[0] ?? 0);
		$data['session_limit_period'] = round(($isLimitSession[1] ?? 0) / 60, 0);
		$data['online_count'] = count($data['session_list']);
		$data['expires'] = (int) $data['session_limit_period'] * 60;

		$this->renderPage('panel/table_sessions', $data);
	}

	/**
	 * Save the configuration settings to the JSON file.
	 *
	 * @return void
	 */
	protected function saveConfig()
	{
		$configFilePath = $this->directory . '/' . $this->filename;

		if (empty($_POST) || ! is_array($_POST)) {
			return;
		}

		foreach ($_POST as $postKey => $postData) {
			if (is_string($postData)) {
				if ($postData === 'on') {
					$this->setConfig(str_replace('__', '.', $postKey), true);
				} elseif ($postData === 'off') {
					$this->setConfig(str_replace('__', '.', $postKey), false);
				} else {
					if ($postKey === 'ip_variable_source') {
						$this->setConfig('ip_variable_source.REMOTE_ADDR', false);
						$this->setConfig('ip_variable_source.HTTP_CF_CONNECTING_IP', false);
						$this->setConfig('ip_variable_source.HTTP_X_FORWARDED_FOR', false);
						$this->setConfig('ip_variable_source.HTTP_X_FORWARDED_HOST', false);
						$this->setConfig('ip_variable_source.' . $postData, true);
					} else {
						if (is_numeric($postData)) {
							$this->setConfig(str_replace('__', '.', $postKey), (int) $postData);
						} else  {
							$this->setConfig(str_replace('__', '.', $postKey), $postData);
						}
					}
				}
			}
		}

		//  Start checking the availibility of the data driver settings.
		$isDataDriverFailed = false;

		switch ($this->configuration['driver_type']) {

			case 'mysql':
	
				if (class_exists('PDO')) {
					$db = [
						'host'    => $this->getConfig['drivers.mysql.host'],
						'dbname'  => $this->getConfig['drivers.mysql.dbname'],
						'user'    => $this->getConfig['drivers.mysql.user'],
						'pass'    => $this->getConfig['drivers.mysql.pass'],
						'charset' => $this->getConfig['drivers.mysql.charset'],
					];

					try {
						$pdo = new \PDO(
							'mysql:host=' . $db['host'] . ';dbname=' . $db['dbname'] . ';charset=' . $db['charset'],
							(string) $db['user'],
							(string) $db['pass']
						);
					} catch(\PDOException $e) {
						$isDataDriverFailed = true;
					}
				} else {
					$isDataDriverFailed = true;
				}

				break;

			case 'sqlite':

				$sqliteDir = rtrim($this->getConfig('drivers.sqlite.directory_path'), '\\/ ');

				if (empty($sqliteDir)) {
					$sqliteDir = $this->directory . '/data_driver_sqlite';
				}

				$sqliteFilePath = $sqliteDir . '/shieldon.sqlite3';
				$this->setConfig('drivers.sqlite.directory_path', $sqliteDir);
				
				if (! file_exists($sqliteFilePath)) {
					if (! is_dir($sqliteDir)) {
						$originalUmask = umask(0);
						@mkdir($sqliteDir, 0777, true);
						umask($originalUmask);
					}
				}

				if (class_exists('PDO')) {
					try {
						$pdo = new \PDO('sqlite:' . $sqliteFilePath);
					} catch(\PDOException $e) {
						$isDataDriverFailed = true;
					}
				} else {
					$isDataDriverFailed = true;
				}

				if (! is_writable($sqliteFilePath)) {
					$isDataDriverFailed = true;
				}

				break;

			case 'redis':

				if (class_exists('Redis')) {
					try {
						$redis = new \Redis();
						$redis->connect(
							(string) $this->getConfig['drivers.redis.host'], 
							(int)    $this->getConfig['drivers.redis.port']
						);
					} catch(\RedisException $e) {
						$isDataDriverFailed = true;
					}
				} else {
					$isDataDriverFailed = true;
				}

				break;

			case 'file':
			default:

				$fileDir = rtrim($this->getConfig('drivers.file.directory_path'), '\\/ ');

				if (empty($fileDir)) {
					$fileDir = $this->directory . '/data_driver_file';
					$this->setConfig('drivers.file.directory_path', $fileDir);
				}

				$this->setConfig('drivers.file.directory_path', $fileDir);

				if (! is_dir($fileDir)) {
					$originalUmask = umask(0);
					@mkdir($fileDir, 0777, true);
					umask($originalUmask);
				}

				if (! is_writable($fileDir)) {
					$isDataDriverFailed = true;
				}
			// endswitch
		}

		// Check Action Logger settings.
		$enableActionLogger = $this->getConfig('loggers.action.enable');
		$actionLogDir = rtrim($this->getConfig('loggers.action.config.directory_path'), '\\/ ');

		if ($enableActionLogger) {
			if (empty($actionLoggerDir)) {
				$actionLogDir = $this->directory . '/action_logs';
			}

			$this->setConfig('loggers.action.config.directory_path', $actionLogDir);
	
			if (! is_dir($actionLogDir)) {
				$originalUmask = umask(0);
				@mkdir($actionLogDir, 0777, true);
				umask($originalUmask);
			}
	
			if (! is_writable($actionLogDir)) {
				$isDataDriverFailed = true;
			}
		}

		// Only update settings while data driver is correctly connected.
		if (! $isDataDriverFailed) {
			file_put_contents($configFilePath, json_encode($this->configuration));
		}
	}

	/**
	 * Echo the setting string to the template.
	 *
	 * @param string $field
	 * @return string
	 */
	protected function _(string $field)
	{
		if (is_string($this->getConfig($field)) || is_numeric($this->getConfig($field))) {
			echo $this->getConfig($field);
		}
	}

	/**
	 * Use on HTML checkbox and radio elements.
	 *
	 * @param string $value
	 * @param mixed  $valueChecked
	 * @param bool   $isConfig
	 *
	 * @return void
	 */
	protected function checked(string $value, $valueChecked, bool $isConfig = true): void
	{
		if ($isConfig) {
			if ($this->getConfig($value) === $valueChecked) {
				echo 'checked';
			} else {
				echo '';
			}
		} else {
			if ($value === $valueChecked) {
				echo 'checked';
			} else {
				echo '';
			}
		}
	}

	/**
	 * Use on HTML select elemets.
	 *
	 * @param string $value
	 * @param mixed $valueChecked
	 *
	 * @return void
	 */
	protected function selected(string $value, $valueChecked): void
	{
		if ($this->getConfig($value) === $valueChecked) {
			echo 'selected';
		} else {
			echo '';
		}
	}

	/**
	 * Load view file.
	 *
	 * @param string $page
	 * @param array  $data
	 * @param bool   $echo
	 *
	 * @return string|void
	 */
	private function loadView(string $page, array $data = [], $echo = false)
	{
		if (! defined('SHIELDON_VIEW')) {
			define('SHIELDON_VIEW', true);
		}

		$viewFilePath =  __DIR__ . '/../views/' . $page . '.php';
	
		if (! empty($data)) {
			extract($data);
		}

		$result = '';
	
		if (file_exists($viewFilePath)) {
			ob_start();
			require $viewFilePath;
			$result = ob_get_contents();
			ob_end_clean();
		}

		if ($echo) {
			echo $result;
			exit;
		}

		return $result;
	}

	/**
	 * Include a view file.
	 *
	 * @param string $page
	 *
	 * @return void
	 */
	private function _include(string $page)
	{
		if (! defined('SHIELDON_VIEW')) {
			define('SHIELDON_VIEW', true);
		}

		require __DIR__ . '/../views/' . $page . '.php';
	}

	/**
	 * Render the web page.
	 *
	 * @param string $page
	 * @param array $data
	 *
	 * @return void
	 */
	private function renderPage(string $page, array $data)
	{
		$channelName = $this->shieldon->driver->getChannel();

		if (empty($channelName)) {
			$channelName = 'default';
		}

		$content['channel_name'] = $channelName;
		$content['mode_name'] = $this->mode; // WAF, self-managed
		$content['page_url'] = $this->url();
		$content['title'] = $data['title'] ?? '';
		$content['content'] = $this->loadView($page, $data);

		$this->loadView('panel/template', $content, true);
	}

	/**
	 * Providing the Dasboard URLs.
	 *
	 * @param string $page Page tab.
	 * @param string $tab  Tab.
	 *
	 * @return string
	 */
	private function url(string $page = '', string $tab = '')
	{
		$httpProtocal = 'http://';

		if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
			$httpProtocal = 'https://';
		}

		$path = parse_url($httpProtocal . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'], PHP_URL_PATH);

		$url = $httpProtocal . $_SERVER['HTTP_HOST'] . $path;
		$soPage = (! empty($page)) ? '?so_page=' . $page : '';
		$soTab = (! empty($tab)) ? '&tab=' . $tab : '';

		return $url . $soPage . $soTab;
	}

	/**
	 * Prompt an authorization login.
	 *
	 * @return void
	 */
	private function httpAuth()
	{
		$admin = $this->getConfig('admin');

		if (! isset($_SERVER['PHP_AUTH_USER']) || ! isset($_SERVER['PHP_AUTH_PW'])) {
            header('WWW-Authenticate: Basic realm=""');
            header('HTTP/1.0 401 Unauthorized');
            die('Permission required.');
		}

		if (
			$admin['user'] === $_SERVER['PHP_AUTH_USER'] && 
			password_verify($_SERVER['PHP_AUTH_PW'], $admin['pass'])
		) {} else {
            header('HTTP/1.0 401 Unauthorized');
            die('Permission required.');
		}
	}

	// @codeCoverageIgnoreEnd
}

