<?php
/**
 * View Package - Serves the generated PDF from database
 */

define('SNS_ENROLLMENT', true);
require_once __DIR__ . '/../src/config.php';

session_start();

// Check if logged in
if (!isset($_SESSION['staff_id']) || !isset($_SESSION['staff_logged_in'])) {
    http_response_code(403);
    die('Access denied');
}

$test_package_id = intval($_GET['id'] ?? 0);

if ($test_package_id <= 0) {
    http_response_code(400);
    die('Invalid package ID');
}

try {
    $stmt = $pdo->prepare("SELECT xactosign_package_id, pdf_data, file_size, status FROM test_packages WHERE id = ?");
    $stmt->execute([$test_package_id]);
    $package = $stmt->fetch();

    if (!$package) {
        http_response_code(404);
        die('Package not found');
    }

    if ($package['status'] !== 'completed') {
        http_response_code(400);
        die('Package not ready. Status: ' . $package['status']);
    }

    if (empty($package['pdf_data'])) {
        http_response_code(500);
        die('Package PDF data is missing');
    }

    // Set headers for PDF display
    $filename = 'TEST_PACKAGE_' . $package['xactosign_package_id'] . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Content-Length: ' . $package['file_size']);
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    // Output PDF
    echo $package['pdf_data'];
    exit;

} catch (Exception $e) {
    error_log("Error viewing package: " . $e->getMessage());
    http_response_code(500);
    die('Error: ' . htmlspecialchars($e->getMessage()));
}
