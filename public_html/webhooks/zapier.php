<?php
/**
 * Zapier API Authentication Endpoint
 *
 * This endpoint validates Zapier API credentials and returns
 * basic account information for connection labeling.
 *
 * Expected authentication:
 * - Header: X-API-Key: zpk_xxxxx
 * - OR URL param: ?api_key=zpk_xxxxx
 */

define('SNS_ENROLLMENT', true);
require_once __DIR__ . '/../src/config.php';

// Set JSON response header
header('Content-Type: application/json');

// Enable CORS for Zapier
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

/**
 * Send JSON response
 */
function send_response($success, $data = [], $message = '', $http_code = 200) {
    http_response_code($http_code);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);
    exit;
}

/**
 * Get API key from request
 */
function get_api_key() {
    // Try to get from Authorization header (Bearer token)
    $headers = getallheaders();
    if (isset($headers['Authorization'])) {
        $auth = $headers['Authorization'];
        if (preg_match('/Bearer\s+(.+)/', $auth, $matches)) {
            return trim($matches[1]);
        }
    }

    // Try to get from X-API-Key header
    if (isset($headers['X-API-Key'])) {
        return trim($headers['X-API-Key']);
    }

    // Try to get from URL parameter
    if (isset($_GET['api_key'])) {
        return trim($_GET['api_key']);
    }

    // Try to get from POST body
    if (isset($_POST['api_key'])) {
        return trim($_POST['api_key']);
    }

    return null;
}

/**
 * Validate API key against database
 */
function validate_api_key($api_key) {
    global $pdo;

    if (empty($api_key)) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT id, service_name, is_enabled, additional_config
            FROM api_keys
            WHERE service_name = 'zapier'
            LIMIT 1
        ");
        $stmt->execute();
        $result = $stmt->fetch();

        if (!$result || !$result['is_enabled']) {
            return false;
        }

        $config = json_decode($result['additional_config'], true);
        $stored_api_key = $config['api_key'] ?? '';

        // Constant-time string comparison to prevent timing attacks
        if (hash_equals($stored_api_key, $api_key)) {
            return true;
        }

        return false;
    } catch (PDOException $e) {
        log_activity("Zapier API key validation error: " . $e->getMessage(), 'ERROR');
        return false;
    }
}

// Only allow GET requests for authentication test
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_response(false, [], 'Method not allowed. Use GET for authentication test.', 405);
}

// Get API key from request
$api_key = get_api_key();

if (empty($api_key)) {
    send_response(false, [], 'API key is required. Provide via X-API-Key header or api_key parameter.', 401);
}

// Validate API key
if (!validate_api_key($api_key)) {
    send_response(false, [], 'Invalid or disabled API key.', 401);
}

// Get company settings for connection label
try {
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'company_name' LIMIT 1");
    $stmt->execute();
    $company_name = $stmt->fetchColumn() ?: COMPANY_NAME;
} catch (PDOException $e) {
    $company_name = COMPANY_NAME;
}

// Authentication successful - return account info directly (no wrapper)
// Zapier expects the fields at root level for connection label {{bundle.inputData.field}}
http_response_code(200);
echo json_encode([
    'account_name' => $company_name,
    'install_url' => BASE_URL,
    'integration' => 'Credit Enroll Pro',
    'version' => '1.0.0',
    'authenticated' => true
]);
exit;
