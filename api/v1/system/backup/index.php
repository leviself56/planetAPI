<?php
require_once __DIR__ . '/../../config.php';

api_require_method('POST');
$config = api_build_config();

$result = PlanetAPI::makeBackup($config);
if (api_is_planet_error($result)) {
    api_emit_result('system.backup', $result);
}

$filePath = (string) $result;
$data = [
    'file_path' => $filePath,
    'filename' => basename($filePath),
];

if (is_file($filePath)) {
    $data['file_size'] = filesize($filePath);
    $hash = hash_file('sha256', $filePath);
    if ($hash !== false) {
        $data['sha256'] = $hash;
    }
}

$includeRaw = strtolower((string) api_request_value('include_data', 'false'));
if (in_array($includeRaw, ['1', 'true', 'yes'], true) && is_readable($filePath)) {
    $data['content_base64'] = base64_encode(file_get_contents($filePath));
}

api_emit_result('system.backup', $data, 201);
