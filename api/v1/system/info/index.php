<?php
require_once __DIR__ . '/../../config.php';

api_require_method('GET');
$config = api_build_config();
$result = PlanetAPI::getSystemInfo($config);
api_emit_result('system.info', $result);
