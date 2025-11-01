<?php
/**
 * VoIP.ms SMS Webhook Handler
 *
 * This file receives inbound SMS messages from VoIP.ms
 * Configure in VoIP.ms portal: Main Menu -> DID Numbers -> Manage DID -> SMS/MMS
 * URL Format: https://sns-enroll.transxacto.net/src/voipms_webhook.php?to={TO}&from={FROM}&message={MESSAGE}
 */

// Prevent direct access without SNS_ENROLLMENT constant
define('SNS_ENROLLMENT', true);

// Load configuration
require_once __DIR__ . '/config.php';

// Log function for debugging
function log_webhook($message) {
    // Always log webhook calls to help with debugging
    $log_file = ROOT_PATH . '/logs/voipms_webhook.log';
    $timestamp = date('Y-m-d H:i:s');
    $log_message = "[$timestamp] $message" . PHP_EOL;
    @file_put_contents($log_file, $log_message, FILE_APPEND);
}

// Get request method
$method = $_SERVER['REQUEST_METHOD'];

// Log ALL incoming data for debugging
log_webhook("=== Webhook called ===");
log_webhook("Method: $method");
log_webhook("Remote IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
log_webhook("Query String: " . ($_SERVER['QUERY_STRING'] ?? 'none'));
log_webhook("GET params: " . json_encode($_GET));
log_webhook("POST params: " . json_encode($_POST));

try {
    // VoIP.ms sends GET requests with query parameters
    if ($method === 'GET') {
        // Get parameters from URL
        $to = isset($_GET['to']) ? clean_phone($_GET['to']) : null;
        $from = isset($_GET['from']) ? clean_phone($_GET['from']) : null;
        $message = isset($_GET['message']) ? $_GET['message'] : null;

        // Optional parameters that VoIP.ms may send
        $id = isset($_GET['id']) ? $_GET['id'] : null;
        $timestamp = isset($_GET['date']) ? $_GET['date'] : null;
        $media = isset($_GET['files']) ? $_GET['files'] : null;

        log_webhook("Parsed - From: $from, To: $to, ID: $id, Timestamp: $timestamp");
        log_webhook("Message: " . substr($message ?? '', 0, 100));
        if ($media) {
            log_webhook("Media: $media");
        }

        // Validate required parameters
        if (!$to || !$from || $message === null) {
            log_webhook("ERROR: Missing required parameters - to=$to, from=$from, message=" . ($message === null ? 'null' : 'present'));
            http_response_code(400);
            echo 'Missing required parameters';
            exit;
        }

        // Store in database
        $stmt = $pdo->prepare("
            INSERT INTO sms_messages (direction, from_number, to_number, message, status, voipms_id)
            VALUES ('inbound', ?, ?, ?, 'received', ?)
        ");

        $stmt->execute([$from, $to, $message, $id]);

        log_webhook("SUCCESS: SMS stored - ID: " . $pdo->lastInsertId());

        // VoIP.ms expects 'ok' response to confirm receipt
        // If we don't send 'ok', it will retry every 30 minutes
        http_response_code(200);
        echo 'ok';

    } else {
        log_webhook("Invalid method: $method");
        http_response_code(405);
        echo 'Method not allowed';
    }

} catch (Exception $e) {
    log_webhook("Error: " . $e->getMessage());
    http_response_code(500);
    echo 'Internal server error';
}
