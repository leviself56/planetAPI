<?php
require_once __DIR__ . '/../../config.php';

api_require_method('GET');
$config = api_build_config();
$portStatus = PlanetAPI::getPortLinkStatus($config);
if (api_is_planet_error($portStatus)) {
    api_emit_result('ports.status', $portStatus);
}

$sfpInfo = PlanetAPI::getSFPInfo($config);
if (api_is_planet_error($sfpInfo)) {
    api_emit_result('ports.status', $sfpInfo);
}

$data = [
    'ports' => isset($portStatus['ports']) ? $portStatus['ports'] : $portStatus,
    'sfp' => $sfpInfo,
];

api_emit_result('ports.status', $data);
