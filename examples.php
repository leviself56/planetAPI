<?php
/**
 * Usage showcase for every public PlanetAPI method.
 * Update the configuration below to match your device before running.
 */

require_once __DIR__ . '/class.planet.php';

$config = [
    'base_url' => 'http://192.168.188.140',
    'username' => 'admin',
    'password' => 'admin',
    'timeout'  => 15,
];


// Optional debug logging to STDERR.
PlanetAPI::setDebug(false);

function showResult(string $label, $result): void {
    $isError = is_array($result) && isset($result['success']) && $result['success'] === false;
    echo str_repeat('-', 60) . PHP_EOL;
    echo $label . PHP_EOL;
    echo str_repeat('-', 60) . PHP_EOL;
    if ($isError) {
        echo "Operation failed:\n";
        echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL . PHP_EOL;
    } else {
        echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL . PHP_EOL;
    }
}

// System detail getters.
showResult('System Info', PlanetAPI::getSystemInfo($config));
showResult('System Resources', PlanetAPI::getSystemResources($config));
showResult('Network Config', PlanetAPI::getNetworkConfig($config));

// Updating text fields (device name, comment, location, contact).
showResult('Update System Info', PlanetAPI::updateSystemInfo($config, [
    'devicename' => 'Demo Switch',
    'location' => 'Network Closet',
]));

// Convenience wrapper for a single device-name change.
showResult('Set Device Name', PlanetAPI::setDeviceName($config, 'Demo Switch #1'));

// Bandwidth information and shaping controls.
showResult('Bandwidth Control', PlanetAPI::getBandwidthControl($config));
showResult('Set Port Bandwidth Limits (Port 1)', PlanetAPI::setPortBandwidthLimits($config, 1, 50000, 100000));

// Link/SFP diagnostics.
showResult('SFP Info', PlanetAPI::getSFPInfo($config));
showResult('Port Link Status', PlanetAPI::getPortLinkStatus($config));

// VLAN table pagination (start index 1, 16 entries).
showResult('Switch VLANs', PlanetAPI::getSwitchVlans($config, 1, 16));

// Saving configuration explicitly (most write methods already call this).
showResult('Save Configuration', PlanetAPI::saveConfiguration($config));

// Exporting an on-box backup archive; returns path to local .tar.gz.
$backupPath = PlanetAPI::makeBackup($config);
showResult('Make Backup', $backupPath);

// Composite snapshot that includes the most important data sets.
showResult('Print Switch Data', PlanetAPI::printSwitchData($config));

// Credential update example (commented out to avoid surprises). Uncomment to use.
// showResult('Set Credentials', PlanetAPI::setCredentials($config, 'new-user', 'new-pass-123'));


// Reboot example (also commented for safety). Uncomment only when ready.
// showResult('Reboot Switch', PlanetAPI::rebootSwitch($config));


?>
