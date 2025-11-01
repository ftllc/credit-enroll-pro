<?php
/**
 * Get Inbound SMS Messages
 * Returns recent inbound SMS messages from the database
 */

// Prevent direct access without SNS_ENROLLMENT constant
define('SNS_ENROLLMENT', true);

// Load configuration
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

try {
    // Get limit from query parameter (default 50)
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 50;
    $limit = min(max($limit, 1), 500); // Between 1 and 500

    // Query for inbound messages
    $stmt = $pdo->prepare("
        SELECT
            id,
            from_number,
            to_number,
            message,
            status,
            DATE_FORMAT(created_at, '%Y-%m-%d %h:%i %p') as created_at
        FROM sms_messages
        WHERE direction = 'inbound'
        ORDER BY created_at DESC
        LIMIT ?
    ");

    $stmt->execute([$limit]);
    $messages = $stmt->fetchAll();

    echo json_encode([
        'status' => 'success',
        'count' => count($messages),
        'messages' => $messages
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => DEBUG_MODE ? $e->getMessage() : 'Failed to retrieve messages'
    ]);
}
