<?php
/**
 * Example: How to integrate state contracts into your enrollment flow
 *
 * This file demonstrates how to retrieve and use state-specific contracts
 * in your application.
 */

define('SNS_ENROLLMENT', true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../state_contracts_helper.php';

// Example 1: Get contracts for a specific state during enrollment
function display_contracts_for_enrollment($pdo, $enrollment_id) {
    // Get enrollment data (including state)
    $stmt = $pdo->prepare("SELECT state FROM enrollment_users WHERE id = ?");
    $stmt->execute([$enrollment_id]);
    $enrollment = $stmt->fetch();

    if (!$enrollment) {
        return "Enrollment not found";
    }

    $state_code = $enrollment['state'];

    // Get the appropriate contract package for this state
    $package = get_contracts_for_state($pdo, $state_code);

    if (!$package) {
        return "No contracts available for this state";
    }

    // Check if all required contracts are present
    if (!has_all_contracts_for_state($pdo, $state_code)) {
        return "Warning: Not all contracts are available for " . $state_code;
    }

    // Access each contract
    $contracts = [
        'CROA Disclosure' => $package['documents']['croa_disclosure'] ?? null,
        'Client Agreement' => $package['documents']['client_agreement'] ?? null,
        'Notice of Cancellation' => $package['documents']['notice_of_cancellation'] ?? null
    ];

    // Display contract information
    echo "<h3>Contracts for {$state_code} ({$package['package_name']})</h3>";
    echo "<ul>";
    foreach ($contracts as $name => $doc) {
        if ($doc) {
            echo "<li>{$name}: {$doc['file_name']} (" . number_format($doc['file_size'] / 1024, 1) . " KB)</li>";
        } else {
            echo "<li>{$name}: <em>Not available</em></li>";
        }
    }
    echo "</ul>";
}

// Example 2: Generate a specific contract for download/signature
function generate_contract_for_signature($pdo, $enrollment_id, $contract_type) {
    // Get enrollment data
    $stmt = $pdo->prepare("SELECT state, first_name, last_name FROM enrollment_users WHERE id = ?");
    $stmt->execute([$enrollment_id]);
    $enrollment = $stmt->fetch();

    if (!$enrollment) {
        return null;
    }

    // Get the specific contract document
    $doc = get_contract_document($pdo, $enrollment['state'], $contract_type);

    if (!$doc) {
        error_log("Contract not found: {$contract_type} for state {$enrollment['state']}");
        return null;
    }

    // Verify integrity before serving
    if (!verify_contract_integrity($doc['contract_pdf'], $doc['pdf_hash'])) {
        error_log("Contract integrity check failed for doc ID: {$doc['id']}");
        return null;
    }

    // Return the contract data
    return [
        'pdf_content' => $doc['contract_pdf'],
        'filename' => $doc['file_name'],
        'mime_type' => $doc['mime_type']
    ];
}

// Example 3: Serve a contract PDF to the browser
function serve_contract_pdf($pdo, $enrollment_id, $contract_type) {
    $contract = generate_contract_for_signature($pdo, $enrollment_id, $contract_type);

    if (!$contract) {
        http_response_code(404);
        die('Contract not found');
    }

    // Set headers
    header('Content-Type: ' . $contract['mime_type']);
    header('Content-Disposition: inline; filename="' . $contract['filename'] . '"');
    header('Content-Length: ' . strlen($contract['pdf_content']));
    header('Cache-Control: private, max-age=0, must-revalidate');

    // Output PDF
    echo $contract['pdf_content'];
    exit;
}

// Example 4: Store signed contract
function store_signed_contract($pdo, $enrollment_id, $contract_type, $signature_data) {
    try {
        // Get the contract package for this enrollment's state
        $stmt = $pdo->prepare("SELECT state FROM enrollment_users WHERE id = ?");
        $stmt->execute([$enrollment_id]);
        $enrollment = $stmt->fetch();

        if (!$enrollment) {
            throw new Exception("Enrollment not found");
        }

        // Get the contract document
        $doc = get_contract_document($pdo, $enrollment['state'], $contract_type);

        if (!$doc) {
            throw new Exception("Contract not found for state {$enrollment['state']}");
        }

        // Map contract type names
        $contract_type_map = [
            'croa_disclosure' => 'croa',
            'client_agreement' => 'client_agreement',
            'notice_of_cancellation' => 'notice_of_cancellation'
        ];

        $db_contract_type = $contract_type_map[$contract_type] ?? $contract_type;

        // Store in the contracts table
        $stmt = $pdo->prepare("
            INSERT INTO contracts (
                enrollment_id,
                contract_type,
                contract_pdf,
                contract_hash,
                signed,
                signature_data,
                signed_at,
                signed_by,
                ip_address
            ) VALUES (?, ?, ?, ?, 1, ?, NOW(), ?, ?)
        ");

        $stmt->execute([
            $enrollment_id,
            $db_contract_type,
            $doc['contract_pdf'],
            $doc['pdf_hash'],
            $signature_data,
            'Enrollment User',
            get_client_ip()
        ]);

        return true;

    } catch (Exception $e) {
        error_log("Error storing signed contract: " . $e->getMessage());
        return false;
    }
}

// Example 5: Check if enrollment has all contracts signed
function check_contracts_complete($pdo, $enrollment_id) {
    $required_contracts = ['croa', 'client_agreement', 'notice_of_cancellation'];

    foreach ($required_contracts as $type) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM contracts
            WHERE enrollment_id = ? AND contract_type = ? AND signed = 1
        ");
        $stmt->execute([$enrollment_id, $type]);

        if ($stmt->fetchColumn() == 0) {
            return false;
        }
    }

    return true;
}

// Example 6: Get list of all states that have contracts configured
function get_available_contract_states($pdo) {
    $states_with_contracts = get_states_with_contracts($pdo);

    // Also include states that would use the default package
    $stmt = $pdo->query("SELECT is_default FROM state_contract_packages WHERE is_default = 1");
    $has_default = $stmt->fetchColumn() !== false;

    return [
        'states_with_specific_contracts' => $states_with_contracts,
        'has_default_package' => $has_default,
        'message' => $has_default
            ? 'All states are supported (using default or state-specific contracts)'
            : 'Only specific states are supported: ' . implode(', ', $states_with_contracts)
    ];
}

/*
 * USAGE IN YOUR ENROLLMENT FLOW:
 *
 * 1. When user reaches contract signing step:
 *    - Check if contracts exist: has_all_contracts_for_state($pdo, $state_code)
 *    - Get contracts: $package = get_contracts_for_state($pdo, $state_code)
 *
 * 2. Display contracts for user to review:
 *    - Use iframe or embed to show: /view_contract.php?enrollment_id=X&type=croa_disclosure
 *
 * 3. After user signs:
 *    - Store signature: store_signed_contract($pdo, $enrollment_id, $type, $signature_data)
 *
 * 4. Verify all contracts signed:
 *    - check_contracts_complete($pdo, $enrollment_id)
 */
