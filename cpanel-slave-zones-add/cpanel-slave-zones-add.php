<?php

// Auth ID and Password
define("AUTH_ID", 0);
define("AUTH_PASS", "xxx");

// IP address of the master server (primary server)
define("MASTER_IP", "xxx.xxx.xxx.xxx");

// Optional second master IP
// define("MASTER_IP2", "xxx.xxx.xxx.xxx");

// Paths
define("ZONES_DIR", "/var/named/");
define("TMPFILE", "/tmp/cloudns_invalid-zone-names.txt");

// Check TMPFILE is writable
if ((file_exists(TMPFILE) && !is_writable(TMPFILE)) || (!file_exists(TMPFILE) && !is_writable(dirname(TMPFILE)))) {
    die("TMPFILE (" . TMPFILE . ") is not writable. Please update path or permissions.\n");
}

// PHP 7+ compatibility
if (!function_exists('str_ends_with')) {
    function str_ends_with($haystack, $needle) {
        return substr($haystack, -strlen($needle)) === $needle;
    }
}

// ClouDNS API call
function apiCall($url, $data) {
    $url = "https://api.cloudns.net/{$url}";
    $data = "auth-id=" . AUTH_ID . "&auth-password=" . AUTH_PASS . "&{$data}";
    $init = curl_init();
    curl_setopt($init, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($init, CURLOPT_URL, $url);
    curl_setopt($init, CURLOPT_POST, true);
    curl_setopt($init, CURLOPT_POSTFIELDS, $data);
    curl_setopt($init, CURLOPT_USERAGENT, 'cloudns_api_script/0.1 (+https://github.com/ClouDNS/cloudns-api-bulk-updates/tree/master/cpanel-slave-zones-add)');
    $content = curl_exec($init);
    curl_close($init);
    return json_decode($content, true);
}

// Confirm ClouDNS login
$login = apiCall('dns/login.json', "");
if (isset($login['status']) && $login['status'] == 'Failed') {
    die("Login failed: " . $login['statusDescription'] . "\n");
}

// Load invalid zone list
$invalid_zones = file_exists(TMPFILE)
    ? file(TMPFILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
    : [];

// Get .db zone files, sorted alphabetically
$zoneFiles = array_filter(scandir(ZONES_DIR), function ($f) {
    return str_ends_with($f, '.db');
});
sort($zoneFiles);

// Init counters
$added = 0;
$skipped = 0;
$failed = 0;
$limit_reached = 0;

echo "== Starting zone sync from " . ZONES_DIR . " to ClouDNS ==\n";

foreach ($zoneFiles as $zoneFile) {
    echo "Processing: {$zoneFile}... ";

    if (in_array($zoneFile, $invalid_zones)) {
        echo "skipped (previously invalid).\n";
        $skipped++;
        continue;
    }

    $zoneShort = preg_replace('/\.db$/', '', $zoneFile);
    echo "registering '{$zoneShort}' as slave... ";

    $response = apiCall('dns/register.json', "domain-name={$zoneShort}&zone-type=slave&master-ip=" . MASTER_IP);

    if (isset($response['status']) && $response['status'] === 'Failed') {
        $desc = $response['statusDescription'] ?? 'Unknown error';
        echo "FAILED: {$desc}\n";

        // Track specific limit error separately
        if (stripos($desc, 'Zone limit') !== false) {
            $limit_reached++;
        } else {
            $failed++;
        }

        file_put_contents(TMPFILE, $zoneFile . "\n", FILE_APPEND);
        continue;
    }

    echo "success.\n";
    $added++;

    if (defined('MASTER_IP2') && constant('MASTER_IP2')) {
        echo " -> Adding second master IP: " . MASTER_IP2 . "\n";
        apiCall('dns/add-master-server.json', "domain-name={$zoneShort}&master-ip=" . MASTER_IP2);
    }
}

echo "\n== Sync Summary ==\n";
echo "Zones added:        {$added}\n";
echo "Skipped (invalid):  {$skipped}\n";
echo "Failures:           {$failed}\n";
echo "Zone limit reached: {$limit_reached}\n";
