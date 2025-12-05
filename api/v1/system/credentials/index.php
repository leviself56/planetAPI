<?php
require_once __DIR__ . '/../../config.php';

api_require_method('POST');
$config = api_build_config();
$payload = api_require_json_body('system.credentials');
api_require_payload_fields($payload, ['username', 'password'], 'system.credentials');

$newUsername = (string) $payload['username'];
$newPassword = (string) $payload['password'];

if ($newUsername === '' || $newPassword === '') {
    api_respond_error('system.credentials', 'Username and password cannot be empty.', 422);
}

$result = PlanetAPI::setCredentials($config, $newUsername, $newPassword);
api_emit_result('system.credentials', $result);
