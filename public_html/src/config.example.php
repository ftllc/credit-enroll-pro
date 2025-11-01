<?php
/**
 * Credit Enroll Pro - Configuration File
 *
 * IMPORTANT: Copy this file to config.php and fill in your actual values
 * Never commit config.php to version control
 */

// Prevent direct access
if (!defined('SNS_ENROLLMENT')) {
    die('Direct access not permitted');
}

// ============================================================================
// ENVIRONMENT
// ============================================================================
define('ENVIRONMENT', 'production'); // development, staging, or production
define('DEBUG_MODE', false); // Set to true for development only

// ============================================================================
// COMPANY INFORMATION
// ============================================================================
define('COMPANY_NAME', 'Your Company Name');
define('COMPANY_DOMAIN', 'yourdomain.com'); // Change to your actual domain
define('BASE_URL', 'https://' . COMPANY_DOMAIN);

// ============================================================================
// DATABASE CONFIGURATION
// ============================================================================
define('DB_HOST', 'localhost'); // Your database host (usually 'localhost')
define('DB_NAME', 'your_database_name'); // Your MySQL database name
define('DB_USER', 'your_database_user'); // Your MySQL username
define('DB_PASS', 'YOUR_DATABASE_PASSWORD_HERE'); // Your MySQL password
define('DB_CHARSET', 'utf8mb4');

// ============================================================================
// SECURITY & ENCRYPTION
// ============================================================================
// Generate a secure random key: openssl_rand_pseudo_bytes(32)
// Store as base64: base64_encode($key)
define('ENCRYPTION_KEY', 'YOUR_32_BYTE_ENCRYPTION_KEY_HERE_BASE64_ENCODED');
define('ENCRYPTION_METHOD', 'AES-256-CBC');

// Session security
define('SESSION_LIFETIME', 7200); // 2 hours in seconds
define('SESSION_NAME', 'SNS_ENROLL_SESSION');

// Password hashing
define('PASSWORD_HASH_ALGO', PASSWORD_ARGON2ID);

// ============================================================================
// reCAPTCHA ENTERPRISE CONFIGURATION
// ============================================================================
define('RECAPTCHA_ENABLED', true);
define('RECAPTCHA_SITE_KEY', 'YOUR_RECAPTCHA_SITE_KEY');
define('RECAPTCHA_SECRET_KEY', 'YOUR_RECAPTCHA_SECRET_KEY');
define('RECAPTCHA_PROJECT_ID', 'YOUR_GOOGLE_CLOUD_PROJECT_ID');

// ============================================================================
// GOOGLE MAPS API
// ============================================================================
define('GOOGLE_MAPS_ENABLED', true);
define('GOOGLE_MAPS_API_KEY', 'YOUR_GOOGLE_MAPS_API_KEY');

// ============================================================================
// GOOGLE ANALYTICS
// ============================================================================
define('GA_ENABLED', false);
define('GA_TRACKING_ID', 'G-XXXXXXXXXX');

// ============================================================================
// VOIP.MS CONFIGURATION
// ============================================================================
define('VOIPMS_ENABLED', false);
define('VOIPMS_API_USER', 'YOUR_VOIPMS_USERNAME');
define('VOIPMS_API_PASS', 'YOUR_VOIPMS_PASSWORD');
define('VOIPMS_DID', 'YOUR_PHONE_NUMBER'); // Format: 1234567890

// ============================================================================
// MAILERSEND CONFIGURATION
// ============================================================================
define('MAILERSEND_ENABLED', false);
define('MAILERSEND_API_TOKEN', 'YOUR_MAILERSEND_API_TOKEN');
define('MAILERSEND_FROM_EMAIL', 'noreply@yourdomain.com');
define('MAILERSEND_FROM_NAME', COMPANY_NAME);

// ============================================================================
// CREDIT REPAIR CLOUD API
// ============================================================================
define('CRC_ENABLED', false);
define('CRC_API_AUTH_KEY', 'YOUR_CRC_AUTH_KEY');
define('CRC_API_SECRET_KEY', 'YOUR_CRC_SECRET_KEY');

// ============================================================================
// ZOHO BOOKS API
// ============================================================================
define('ZOHO_ENABLED', false);
define('ZOHO_CLIENT_ID', 'YOUR_ZOHO_CLIENT_ID');
define('ZOHO_CLIENT_SECRET', 'YOUR_ZOHO_CLIENT_SECRET');
define('ZOHO_REFRESH_TOKEN', 'YOUR_ZOHO_REFRESH_TOKEN');
define('ZOHO_ORGANIZATION_ID', 'YOUR_ZOHO_ORG_ID');

// ============================================================================
// SYSTEME.IO API
// ============================================================================
define('SYSTEME_ENABLED', false);
define('SYSTEME_API_KEY', 'YOUR_SYSTEME_API_KEY');

// ============================================================================
// FILE PATHS
// ============================================================================
define('ROOT_PATH', dirname(__DIR__));
define('SRC_PATH', ROOT_PATH . '/src');
define('AGREEMENTS_PATH', SRC_PATH . '/agreements');
define('IMG_PATH', SRC_PATH . '/img');

// ============================================================================
// ENROLLMENT SETTINGS
// ============================================================================
define('ENROLLMENT_ENABLED', true);
define('SESSION_TIMEOUT_HOURS', 72);
define('STEP_TIMEOUT_MINUTES', 5);
define('MAX_2FA_ATTEMPTS', 5);
define('2FA_CODE_EXPIRY_MINUTES', 15);

// ============================================================================
// TIMEZONE
// ============================================================================
date_default_timezone_set('America/Chicago');

// ============================================================================
// ERROR REPORTING
// ============================================================================
if (ENVIRONMENT === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    ini_set('error_log', ROOT_PATH . '/logs/error.log');
}

// ============================================================================
// DATABASE CONNECTION
// ============================================================================
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET
    ];

    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (PDOException $e) {
    if (DEBUG_MODE) {
        die("Database connection failed: " . $e->getMessage());
    } else {
        die("System temporarily unavailable. Please try again later.");
    }
}

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

/**
 * Encrypt sensitive data
 */
function encrypt_data($data) {
    $key = base64_decode(ENCRYPTION_KEY);
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length(ENCRYPTION_METHOD));
    $encrypted = openssl_encrypt($data, ENCRYPTION_METHOD, $key, 0, $iv);
    return base64_encode($encrypted . '::' . $iv);
}

/**
 * Decrypt sensitive data
 */
function decrypt_data($data) {
    $key = base64_decode(ENCRYPTION_KEY);
    list($encrypted_data, $iv) = explode('::', base64_decode($data), 2);
    return openssl_decrypt($encrypted_data, ENCRYPTION_METHOD, $key, 0, $iv);
}

/**
 * Generate unique session ID (format: ABCD-1234)
 */
function generate_session_id() {
    global $pdo;

    do {
        $part1 = strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
        $part2 = strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
        $session_id = $part1 . '-' . $part2;

        // Check if session ID already exists
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM enrollment_users WHERE session_id = ?");
        $stmt->execute([$session_id]);
        $exists = $stmt->fetchColumn() > 0;
    } while ($exists);

    return $session_id;
}

/**
 * Generate 2FA code
 */
function generate_2fa_code($length = 6) {
    return str_pad(random_int(0, pow(10, $length) - 1), $length, '0', STR_PAD_LEFT);
}

/**
 * Get client IP address
 */
function get_client_ip() {
    $ip_keys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED',
                'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'];

    foreach ($ip_keys as $key) {
        if (array_key_exists($key, $_SERVER) === true) {
            foreach (explode(',', $_SERVER[$key]) as $ip) {
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                    return $ip;
                }
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Sanitize input
 */
function sanitize_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

/**
 * Format phone number
 */
function format_phone($phone) {
    $phone = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($phone) == 10) {
        return preg_replace('/(\d{3})(\d{3})(\d{4})/', '($1) $2-$3', $phone);
    }
    return $phone;
}

/**
 * Clean phone for SMS (digits only)
 */
function clean_phone($phone) {
    return preg_replace('/[^0-9]/', '', $phone);
}

/**
 * Get referring domain
 */
function get_referring_domain() {
    if (isset($_SERVER['HTTP_REFERER'])) {
        $url = parse_url($_SERVER['HTTP_REFERER']);
        return $url['host'] ?? null;
    }
    return null;
}

/**
 * Strip Facebook tracking parameters
 */
function clean_url($url) {
    $fb_params = ['fbclid', 'gclid', 'msclkid', 'mc_cid', 'mc_eid'];
    $parsed = parse_url($url);

    if (isset($parsed['query'])) {
        parse_str($parsed['query'], $query_params);
        foreach ($fb_params as $param) {
            unset($query_params[$param]);
        }
        $parsed['query'] = http_build_query($query_params);
    }

    return (isset($parsed['scheme']) ? $parsed['scheme'] . '://' : '') .
           (isset($parsed['host']) ? $parsed['host'] : '') .
           (isset($parsed['path']) ? $parsed['path'] : '') .
           (isset($parsed['query']) && $parsed['query'] ? '?' . $parsed['query'] : '');
}

/**
 * Log activity
 */
function log_activity($message, $level = 'INFO') {
    if (DEBUG_MODE) {
        $log_file = ROOT_PATH . '/logs/activity.log';
        $timestamp = date('Y-m-d H:i:s');
        $log_message = "[$timestamp] [$level] $message" . PHP_EOL;
        file_put_contents($log_file, $log_message, FILE_APPEND);
    }
}
