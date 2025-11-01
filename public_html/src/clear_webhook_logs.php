<?php
/**
 * Clear Webhook Logs
 * Clears the webhook log file
 */

define('SNS_ENROLLMENT', true);
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

$log_file = ROOT_PATH . '/logs/voipms_webhook.log';

try {
    if (file_exists($log_file)) {
        file_put_contents($log_file, '');
    }
    echo json_encode(['status' => 'success', 'message' => 'Logs cleared successfully']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
