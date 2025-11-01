<?php
/**
 * Download Complete Enrollment Package
 */

define('SNS_ENROLLMENT', true);
require_once __DIR__ . '/../src/config.php';

session_start();

if (!isset($_SESSION['enrollment_session_id'])) {
    http_response_code(403);
    die('Access denied');
}

try {
    $stmt = $pdo->prepare("SELECT * FROM enrollment_users WHERE session_id = ?");
    $stmt->execute([$_SESSION['enrollment_session_id']]);
    $enrollment = $stmt->fetch();

    if (!$enrollment) {
        http_response_code(404);
        die('Enrollment not found');
    }

    if ($enrollment['package_status'] !== 'completed') {
        http_response_code(400);
        die('Package not ready for download');
    }

    if (empty($enrollment['complete_package_pdf'])) {
        http_response_code(404);
        die('Package PDF not found');
    }

    // Set headers for PDF inline viewing (opens in new tab)
    $filename = 'Contract_Package_' . $enrollment['xactosign_package_id'] . '.pdf';

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Content-Length: ' . $enrollment['package_file_size']);
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');

    echo $enrollment['complete_package_pdf'];
    exit;

} catch (Exception $e) {
    error_log("Error downloading package: " . $e->getMessage());
    http_response_code(500);
    die('Error downloading package');
}
