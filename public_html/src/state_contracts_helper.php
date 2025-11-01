<?php
/**
 * State Contracts Helper Functions
 *
 * Provides functions to retrieve appropriate state-specific contracts
 */

if (!defined('SNS_ENROLLMENT')) {
    die('Direct access not permitted');
}

/**
 * Get the appropriate contract package for a given state
 *
 * @param PDO $pdo Database connection
 * @param string $state_code Two-letter state code (e.g., 'TX', 'CA')
 * @return array|null Contract package data with documents, or null if not found
 */
function get_contracts_for_state($pdo, $state_code) {
    try {
        // First, try to find a package assigned to this specific state
        $stmt = $pdo->prepare("
            SELECT
                scp.id,
                scp.package_name,
                scp.is_default
            FROM state_contract_packages scp
            JOIN state_contract_mappings scm ON scp.id = scm.package_id
            WHERE scm.state_code = ?
            LIMIT 1
        ");
        $stmt->execute([$state_code]);
        $package = $stmt->fetch();

        // If no state-specific package found, get the default package
        if (!$package) {
            $stmt = $pdo->prepare("
                SELECT
                    id,
                    package_name,
                    is_default
                FROM state_contract_packages
                WHERE is_default = 1
                LIMIT 1
            ");
            $stmt->execute();
            $package = $stmt->fetch();
        }

        if (!$package) {
            return null;
        }

        // Fetch all three contract documents for this package
        $stmt = $pdo->prepare("
            SELECT
                id,
                contract_type,
                contract_pdf,
                file_name,
                file_size,
                mime_type,
                pdf_hash,
                uploaded_at
            FROM state_contract_documents
            WHERE package_id = ?
        ");
        $stmt->execute([$package['id']]);

        $documents = [];
        while ($doc = $stmt->fetch()) {
            $documents[$doc['contract_type']] = $doc;
        }

        $package['documents'] = $documents;

        return $package;

    } catch (PDOException $e) {
        error_log("Error fetching contracts for state $state_code: " . $e->getMessage());
        return null;
    }
}

/**
 * Get a specific contract document by type for a given state
 *
 * @param PDO $pdo Database connection
 * @param string $state_code Two-letter state code
 * @param string $contract_type One of: 'croa_disclosure', 'client_agreement', 'notice_of_cancellation'
 * @return array|null Document data, or null if not found
 */
function get_contract_document($pdo, $state_code, $contract_type) {
    $package = get_contracts_for_state($pdo, $state_code);

    if (!$package || !isset($package['documents'][$contract_type])) {
        return null;
    }

    return $package['documents'][$contract_type];
}

/**
 * Check if all three required contracts exist for a given state
 *
 * @param PDO $pdo Database connection
 * @param string $state_code Two-letter state code
 * @return bool True if all contracts exist, false otherwise
 */
function has_all_contracts_for_state($pdo, $state_code) {
    $package = get_contracts_for_state($pdo, $state_code);

    if (!$package) {
        return false;
    }

    $required_types = ['croa_disclosure', 'client_agreement', 'notice_of_cancellation'];

    foreach ($required_types as $type) {
        if (!isset($package['documents'][$type])) {
            return false;
        }
    }

    return true;
}

/**
 * Get all states that have contract assignments
 *
 * @param PDO $pdo Database connection
 * @return array Array of state codes with assigned contracts
 */
function get_states_with_contracts($pdo) {
    try {
        $stmt = $pdo->query("
            SELECT DISTINCT state_code
            FROM state_contract_mappings
            ORDER BY state_code
        ");

        return $stmt->fetchAll(PDO::FETCH_COLUMN);

    } catch (PDOException $e) {
        error_log("Error fetching states with contracts: " . $e->getMessage());
        return [];
    }
}

/**
 * Verify PDF integrity
 *
 * @param string $pdf_content The PDF content
 * @param string $stored_hash The stored hash
 * @return bool True if hashes match, false otherwise
 */
function verify_contract_integrity($pdf_content, $stored_hash) {
    $calculated_hash = hash('sha256', $pdf_content);
    return $calculated_hash === $stored_hash;
}
