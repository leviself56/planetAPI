<?php
require_once __DIR__ . '/../../class.planet.php';

const API_DEFAULT_USERNAME = 'admin';
const API_DEFAULT_PASSWORD = 'admin';
const API_DEFAULT_TIMEOUT = 15;

/**
 * Cached JSON body payload so php://input is only read once.
 */
function api_get_json_body(): array {
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $raw = file_get_contents('php://input');

    if ($raw === false || trim($raw) === '') {
        $cache = [];
        return $cache;
    }

    $decoded = json_decode($raw, true);
    $cache = is_array($decoded) ? $decoded : [];

    return $cache;
}

function api_request_value(string $key, $default = null) {
    if (isset($_GET[$key])) {
        return $_GET[$key];
    }

    if (isset($_POST[$key])) {
        return $_POST[$key];
    }

    $json = api_get_json_body();
    if (array_key_exists($key, $json)) {
        return $json[$key];
    }

    return $default;
}

function api_require_method(string $method): void {
    $current = strtoupper($_SERVER['REQUEST_METHOD'] ?? '');
    if ($current !== strtoupper($method)) {
        api_respond_error(
            'http.method',
            sprintf('Method %s not allowed. Expected %s.', $current ?: 'UNKNOWN', strtoupper($method)),
            405,
            ['allowed' => $method]
        );
    }
}

function api_resolve_switch_ip(): string {
    $candidate = api_request_value('switch_ip');

    if ($candidate === null || $candidate === '') {
        $pathInfo = $_SERVER['PATH_INFO'] ?? '';
        $pathInfo = trim($pathInfo, '/');
        if ($pathInfo !== '') {
            $candidate = $pathInfo;
        }
    }

    if ($candidate === null || $candidate === '') {
        api_respond_error('switch.identification', 'Missing switch_ip. Provide it as a query segment or parameter.');
    }

    $candidate = trim($candidate);

    if (filter_var($candidate, FILTER_VALIDATE_IP) === false) {
        api_respond_error('switch.identification', 'Invalid switch_ip. Only IPv4/IPv6 literals are supported.', 422, ['value' => $candidate]);
    }

    return $candidate;
}

function api_build_config(): array {
    $ip = api_resolve_switch_ip();

    $username = (string) api_request_value('username', API_DEFAULT_USERNAME);
    $password = (string) api_request_value('password', API_DEFAULT_PASSWORD);
    $timeout = (int) api_request_value('timeout', API_DEFAULT_TIMEOUT);

    if ($timeout <= 0) {
        $timeout = API_DEFAULT_TIMEOUT;
    }

    return [
        'base_url' => sprintf('http://%s', $ip),
        'username' => $username,
        'password' => $password,
        'timeout' => $timeout,
    ];
}

function api_is_planet_error($value): bool {
    return is_array($value) && isset($value['success']) && $value['success'] === false;
}

function api_respond_json(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode($payload, JSON_PRETTY_PRINT) . PHP_EOL;
    exit;
}

function api_respond_error(string $operation, string $message, int $status = 400, array $details = []): void {
    $payload = [
        'success' => false,
        'error' => [
            'operation' => $operation,
            'message' => $message,
        ],
    ];

    if (!empty($details)) {
        $payload['error']['details'] = $details;
    }

    api_respond_json($payload, $status);
}

function api_emit_result(string $operation, $result, int $status = 200): void {
    if (api_is_planet_error($result)) {
        $details = $result;
        $message = $result['error'] ?? 'Planet API operation failed.';
        $code = isset($result['code']) ? (int) $result['code'] : 502;
        api_respond_error($operation, $message, $code >= 400 ? $code : 502, $details);
    }

    api_respond_json([
        'success' => true,
        'operation' => $operation,
        'data' => $result,
        'meta' => [
            'timestamp' => gmdate('c'),
        ],
    ], $status);
}

function api_require_payload_fields(array $payload, array $required, string $operation): void {
    $missing = [];

    foreach ($required as $field) {
        if (!array_key_exists($field, $payload) || $payload[$field] === '' || $payload[$field] === null) {
            $missing[] = $field;
        }
    }

    if (!empty($missing)) {
        api_respond_error($operation, 'Missing required fields.', 422, ['fields' => $missing]);
    }
}

function api_require_json_body(string $operation): array {
    $body = api_get_json_body();

    if (empty($body)) {
        api_respond_error($operation, 'Request body must be valid JSON.', 400);
    }

    return $body;
}
