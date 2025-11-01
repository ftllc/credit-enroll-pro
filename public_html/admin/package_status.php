<?php
/**
 * Package Status Checker - Returns current status of package generation
 */

define('SNS_ENROLLMENT', true);
require_once __DIR__ . '/../src/config.php';

session_start();

// Check if logged in
if (!isset($_SESSION['staff_id']) || !isset($_SESSION['staff_logged_in'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

$test_package_id = intval($_GET['id'] ?? 0);

if ($test_package_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid package ID']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT id, xactosign_package_id, status, file_size, total_pages, error_message, completed_at FROM test_packages WHERE id = ?");
    $stmt->execute([$test_package_id]);
    $package = $stmt->fetch();

    if (!$package) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Package not found']);
        exit;
    }

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'status' => $package['status'],
        'xactosign_package_id' => $package['xactosign_package_id'],
        'file_size' => $package['file_size'],
        'total_pages' => $package['total_pages'],
        'error_message' => $package['error_message'],
        'completed_at' => $package['completed_at']
    ]);

} catch (Exception $e) {
    error_log("Error checking package status: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
