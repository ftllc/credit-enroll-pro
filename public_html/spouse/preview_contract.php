<?php
/**
 * Spouse Contract Preview Generator
 * Generates PDF previews of contracts with spouse information
 */

define('SNS_ENROLLMENT', true);
require_once __DIR__ . '/../src/config.php';

// Get parameters
$contract_type = $_GET['type'] ?? '';
$session_id = $_GET['session'] ?? '';

if (empty($contract_type) || empty($session_id)) {
    die('Invalid parameters');
}

// Get spouse enrollment
$stmt = $pdo->prepare("SELECT * FROM spouse_enrollments WHERE session_id = ?");
$stmt->execute([$session_id]);
$spouse_enrollment = $stmt->fetch();

if (!$spouse_enrollment) {
    die('Spouse enrollment not found');
}

// Get primary enrollment
$stmt = $pdo->prepare("SELECT * FROM enrollment_users WHERE id = ?");
$stmt->execute([$spouse_enrollment['primary_enrollment_id']]);
$enrollment = $stmt->fetch();

if (!$enrollment) {
    die('Primary enrollment not found');
}

// Get contract package
$package = null;
if (!empty($enrollment['state'])) {
    $stmt = $pdo->prepare("
        SELECT scp.*
        FROM state_contract_packages scp
        INNER JOIN state_contract_mappings scm ON scp.id = scm.package_id
        WHERE scm.state_code = ?
        LIMIT 1
    ");
    $stmt->execute([$enrollment['state']]);
    $package = $stmt->fetch();
}

if (!$package) {
    $stmt = $pdo->prepare("SELECT * FROM state_contract_packages WHERE is_default = 1 LIMIT 1");
    $stmt->execute();
    $package = $stmt->fetch();
}

if (!$package) {
    die('No contract package found');
}

// Get the appropriate contract document based on package_id and contract_type
$db_contract_type = null;
if ($contract_type === 'croa') {
    $db_contract_type = 'croa_disclosure';
} elseif ($contract_type === 'agreement') {
    $db_contract_type = 'client_agreement';
} else {
    die('Invalid contract type');
}

// Get the document from state_contract_documents table
$stmt = $pdo->prepare("
    SELECT *
    FROM state_contract_documents
    WHERE package_id = ? AND contract_type = ?
    LIMIT 1
");
$stmt->execute([$package['id'], $db_contract_type]);
$document = $stmt->fetch();

if (!$document || empty($document['contract_pdf'])) {
    die('Contract document not found for package ID ' . $package['id'] . ' and type ' . $db_contract_type);
}

// Use spouse information for substitution
$substitutions = [
    '{{first_name}}' => $enrollment['spouse_first_name'],
    '{{last_name}}' => $enrollment['spouse_last_name'],
    '{{full_name}}' => $enrollment['spouse_first_name'] . ' ' . $enrollment['spouse_last_name'],
    '{{email}}' => $enrollment['spouse_email'],
    '{{phone}}' => format_phone($enrollment['spouse_phone']),
    '{{address}}' => $enrollment['address_line1'],
    '{{address_line1}}' => $enrollment['address_line1'],
    '{{address_line2}}' => $enrollment['address_line2'] ?? '',
    '{{city}}' => $enrollment['city'],
    '{{state}}' => $enrollment['state'],
    '{{zip}}' => $enrollment['zip_code'],
    '{{date}}' => date('F j, Y'),
    '{{company_name}}' => COMPANY_NAME
];

// For now, just serve the PDF directly
// In a production environment, you would:
// 1. Use a PDF library to fill in form fields
// 2. Add "SIGNATURE PENDING" watermarks
// 3. Cache the generated PDF

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="contract_preview.pdf"');
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

echo $document['contract_pdf'];
