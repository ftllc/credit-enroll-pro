<?php
/**
 * XactoAuth Webhook Endpoint
 * Handles logout events from XactoAuth ("Sign Out of All Apps" functionality)
 */

define('SNS_ENROLLMENT', true);
require_once __DIR__ . '/../src/config.php';

// Get raw POST data
$raw_payload = file_get_contents('php://input');

// Log webhook received
log_activity("XactoAuth webhook received: " . substr($raw_payload, 0, 200), 'INFO');

// Verify webhook signature
$credentials = get_xactoauth_credentials();

if (!$credentials) {
    http_response_code(500);
    log_activity("XactoAuth webhook: credentials not configured", 'ERROR');
    die(json_encode(['error' => 'XactoAuth not configured']));
}

// Verify webhook signature
$received_signature = $_SERVER['HTTP_X_XACTOAUTH_SIGNATURE'] ??
                     $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ??
                     $_SERVER['HTTP_X_SIGNATURE'] ?? '';

// IMPORTANT: Use webhook_secret, NOT api_secret!
// XactoAuth signs webhooks with webhook_secret: hash_hmac('sha256', json_encode($payload), $webhook_secret)
$webhook_secret = $credentials['webhook_secret'];

if (!$webhook_secret) {
    http_response_code(500);
    log_activity("XactoAuth webhook: webhook_secret not configured", 'ERROR');
    die(json_encode(['error' => 'Webhook secret not configured']));
}

// Calculate HMAC-SHA256 signature of raw payload using webhook_secret
$calculated_signature = hash_hmac('sha256', $raw_payload, $webhook_secret);

// Log signatures for debugging
log_activity("XactoAuth webhook signature check:", 'INFO');
log_activity("  Received: {$received_signature}", 'INFO');
log_activity("  Calculated: {$calculated_signature}", 'INFO');
log_activity("  Secret used: {$webhook_secret}", 'INFO');
log_activity("  Payload: {$raw_payload}", 'INFO');

if (!empty($received_signature) && !hash_equals($calculated_signature, $received_signature)) {
    http_response_code(401);
    log_activity("XactoAuth webhook signature verification FAILED - rejecting", 'ERROR');
    die(json_encode([
        'error' => 'Invalid signature',
        'expected' => $calculated_signature,
        'received' => $received_signature
    ]));
}

// Parse payload
$payload = json_decode($raw_payload, true);

if (!$payload) {
    http_response_code(400);
    die(json_encode(['error' => 'Invalid JSON payload']));
}

// Handle different event types
$event_type = $payload['event'] ?? '';

switch ($event_type) {
    case 'user.logout':
        // Get user ID from payload
        $xactoauth_user_id = $payload['data']['user_id'] ?? $payload['data']['identity_id'] ?? null;

        if (!$xactoauth_user_id) {
            http_response_code(400);
            die(json_encode(['error' => 'Missing user_id or identity_id']));
        }

        log_activity("XactoAuth logout webhook received for user: {$xactoauth_user_id}", 'INFO');

        // Find all sessions for this user
        $session_path = session_save_path();
        if (empty($session_path)) {
            $session_path = '/var/lib/php/sessions';
            if (!is_dir($session_path)) {
                $session_path = '/tmp';
            }
        }

        log_activity("XactoAuth logout: searching sessions in {$session_path}", 'INFO');

        $session_files = @glob($session_path . '/sess_*');
        if ($session_files === false) {
            log_activity("XactoAuth logout: failed to read session directory", 'ERROR');
            $session_files = [];
        }

        $destroyed_count = 0;
        $checked_count = 0;

        foreach ($session_files as $session_file) {
            $checked_count++;
            $session_data = @file_get_contents($session_file);

            if ($session_data === false) {
                continue; // Can't read this session file
            }

            // Check if this session belongs to the logged-out user
            // Look for both xactoauth_user_id in staff table and xactoauth_identity_id in session
            if (strpos($session_data, $xactoauth_user_id) !== false) {
                if (@unlink($session_file)) {
                    $destroyed_count++;
                    log_activity("XactoAuth logout: destroyed session file " . basename($session_file), 'INFO');
                } else {
                    log_activity("XactoAuth logout: failed to delete session file " . basename($session_file), 'WARNING');
                }
            }
        }

        log_activity("XactoAuth logout webhook: checked {$checked_count} sessions, destroyed {$destroyed_count} for user {$xactoauth_user_id}", 'INFO');

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'sessions_checked' => $checked_count,
            'sessions_destroyed' => $destroyed_count,
            'user_id' => $xactoauth_user_id
        ]);
        break;

    case 'user.updated':
        // Handle user profile updates
        $xactoauth_user_id = $payload['data']['user_id'] ?? $payload['data']['identity_id'] ?? null;
        $updated_data = $payload['data'] ?? [];

        if ($xactoauth_user_id) {
            // Update local user data if needed
            $full_name = trim(($updated_data['first_name'] ?? '') . ' ' . ($updated_data['last_name'] ?? ''));
            if (empty($full_name)) {
                $full_name = $updated_data['display_name'] ?? '';
            }

            if (!empty($updated_data['email']) && !empty($full_name)) {
                $stmt = $pdo->prepare("UPDATE staff SET email = ?, full_name = ? WHERE xactoauth_user_id = ?");
                $stmt->execute([
                    $updated_data['email'],
                    $full_name,
                    $xactoauth_user_id
                ]);
                log_activity("XactoAuth user updated webhook for user {$xactoauth_user_id}: email and name updated", 'INFO');
            }
        }

        http_response_code(200);
        echo json_encode(['success' => true]);
        break;

    default:
        // Unknown event type
        log_activity("XactoAuth webhook received unknown event type: {$event_type}", 'WARNING');

        http_response_code(200);
        echo json_encode(['success' => true, 'message' => 'Event type not handled']);
        break;
}
