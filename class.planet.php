<?php

class PlanetAPIException extends RuntimeException {}

class PlanetAPIConnectionException extends PlanetAPIException {
	private $systemId;
	private $endpoint;

	public function __construct($message, $systemId, $endpoint, $code = 0, $previous = null) {
		if (!($previous instanceof Throwable)) {
			$previous = null;
		}

		parent::__construct($message, $code, $previous);
		$this->systemId = $systemId;
		$this->endpoint = $endpoint;
	}

	public function getSystemId() {
		return $this->systemId;
	}

	public function getEndpoint() {
		return $this->endpoint;
	}
}

class PlanetAPI {

    private static $systems = [];

	private static $sessionInitialized = [];
	private static $seidCache = [];
	private static $debug = false;
	private static $logger = null;

	private function __construct() {
	}

	public static function registerSystem($systemId, array $config) {
		if (!isset($config['base_url'], $config['username'], $config['password'])) {
			throw new InvalidArgumentException('Configuration requires base_url, username, and password.');
		}

		self::$systems[$systemId] = array_merge([
			'timeout' => 10,
		], $config);
	}

	public static function unregisterSystem($systemId) {
		unset(self::$systems[$systemId]);
		$hash = self::systemHash($systemId);
		unset(self::$sessionInitialized[$hash], self::$seidCache[$hash]);
	}

	public static function setDebug($enabled, $logger = null) {
		self::$debug = (bool) $enabled;

		if ($logger !== null && !is_callable($logger)) {
			throw new InvalidArgumentException('Logger must be callable or null.');
		}

		self::$logger = $logger;
	}

	private static function resolveConfig($systemId): array {
		if (is_array($systemId)) {
			if (!isset($systemId['base_url'], $systemId['username'], $systemId['password'])) {
				throw new InvalidArgumentException('Inline configuration requires base_url, username, and password.');
			}

			return array_merge([
				'timeout' => 10,
			], $systemId);
		}

		if (!isset(self::$systems[$systemId])) {
			throw new InvalidArgumentException("Unknown system identifier {$systemId}.");
		}

		return self::$systems[$systemId];
	}

	private static function systemHash($systemId): string {
		if (is_array($systemId)) {
			return md5(json_encode($systemId));
		}

		return (string) $systemId;
	}

	private static function logDebug($message, array $context = []): void {
		if (!self::$debug) {
			return;
		}

		$line = $message;

		if (!empty($context)) {
			$line .= ' | ' . json_encode($context);
		}

		if (self::$logger !== null) {
			call_user_func(self::$logger, $line, $context);
			return;
		}

		error_log('[PlanetAPI] ' . $line);
	}

	private static function describeSystem($systemId, array $config): string {
		$identifier = is_array($systemId) ? ($systemId['base_url'] ?? 'inline-config') : (string) $systemId;
		$target = isset($config['base_url']) ? $config['base_url'] : 'unknown';

		return $identifier . ' @ ' . $target;
	}

	private static function resolveHost(array $config): string {
		$host = parse_url($config['base_url'], PHP_URL_HOST);
		return $host !== false && $host !== null ? $host : $config['base_url'];
	}

	private static function buildRefererUrl($systemId, string $path, bool $includeSeid = true): string {
		$config = self::resolveConfig($systemId);
		$base = rtrim($config['base_url'], '/');
		$path = '/' . ltrim($path, '/');
		$url = $base . $path;

		if ($includeSeid) {
			$hash = self::systemHash($systemId);
			if (isset(self::$seidCache[$hash])) {
				$url .= (strpos($url, '?') === false ? '?' : '&') . 'seid=' . rawurlencode(self::$seidCache[$hash]);
			}
		}

		return $url;
	}

	private static function generateSeid(): string {
		$min = 100000000;
		$max = 999999999;

		if (function_exists('random_int')) {
			return (string) random_int($min, $max);
		}

		return (string) mt_rand($min, $max);
	}

	private static function injectCookie($ch, array $config, $name, $value): void {
		$host = self::resolveHost($config);
		$cookieLine = sprintf('Set-Cookie: %s=%s; domain=%s; path=/', $name, $value, $host);
		curl_setopt($ch, CURLOPT_COOKIELIST, $cookieLine);
	}

	private static function ensureLoginHandshake($systemId): void {
		$hash = self::systemHash($systemId);

		if (isset(self::$seidCache[$hash])) {
			return;
		}

		$seid = self::generateSeid();
		self::$seidCache[$hash] = $seid;
		self::logDebug('Performing login handshake', ['hash' => $hash, 'seid' => $seid]);
		self::request($systemId, '/cgi-bin/login.cgi', ['seid' => $seid]);
	}

	private static function getCookieJarPath($systemId): string {
		$hash = self::systemHash($systemId);
		$path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'planetapi_' . $hash . '.cookie';

		if (!file_exists($path) && touch($path) === false) {
			throw new RuntimeException('Unable to create cookie jar for Planet API.');
		}

		return $path;
	}

	private static function ensureSession($systemId): void {
		$hash = self::systemHash($systemId);

		if (isset(self::$sessionInitialized[$hash])) {
			return;
		}

		self::logDebug('Initializing new session', ['hash' => $hash]);
		self::request($systemId, '/');
		self::ensureLoginHandshake($systemId);
		self::$sessionInitialized[$hash] = true;
		self::logDebug('Session initialized', ['hash' => $hash]);
	}

	private static function resetSessionState($systemId): void {
		$hash = self::systemHash($systemId);
		unset(self::$sessionInitialized[$hash], self::$seidCache[$hash]);
		$cookiePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'planetapi_' . $hash . '.cookie';
		if (file_exists($cookiePath)) {
			@unlink($cookiePath);
		}
	}

	private static function buildErrorResult(string $operation, string $message, ?int $code = null, array $context = []): array {
		$result = [
			'success' => false,
			'operation' => $operation,
			'error' => $message,
		];

		if ($code !== null && $code !== 0) {
			$result['code'] = $code;
		}

		if (!empty($context)) {
			$result['context'] = $context;
		}

		return $result;
	}

	private static function isErrorResult($value): bool {
		return is_array($value) && isset($value['success']) && $value['success'] === false;
	}

	private static function guardOperation(string $operation, callable $callback) {
		try {
			return $callback();
		} catch (PlanetAPIConnectionException $e) {
			self::logDebug($operation . ' connection failure', ['error' => $e->getMessage(), 'code' => $e->getCode(), 'endpoint' => $e->getEndpoint(), 'system' => $e->getSystemId()]);
			return self::buildErrorResult($operation, $e->getMessage(), $e->getCode(), [
				'endpoint' => $e->getEndpoint(),
				'system' => $e->getSystemId(),
			]);
		} catch (PlanetAPIException $e) {
			self::logDebug($operation . ' failed', ['error' => $e->getMessage(), 'code' => $e->getCode()]);
			return self::buildErrorResult($operation, $e->getMessage(), $e->getCode());
		} catch (Throwable $e) {
			self::logDebug($operation . ' unexpected failure', ['error' => $e->getMessage()]);
			$code = method_exists($e, 'getCode') ? $e->getCode() : null;
			return self::buildErrorResult($operation, $e->getMessage(), $code);
		}
	}

	private static function request($systemId, string $endpoint, $query = [], ?string $payload = null, string $method = 'GET', array $headers = []): string {
		if (!function_exists('curl_init')) {
			throw new RuntimeException('The cURL PHP extension is required to use PlanetAPI.');
		}

		$config = self::resolveConfig($systemId);
		$url = rtrim($config['base_url'], '/') . $endpoint;
		$hash = self::systemHash($systemId);
		$seid = isset(self::$seidCache[$hash]) ? self::$seidCache[$hash] : null;
		$queryString = '';

		if (is_array($query) && !empty($query)) {
			if ($seid !== null && !array_key_exists('seid', $query)) {
				$query['seid'] = $seid;
			}
			$queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
		} elseif (is_string($query) && $query !== '') {
			$queryString = ltrim($query, '?');
			if ($seid !== null && stripos($queryString, 'seid=') === false) {
				$queryString .= ($queryString === '' ? '' : '&') . 'seid=' . rawurlencode($seid);
			}
		} elseif ($seid !== null) {
			$queryString = 'seid=' . rawurlencode($seid);
		}

		if ($queryString !== '') {
			$url .= (strpos($url, '?') !== false ? '&' : '?') . $queryString;
		}

		$ch = curl_init($url);

		if ($ch === false) {
			throw new RuntimeException('Unable to initialize cURL.');
		}

		$cookieJar = self::getCookieJarPath($systemId);

		self::logDebug('Issuing request', [
			'method' => $method,
			'url' => $url,
			'has_query' => $queryString !== '',
			'has_payload' => $payload !== null,
			'cookie_jar' => $cookieJar,
		]);

		$defaultHeaders = [
			'Accept: */*',
			'User-Agent: PlanetAPI/1.0 (+curl)'
		];
		$hasCustomContentType = false;

		foreach ($headers as $headerLine) {
			if (stripos($headerLine, 'Content-Type:') === 0) {
				$hasCustomContentType = true;
				break;
			}
		}

		if ($payload !== null && !$hasCustomContentType) {
			$defaultHeaders[] = 'Content-Type: application/x-www-form-urlencoded; charset=UTF-8';
		}

		$options = [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_USERPWD => $config['username'] . ':' . $config['password'],
			CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
			CURLOPT_TIMEOUT => $config['timeout'],
			CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_HTTPHEADER => array_merge($defaultHeaders, $headers),
			CURLOPT_COOKIEJAR => $cookieJar,
			CURLOPT_COOKIEFILE => $cookieJar,
		];

		$traceHandle = null;
		$captureHeaders = self::$debug;

		if ($captureHeaders) {
			$options[CURLOPT_HEADER] = true;
			$options[CURLOPT_VERBOSE] = true;
			$traceHandle = @fopen('php://output', 'w');

			if ($traceHandle !== false) {
				$options[CURLOPT_STDERR] = $traceHandle;
			} else {
				$traceHandle = null;
			}
		}

		if ($payload !== null) {
			$options[CURLOPT_POSTFIELDS] = $payload;
		}

		curl_setopt_array($ch, $options);

		if ($seid !== null) {
			self::injectCookie($ch, $config, 'seid', $seid);
		}

		$response = curl_exec($ch);

		if ($traceHandle !== null) {
			fclose($traceHandle);
		}

		if ($response === false) {
			$error = curl_error($ch);
			$errno = curl_errno($ch);
			$label = self::describeSystem($systemId, $config);
			self::closeCurlHandle($ch);

			self::logDebug('cURL error', [
				'message' => $error,
				'errno' => $errno,
				'system' => $label,
				'endpoint' => $endpoint,
			]);

			$message = sprintf(
				'Unable to reach %s (endpoint %s): %s [cURL #%d]',
				$label,
				$endpoint,
				$error !== '' ? $error : 'unknown error',
				$errno
			);

			throw new PlanetAPIConnectionException($message, $label, $endpoint, $errno);
		}

		$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
		$statusCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$label = self::describeSystem($systemId, $config);
		self::closeCurlHandle($ch);

		$body = $response;

		if ($captureHeaders && $headerSize > 0) {
			$rawHeaders = substr($response, 0, $headerSize);
			$body = substr($response, $headerSize);
			self::logDebug('Response headers', ['headers' => trim($rawHeaders), 'system' => $label, 'endpoint' => $endpoint]);
		}

		self::logDebug('Response received', [
			'status' => $statusCode,
			'body_length' => strlen($body),
			'body_preview' => substr(trim((string) $body), 0, 200),
			'system' => $label,
			'endpoint' => $endpoint,
		]);

		if ($statusCode >= 400) {
			$message = sprintf(
				'Planet API responded with HTTP %d for %s (endpoint %s)',
				$statusCode,
				$label,
				$endpoint
			);

			throw new PlanetAPIException($message, $statusCode);
		}

		return (string) $body;
	}

	private static function closeCurlHandle($handle): void {
		if (!is_resource($handle) && !(PHP_VERSION_ID >= 80000 && $handle instanceof CurlHandle)) {
			return;
		}

		if (PHP_VERSION_ID < 80500) {
			curl_close($handle);
		} else {
			unset($handle);
		}
	}

	private static function buildFieldSelector(array $fields): string {
		return implode('$;', array_map('trim', $fields)) . '$;';
	}

	private static function parseFieldResponse(string $payload, array $fields): array {
		$parts = array_map('trim', explode('$;', trim($payload)));
		$result = [];

		foreach ($fields as $index => $field) {
			$result[$field] = array_key_exists($index, $parts) ? $parts[$index] : null;
		}

		return $result;
	}

	private static function sanitizeValue(string $value): string {
		return str_replace('$;', '', trim($value));
	}

	private static function truncateValue($value, int $limit): string {
		$value = (string) $value;
		if ($limit <= 0) {
			return '';
		}

		if (function_exists('mb_substr')) {
			return mb_substr($value, 0, $limit, 'UTF-8');
		}

		return substr($value, 0, $limit);
	}

	private static function normalizeSystemInfoValue($value, int $limit): string {
		$value = str_replace(["\r", "\n", "\t"], ' ', (string) $value);
		$value = preg_replace('/\s+/', '.', trim($value));
		$value = str_replace('$;', '', $value);
		$value = preg_replace('/[^A-Za-z0-9_\-.]/', '_', $value);
		return self::truncateValue($value, $limit);
	}

	private static function normalizeNumericString($value): ?int {
		if ($value === null || $value === '') {
			return null;
		}

		if (is_numeric($value)) {
			return (int) $value;
		}

		if (preg_match('/(-?\d+)/', (string) $value, $matches)) {
			return (int) $matches[1];
		}

		return null;
	}

	private static function normalizeBooleanFlag($value): ?bool {
		if ($value === null || $value === '') {
			return null;
		}

		if (is_bool($value)) {
			return $value;
		}

		$normalized = strtolower((string) $value);

		$truthy = ['1', 'true', 'yes', 'on', 'up', 'present'];
		$falsy = ['0', 'false', 'no', 'off', 'down', 'absent'];

		if (in_array($normalized, $truthy, true)) {
			return true;
		}

		if (in_array($normalized, $falsy, true)) {
			return false;
		}

		return null;
	}

	private static function buildSfpPortLinkStatus(array $sfpInfo): array {
		$speed = self::normalizeNumericString($sfpInfo['speed'] ?? null);
		$linkUp = isset($sfpInfo['identify']) ? self::normalizeBooleanFlag($sfpInfo['identify']) : null;

		return [
			'auto_negotiation' => null,
			'flow_control' => null,
			'asymmetric_flow' => null,
			'duplex_full' => null,
			'speed_mbps' => $speed,
			'link_up' => $linkUp,
			'link_time_seconds' => null,
		];
	}

	private static function writeFields($systemId, array $pairs, string $endpoint = '/cgi-bin/sysinfo.cgi', array $headers = []): bool {
		if (empty($pairs)) {
			return false;
		}

		$segments = [];

		foreach ($pairs as $field => $value) {
			$segments[] = sprintf('%s=%s$;', $field, self::sanitizeValue((string) $value));
		}

		if (empty($segments)) {
			return false;
		}

		$payload = 'W=' . implode('', $segments);
		$response = self::request($systemId, $endpoint, [], $payload, 'POST', $headers);

		return stripos($response, 'OK') !== false;
	}

	private static function performSaveConfiguration($systemId): bool {
		self::ensureSession($systemId);

		$headers = [
			'X-Requested-With: XMLHttpRequest',
			'Referer: ' . self::buildRefererUrl($systemId, 'sysinfo.htm', false),
			'Cache-Control: no-cache',
			'Pragma: no-cache',
		];

		$response = trim(self::request(
			$systemId,
			'/cgi-bin/save.cgi',
			'R=undefined',
			null,
			'GET',
			$headers
		));

		return stripos($response, 'OK') !== false;
	}

	public static function getSystemInfo($systemId): array {
		return self::guardOperation('getSystemInfo', function () use ($systemId) {
			self::ensureSession($systemId);

			$fields = [
				'mac',
				'fwversion',
				'sysdate',
				'uptime',
				'fwdate',
				'devicename',
				'comment',
				'location',
				'contact',
			];

			$headers = [
				'X-Requested-With: XMLHttpRequest',
				'Referer: ' . self::buildRefererUrl($systemId, 'system_info.htm'),
				'Cache-Control: no-cache',
				'Pragma: no-cache',
			];

			$query = 'R=' . self::buildFieldSelector($fields);

			$response = self::request(
				$systemId,
				'/cgi-bin/sysinfo.cgi',
				$query,
				null,
				'GET',
				$headers
			);

			return self::parseFieldResponse($response, $fields);
		});
	}

	public static function getSystemResources($systemId): array {
		return self::guardOperation('getSystemResources', function () use ($systemId) {
			self::ensureSession($systemId);

			$headers = [
				'X-Requested-With: XMLHttpRequest',
				'Referer: ' . self::buildRefererUrl($systemId, 'system_monitor.htm'),
				'Cache-Control: no-cache',
				'Pragma: no-cache',
			];

			$response = trim(self::request(
				$systemId,
				'/cgi-bin/cpuinfo.cgi',
				[],
				null,
				'GET',
				$headers
			));

			$ramUsage = null;
			$cpuUsage = null;

			if ($response !== '') {
				$parts = explode('=', $response, 2);
				if (isset($parts[0]) && $parts[0] !== '') {
					$ramUsage = (int) $parts[0];
				}
				if (isset($parts[1]) && $parts[1] !== '') {
					$cpuUsage = (int) $parts[1];
				}
			}

			$formattedFreeMemory = $ramUsage !== null ? sprintf('%dK', $ramUsage) : null;
			$formattedCpuUsage = $cpuUsage !== null ? sprintf('%d%%', $cpuUsage) : null;

			return [
				'ram_usage_kb' => $ramUsage,
				'cpu_usage_percent' => $cpuUsage,
				'free_memory_display' => $formattedFreeMemory,
				'cpu_usage_display' => $formattedCpuUsage,
			];
		});
	}

	public static function getNetworkConfig($systemId): array {
		return self::guardOperation('getNetworkConfig', function () use ($systemId) {
			self::ensureSession($systemId);

			$fields = [
				'address',
				'submask',
				'gateway',
				'dhcpc',
				'dhcp_IP',
				'dhcp_submask',
				'dhcp_gateway',
			];

			$headers = [
				'X-Requested-With: XMLHttpRequest',
				'Referer: ' . self::buildRefererUrl($systemId, 'ip_config.htm'),
				'Cache-Control: no-cache',
				'Pragma: no-cache',
			];

			$query = 'R=' . self::buildFieldSelector($fields);
			$response = self::request(
				$systemId,
				'/cgi-bin/ip.cgi',
				$query,
				null,
				'GET',
				$headers
			);

			$parsed = self::parseFieldResponse($response, $fields);
			$dhcpEnabled = isset($parsed['dhcpc']) ? $parsed['dhcpc'] === '1' : null;

			return [
				'static' => [
					'ip' => $parsed['address'] ?? null,
					'subnet' => $parsed['submask'] ?? null,
					'gateway' => $parsed['gateway'] ?? null,
				],
				'dhcp' => [
					'enabled' => $dhcpEnabled,
					'ip' => $parsed['dhcp_IP'] ?? null,
					'subnet' => $parsed['dhcp_submask'] ?? null,
					'gateway' => $parsed['dhcp_gateway'] ?? null,
				],
			];
		});
	}

	public static function setDeviceName($systemId, $deviceName) {
		return self::updateSystemInfo($systemId, ['devicename' => $deviceName]);
	}

	public static function updateSystemInfo($systemId, array $fields) {
		return self::guardOperation('updateSystemInfo', function () use ($systemId, $fields) {
			self::ensureSession($systemId);

			if (empty($fields)) {
				return false;
			}

			$allowed = ['devicename', 'comment', 'location', 'contact'];
			$limits = [
				'devicename' => 15,
				'comment' => 25,
				'location' => 25,
				'contact' => 25,
			];
			$filtered = [];

			foreach ($fields as $key => $value) {
				if (in_array($key, $allowed, true)) {
					if (isset($limits[$key])) {
						$filtered[$key] = self::normalizeSystemInfoValue($value, $limits[$key]);
					} else {
						$filtered[$key] = (string) $value;
					}
				}
			}

			if (empty($filtered)) {
				return false;
			}

			self::logDebug('Updating system info fields', ['fields' => $filtered]);

			$config = self::resolveConfig($systemId);
			$headers = [
				'X-Requested-With: XMLHttpRequest',
				'Content-Type: text/plain;charset=UTF-8',
				'Referer: ' . self::buildRefererUrl($systemId, 'sysinfo.htm', false),
				'Origin: ' . rtrim($config['base_url'], '/'),
			];

			$success = true;
			foreach ($filtered as $field => $value) {
				$selfPayload = [$field => $value];
				$result = self::writeFields($systemId, $selfPayload, '/cgi-bin/sysinfo.cgi', $headers);
				$success = $success && $result;
			}

			if ($success) {
				$success = self::performSaveConfiguration($systemId);
			}

			return $success;
		});
	}

	public static function getBandwidthControl($systemId): array {
		return self::guardOperation('getBandwidthControl', function () use ($systemId) {
			self::ensureSession($systemId);

			$headers = [
				'X-Requested-With: XMLHttpRequest',
				'Referer: ' . self::buildRefererUrl($systemId, 'bandwidth.htm'),
				'Cache-Control: no-cache',
				'Pragma: no-cache',
			];

			$query = 'R=bandwidth_1$;bandwidth_2$;';
			$response = trim(self::request(
				$systemId,
				'/cgi-bin/bandwidth.cgi',
				$query,
				null,
				'GET',
				$headers
			));

			$entries = array_filter(array_map('trim', explode('$;', $response)));
			$result = ['ports' => []];

			foreach ($entries as $index => $line) {
				$params = [];
				parse_str($line, $params);
				$result['ports'][$index + 1] = [
					'ingress_rate' => isset($params['in_rate']) ? (int) $params['in_rate'] : null,
					'egress_rate' => isset($params['e_rate']) ? (int) $params['e_rate'] : null,
				];
			}

			return $result;
		});
	}

	public static function setPortBandwidthLimits($systemId, int $port, int $ingressKbps, int $egressKbps) {
		return self::guardOperation('setPortBandwidthLimits', function () use ($systemId, $port, $ingressKbps, $egressKbps) {
			self::ensureSession($systemId);

			if ($port < 1) {
				throw new InvalidArgumentException('Port index must be 1 or greater.');
			}

			$ingress = max(0, $ingressKbps);
			$egress = max(0, $egressKbps);
			$field = sprintf('bandwidth_%d', $port);
			$value = sprintf('ingress_rate=%d&egress_rate=%d', $ingress, $egress);
			$headers = [
				'X-Requested-With: XMLHttpRequest',
				'Content-Type: text/plain;charset=UTF-8',
				'Referer: ' . self::buildRefererUrl($systemId, 'bandwidth.htm', false),
				'Origin: ' . rtrim(self::resolveConfig($systemId)['base_url'], '/'),
			];

			$success = self::writeFields($systemId, [$field => $value], '/cgi-bin/bandwidth.cgi', $headers);

			if ($success) {
				$success = self::performSaveConfiguration($systemId);
			}

			return $success;
		});
	}

	public static function setCredentials($systemId, string $username, string $password) {
		return self::guardOperation('setCredentials', function () use ($systemId, $username, $password) {
			self::ensureSession($systemId);

			$headers = [
				'X-Requested-With: XMLHttpRequest',
				'Content-Type: text/plain;charset=UTF-8',
				'Referer: ' . self::buildRefererUrl($systemId, 'system_account.htm', false),
				'Origin: ' . rtrim(self::resolveConfig($systemId)['base_url'], '/'),
			];

			$fields = [
				'User' => self::sanitizeValue($username),
				'Password' => self::sanitizeValue($password),
			];
			$success = self::writeFields($systemId, $fields, '/cgi-bin/account.cgi', $headers);

			if ($success) {
				$resolvedConfig = self::resolveConfig($systemId);
				$resolvedConfig['username'] = $username;
				$resolvedConfig['password'] = $password;

				if (!is_array($systemId)) {
					self::$systems[$systemId] = $resolvedConfig;
					$updatedSystemId = $systemId;
				} else {
					$updatedSystemId = $resolvedConfig;
				}

				self::resetSessionState($systemId);
				$attempts = 0;
				$maxAttempts = 3;
				$success = false;
				while ($attempts < $maxAttempts) {
					try {
						$success = self::performSaveConfiguration($updatedSystemId);
						break;
					} catch (PlanetAPIConnectionException $e) {
						$attempts++;
						if ($attempts >= $maxAttempts) {
							throw $e;
						}
						sleep(2);
					}
				}
			}

			return $success;
		});
	}

	public static function getSFPInfo($systemId): array {
		return self::guardOperation('getSFPInfo', function () use ($systemId) {
			self::ensureSession($systemId);

			$headers = [
				'X-Requested-With: XMLHttpRequest',
				'Referer: ' . self::buildRefererUrl($systemId, 'sfp_status.htm'),
				'Cache-Control: no-cache',
				'Pragma: no-cache',
			];

			$query = 'R=identify$;type$;cu_speed$;wave_length$;distance$;temperature$;voltage$;current$;tx_power$;rx_power$;vendor_name$;vendor_oui$;vendor_pn$;vendor_rev$;vendor_sn$;date_code$;';

			try {
				$response = trim(self::request(
					$systemId,
					'/cgi-bin/sfp_info.cgi',
					$query,
					null,
					'GET',
					$headers
				));
			} catch (PlanetAPIException $e) {
				if ($e->getCode() === 404) {
					self::logDebug('SFP info endpoint missing, returning empty list', []);
					return [];
				}

				throw $e;
			}

			$parts = array_map('trim', explode('$;', $response));
			$fieldMap = [
				'port',
				'type',
				'speed',
				'wave_length_nm',
				'distance_m',
				'temperature_c',
				'voltage_v',
				'current_ma',
				'tx_power_dbm',
				'rx_power_dbm',
				'vendor_name',
				'vendor_oui',
				'vendor_part_number',
				'vendor_revision',
				'vendor_serial',
				'date_code',
			];

			$result = [];

			foreach ($fieldMap as $index => $label) {
				$result[$label] = isset($parts[$index]) && $parts[$index] !== '' ? $parts[$index] : null;
			}

			return $result;
		});
	}

	public static function getPortLinkStatus($systemId): array {
		return self::guardOperation('getPortLinkStatus', function () use ($systemId) {
			self::ensureSession($systemId);

			$headers = [
				'X-Requested-With: XMLHttpRequest',
				'Referer: ' . self::buildRefererUrl($systemId, 'port_status.htm'),
				'Cache-Control: no-cache',
				'Pragma: no-cache',
			];

			$query = 'R=port_1$;port_2$;';
			$response = trim(self::request(
				$systemId,
				'/cgi-bin/port_current.cgi',
				$query,
				null,
				'GET',
				$headers
			));

			$ports = array_filter(array_map('trim', explode('$;', $response)));
			$result = ['ports' => []];

			foreach ($ports as $index => $portLine) {
				$portLine = trim($portLine);
				$params = [];
				parse_str($portLine, $params);

				$normalized = [
					'auto_negotiation' => isset($params['an']) ? $params['an'] === '1' : null,
					'flow_control' => isset($params['pause']) ? $params['pause'] === '1' : null,
					'asymmetric_flow' => isset($params['asym']) ? $params['asym'] === '1' : null,
					'duplex_full' => isset($params['duplex']) ? $params['duplex'] === '1' : null,
					'speed_mbps' => isset($params['speed']) ? (int) $params['speed'] : null,
					'link_up' => isset($params['link']) ? $params['link'] === '1' : null,
					'link_time_seconds' => isset($params['time']) ? (int) $params['time'] : null,
				];

				$result['ports'][$index + 1] = $normalized;
			}

			return $result;
		});
	}

	public static function getSwitchVlans($systemId, int $startIndex = 1, int $count = 32): array {
		return self::guardOperation('getSwitchVlans', function () use ($systemId, $startIndex, $count) {
			self::ensureSession($systemId);

			$startIndex = max(1, $startIndex);
			$count = max(1, min($count, 128));
			$indices = [];

			for ($i = 0; $i < $count; $i++) {
				$indices[] = 'tagentry_' . ($startIndex + $i) . '$;';
			}

			$query = 'R=' . implode('', $indices);

			$headers = [
				'X-Requested-With: XMLHttpRequest',
				'Referer: ' . self::buildRefererUrl($systemId, 'vlan_config.htm'),
				'Cache-Control: no-cache',
				'Pragma: no-cache',
			];

			$response = trim(self::request(
				$systemId,
				'/cgi-bin/vlan.cgi',
				$query,
				null,
				'GET',
				$headers
			));

			$rawEntries = array_filter(array_map('trim', explode('$;', $response)));
			$result = [];

			foreach ($rawEntries as $entry) {
				if ($entry === '' || stripos($entry, 'ERROR') !== false) {
					continue;
				}
				$params = [];
				parse_str($entry, $params);

				if (empty($params)) {
					continue;
				}

				$result[] = [
					'name' => $params['name'] ?? null,
					'vid' => isset($params['vid']) ? (int) $params['vid'] : null,
					'state' => $params['state'] ?? null,
					'members' => $params['mem'] ?? null,
					'tagged_ports' => $params['tag'] ?? null,
					'untagged_ports' => $params['untag'] ?? null,
					'forbidden_ports' => $params['forbidden'] ?? null,
					'priority' => isset($params['priority']) ? (int) $params['priority'] : null,
					'gvrp_enabled' => isset($params['gvrp']) ? $params['gvrp'] === '1' : null,
					'age_seconds' => isset($params['time']) ? (int) $params['time'] : null,
					'dynamic_members' => $params['dymem'] ?? null,
				];
			}

			return $result;
		});
	}

	public static function makeBackup($systemId) {
		return self::guardOperation('makeBackup', function () use ($systemId) {
			self::ensureSession($systemId);

			$headers = [
				'X-Requested-With: XMLHttpRequest',
				'Referer: ' . self::buildRefererUrl($systemId, 'backup.htm'),
				'Cache-Control: no-cache',
				'Pragma: no-cache',
			];

			$kickoffResponse = trim(self::request(
				$systemId,
				'/cgi-bin/back.cgi',
				'R=undefined',
				null,
				'GET',
				$headers
			));

			if ($kickoffResponse === '') {
				throw new PlanetAPIException('Backup request returned an empty response.');
			}

			$parsed = [];
			parse_str($kickoffResponse, $parsed);
			$status = $parsed['back'] ?? null;

			if ($status === null) {
				throw new PlanetAPIException('Backup response did not include a status token.');
			}

			if ($status !== 'bktar') {
				throw new PlanetAPIException(sprintf('Unexpected backup status value "%s".', $status));
			}

			$archive = self::request($systemId, '/tmp/current.tar.gz');
			$path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'planet_backup_' . time() . '.tar.gz';

			if (file_put_contents($path, $archive) === false) {
				throw new RuntimeException('Unable to write backup file to disk.');
			}

			return $path;
		});
	}

	public static function rebootSwitch($systemId) {
		return self::guardOperation('rebootSwitch', function () use ($systemId) {
			self::ensureSession($systemId);

			$response = self::request(
				$systemId,
				'/cgi-bin/reboot.cgi',
				'R=undefined',
				null,
				'GET'
			);

			return stripos($response, 'OK') !== false || $response === '';
		});
	}

	public static function saveConfiguration($systemId) {
		return self::guardOperation('saveConfiguration', function () use ($systemId) {
			return self::performSaveConfiguration($systemId);
		});
	}

	public static function printSwitchData($systemId): array {
		return self::guardOperation('printSwitchData', function () use ($systemId) {
			self::ensureSession($systemId);

			$sysinfo = self::getSystemInfo($systemId);
			if (self::isErrorResult($sysinfo)) {
				return $sysinfo;
			}

			$sysResources = self::getSystemResources($systemId);
			if (self::isErrorResult($sysResources)) {
				return $sysResources;
			}

			$networkConfig = self::getNetworkConfig($systemId);
			if (self::isErrorResult($networkConfig)) {
				return $networkConfig;
			}

			$bandwidthControl = self::getBandwidthControl($systemId);
			if (self::isErrorResult($bandwidthControl)) {
				return $bandwidthControl;
			}

			$sfpInfo = self::getSFPInfo($systemId);
			if (self::isErrorResult($sfpInfo)) {
				return $sfpInfo;
			}

			$portLinkStatus = self::getPortLinkStatus($systemId);
			if (self::isErrorResult($portLinkStatus)) {
				return $portLinkStatus;
			}

			$switchVlans = self::getSwitchVlans($systemId);
			if (self::isErrorResult($switchVlans)) {
				return $switchVlans;
			}

			$bandwidthPorts = isset($bandwidthControl['ports']) && is_array($bandwidthControl['ports'])
				? array_keys($bandwidthControl['ports'])
				: [];
			$portStatusPorts = isset($portLinkStatus['ports']) && is_array($portLinkStatus['ports'])
				? array_keys($portLinkStatus['ports'])
				: [];
			$portIds = array_unique(array_merge($bandwidthPorts, $portStatusPorts));

			$sfpPortId = null;
			if (!empty($sfpInfo)) {
				$sfpPortId = isset($sfpInfo['port']) ? (int) $sfpInfo['port'] : null;
				if ($sfpPortId === null || $sfpPortId <= 0) {
					$sfpPortId = 3;
				}
				if (!in_array($sfpPortId, $portIds, true)) {
					$portIds[] = $sfpPortId;
				}
			}

			sort($portIds);
			$ports = [];

			foreach ($portIds as $portId) {
				$ports[$portId] = [
					'bandwidth_control' => $bandwidthControl['ports'][$portId] ?? null,
					'port_link_status' => $portLinkStatus['ports'][$portId] ?? null,
					'sfp_info' => null,
				];
			}

			if ($sfpPortId !== null) {
				if (!isset($ports[$sfpPortId])) {
					$ports[$sfpPortId] = [
						'bandwidth_control' => null,
						'port_link_status' => null,
						'sfp_info' => null,
					];
				}

				$derivedStatus = self::buildSfpPortLinkStatus($sfpInfo);
				if ($ports[$sfpPortId]['port_link_status'] === null) {
					$ports[$sfpPortId]['port_link_status'] = $derivedStatus;
				} else {
					$ports[$sfpPortId]['port_link_status'] = array_merge(
						$ports[$sfpPortId]['port_link_status'],
						array_filter($derivedStatus, function ($value) {
							return $value !== null;
						})
					);
				}

				$ports[$sfpPortId]['sfp_info'] = $sfpInfo;
			}

			return [
				'system' => [
					'details' => $sysinfo,
					'resources' => $sysResources,
					'network' => [
						'ipv4' => $networkConfig,
						'vlans' => $switchVlans
					],
				],
				'ports' => $ports
			];
		});
	}

}

?>