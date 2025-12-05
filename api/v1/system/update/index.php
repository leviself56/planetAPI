<?php
require_once __DIR__ . '/../../config.php';

api_require_method('POST');
$config = api_build_config();
$payload = api_require_json_body('system.update');
$allowed = ['devicename', 'comment', 'location', 'contact'];
$fields = [];

foreach ($allowed as $field) {
    if (array_key_exists($field, $payload) && $payload[$field] !== null && $payload[$field] !== '') {
        $fields[$field] = (string) $payload[$field];
    }
}

if (empty($fields)) {
    api_respond_error('system.update', 'Provide at least one of: devicename, comment, location, contact.', 422);
}

$result = PlanetAPI::updateSystemInfo($config, $fields);
api_emit_result('system.update', $result);
