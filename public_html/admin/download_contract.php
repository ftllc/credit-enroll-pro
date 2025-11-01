<?php
/**
 * Download/View Contract PDF
 */

define('SNS_ENROLLMENT', true);
require_once __DIR__ . '/../src/config.php';

session_start();

// Check if logged in
if (!isset($_SESSION['staff_id']) || !isset($_SESSION['staff_logged_in'])) {
    http_response_code(403);
    die('Access denied');
}

// Get staff info
$stmt = $pdo->prepare("SELECT * FROM staff WHERE id = ?");
$stmt->execute([$_SESSION['staff_id']]);
$staff = $stmt->fetch();

if (!$staff || !$staff['is_active'] || $staff['role'] !== 'admin') {
    http_response_code(403);
    die('Access denied');
}

// Get document ID
$doc_id = intval($_GET['doc_id'] ?? 0);

if ($doc_id <= 0) {
    http_response_code(400);
    die('Invalid document ID');
}

try {
    // Fetch document
    $stmt = $pdo->prepare("
        SELECT
            scd.contract_pdf,
            scd.file_name,
            scd.mime_type,
            scd.pdf_hash,
            scp.package_name
        FROM state_contract_documents scd
        JOIN state_contract_packages scp ON scd.package_id = scp.id
        WHERE scd.id = ?
    ");
    $stmt->execute([$doc_id]);
    $doc = $stmt->fetch();

    if (!$doc) {
        http_response_code(404);
        die('Document not found');
    }

    // Verify hash integrity
    $calculated_hash = hash('sha256', $doc['contract_pdf']);
    if ($calculated_hash !== $doc['pdf_hash']) {
        error_log("Contract PDF hash mismatch for doc_id: $doc_id");
        http_response_code(500);
        die('Document integrity check failed');
    }

    // Set headers for PDF display
    header('Content-Type: ' . $doc['mime_type']);
    header('Content-Disposition: inline; filename="' . $doc['file_name'] . '"');
    header('Content-Length: ' . strlen($doc['contract_pdf']));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');

    // Output PDF
    echo $doc['contract_pdf'];
    exit;

} catch (PDOException $e) {
    error_log("Error fetching contract: " . $e->getMessage());
    http_response_code(500);
    die('Error retrieving document');
}
