<?php
/**
 * Download Complete Enrollment Package
 */

define('SNS_ENROLLMENT', true);
require_once __DIR__ . '/../src/config.php';

session_start();

// Check if logged in
if (!isset($_SESSION['staff_id']) || !isset($_SESSION['staff_logged_in'])) {
    header('Location: login.php');
    exit;
}

$enrollment_id = intval($_GET['id'] ?? 0);

if (!$enrollment_id) {
    header('Location: panel.php');
    exit;
}

// Get enrollment with package
$stmt = $pdo->prepare("
    SELECT id, session_id, first_name, last_name, complete_package_pdf, package_file_size, xactosign_package_id
    FROM enrollment_users
    WHERE id = ? AND package_status = 'completed' AND complete_package_pdf IS NOT NULL
");
$stmt->execute([$enrollment_id]);
$enrollment = $stmt->fetch();

if (!$enrollment) {
    die('Package not found or not ready');
}

// Generate filename
$filename = sprintf(
    'Enrollment_Package_%s_%s_%s.pdf',
    $enrollment['session_id'],
    preg_replace('/[^A-Za-z0-9]/', '', $enrollment['first_name'] . $enrollment['last_name']),
    date('Y-m-d')
);

// Set headers for PDF download
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . $enrollment['package_file_size']);
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

// Output the PDF
echo $enrollment['complete_package_pdf'];
exit;
