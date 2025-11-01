<?php
/**
 * Download Complete Spouse Enrollment Package
 */

define('SNS_ENROLLMENT', true);
require_once __DIR__ . '/../src/config.php';

session_start();

if (!isset($_SESSION['spouse_enrollment_session_id'])) {
    http_response_code(403);
    die('Access denied');
}

try {
    $stmt = $pdo->prepare("SELECT * FROM spouse_enrollments WHERE session_id = ?");
    $stmt->execute([$_SESSION['spouse_enrollment_session_id']]);
    $spouse_enrollment = $stmt->fetch();

    if (!$spouse_enrollment) {
        http_response_code(404);
        die('Spouse enrollment not found');
    }

    if ($spouse_enrollment['package_status'] !== 'completed') {
        http_response_code(400);
        die('Package not ready for download');
    }

    if (empty($spouse_enrollment['complete_package_pdf'])) {
        http_response_code(404);
        die('Package PDF not found');
    }

    // Set headers for PDF inline viewing (opens in new tab)
    $filename = 'Spouse_Contract_Package_' . $spouse_enrollment['spouse_xactosign_package_id'] . '.pdf';

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Content-Length: ' . $spouse_enrollment['package_file_size']);
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');

    echo $spouse_enrollment['complete_package_pdf'];
    exit;

} catch (Exception $e) {
    error_log("Error downloading spouse package: " . $e->getMessage());
    http_response_code(500);
    die('Error downloading package');
}
