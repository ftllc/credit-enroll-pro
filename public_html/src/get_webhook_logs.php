<?php
/**
 * Get Webhook Logs
 * Returns the last 100 lines of the webhook log file
 */

define('SNS_ENROLLMENT', true);
require_once __DIR__ . '/config.php';

$log_file = ROOT_PATH . '/logs/voipms_webhook.log';

if (!file_exists($log_file)) {
    echo "No log file found yet.";
    exit;
}

// Get last 100 lines
$lines = file($log_file);
$last_lines = array_slice($lines, -100);

echo implode('', $last_lines);
