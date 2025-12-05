<?php
require_once __DIR__ . '/../../config.php';

api_require_method('POST');
$config = api_build_config();
$payload = api_require_json_body('ports.bandwidth');
api_require_payload_fields($payload, ['port', 'ingress_kbps', 'egress_kbps'], 'ports.bandwidth');

$port = filter_var($payload['port'], FILTER_VALIDATE_INT);
if ($port === false || $port < 1) {
    api_respond_error('ports.bandwidth', 'Port must be a positive integer.', 422, ['port' => $payload['port']]);
}

$ingress = (int) $payload['ingress_kbps'];
$egress = (int) $payload['egress_kbps'];

if ($ingress < 0 || $egress < 0) {
    api_respond_error('ports.bandwidth', 'Ingress and egress values must be zero or greater.', 422);
}

$result = PlanetAPI::setPortBandwidthLimits($config, $port, $ingress, $egress);
api_emit_result('ports.bandwidth', $result);
