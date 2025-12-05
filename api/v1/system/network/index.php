<?php
require_once __DIR__ . '/../../config.php';

api_require_method('GET');
$config = api_build_config();
$result = PlanetAPI::getNetworkConfig($config);
api_emit_result('system.network', $result);
