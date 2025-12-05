<?php
require_once __DIR__ . '/../../config.php';

api_require_method('POST');
$config = api_build_config();

$confirm = strtolower((string) api_request_value('confirm', 'false'));
if (!in_array($confirm, ['1', 'true', 'yes'], true)) {
    api_respond_error('system.reboot', 'Confirmation required. Pass confirm=true to reboot the switch.', 400);
}

$result = PlanetAPI::rebootSwitch($config);
api_emit_result('system.reboot', $result, 202);
