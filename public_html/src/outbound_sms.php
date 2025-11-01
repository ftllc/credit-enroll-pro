<?php
/**
 * VoIP.ms Outbound SMS Handler
 *
 * Handles sending SMS messages through VoIP.ms API
 * Automatically splits messages >140 characters, sending URLs separately
 */

// Prevent direct access without SNS_ENROLLMENT constant
define('SNS_ENROLLMENT', true);

// Load configuration
require_once __DIR__ . '/config.php';

/**
 * Split message into SMS-safe chunks
 * - Single message max: 140 chars (conservative, VoIP.ms allows 160)
 * - URLs are sent separately
 * - Splits on word boundaries
 *
 * @param string $message The message to split
 * @return array Array of message chunks
 */
function split_sms_message($message) {
    $max_length = 140;
    $chunks = [];

    // Extract URLs from message
    $url_pattern = '/https?:\/\/[^\s]+/i';
    preg_match_all($url_pattern, $message, $url_matches);
    $urls = $url_matches[0];

    // Remove URLs from message
    $text_without_urls = preg_replace($url_pattern, '', $message);
    $text_without_urls = trim(preg_replace('/\s+/', ' ', $text_without_urls));

    // Split text into chunks if needed
    if (!empty($text_without_urls)) {
        if (strlen($text_without_urls) <= $max_length) {
            $chunks[] = $text_without_urls;
        } else {
            // Split on word boundaries
            $words = explode(' ', $text_without_urls);
            $current_chunk = '';

            foreach ($words as $word) {
                $test_chunk = empty($current_chunk) ? $word : $current_chunk . ' ' . $word;

                if (strlen($test_chunk) <= $max_length) {
                    $current_chunk = $test_chunk;
                } else {
                    if (!empty($current_chunk)) {
                        $chunks[] = $current_chunk;
                    }
                    $current_chunk = $word;

                    // Handle single word longer than max_length
                    if (strlen($current_chunk) > $max_length) {
                        $chunks[] = substr($current_chunk, 0, $max_length);
                        $current_chunk = '';
                    }
                }
            }

            if (!empty($current_chunk)) {
                $chunks[] = $current_chunk;
            }
        }
    }

    // Add URLs as separate chunks
    foreach ($urls as $url) {
        $chunks[] = $url;
    }

    return $chunks;
}

/**
 * Send SMS via VoIP.ms API
 *
 * @param string $from DID number sending the message
 * @param string $to Destination phone number
 * @param string $message Message content
 * @return array Response from API
 */
function send_voipms_sms($from, $to, $message) {
    global $pdo;

    // Get VoIP.ms credentials from database
    try {
        $stmt = $pdo->prepare("SELECT * FROM api_keys WHERE service_name = 'voipms'");
        $stmt->execute();
        $voipms_config = $stmt->fetch();

        if (!$voipms_config || !$voipms_config['is_enabled']) {
            return ['status' => 'error', 'message' => 'VoIP.ms is not enabled in settings'];
        }

        $config = json_decode($voipms_config['additional_config'], true);
        $api_user = $config['username'] ?? '';
        $api_pass = $config['password'] ?? '';
        $did = $config['did'] ?? '';
    } catch (Exception $e) {
        return ['status' => 'error', 'message' => 'Failed to load VoIP.ms configuration: ' . $e->getMessage()];
    }

    // Use configured DID if $from is empty
    if (empty($from)) {
        $from = $did;
    }

    // Clean phone numbers
    $from = clean_phone($from);
    $to = clean_phone($to);

    // Validate
    if (empty($api_user) || empty($api_pass)) {
        return ['status' => 'error', 'message' => 'VoIP.ms credentials not configured'];
    }

    if (empty($from) || empty($to) || empty($message)) {
        return ['status' => 'error', 'message' => 'Missing required parameters'];
    }

    // Build API URL
    $url = 'https://voip.ms/api/v1/rest.php?' . http_build_query([
        'api_username' => $api_user,
        'api_password' => $api_pass,
        'method' => 'sendSMS',
        'did' => $from,
        'dst' => $to,
        'message' => $message
    ]);

    // Make API request
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        return ['status' => 'error', 'message' => 'cURL error: ' . $curl_error];
    }

    if ($http_code !== 200) {
        return ['status' => 'error', 'message' => 'HTTP error: ' . $http_code . ' - Response: ' . substr($response, 0, 200)];
    }

    $result = json_decode($response, true);

    if (!$result) {
        return ['status' => 'error', 'message' => 'Failed to parse API response: ' . substr($response, 0, 200)];
    }

    // If API returned an error status, include the full response
    if ($result['status'] !== 'success') {
        $error_msg = $result['status'] ?? 'Unknown error';
        return ['status' => 'error', 'message' => 'VoIP.ms API error: ' . $error_msg, 'api_response' => $result];
    }

    // Store in database
    try {
        $status = 'sent';
        $voipms_id = isset($result['sms']) ? $result['sms'] : null;

        $stmt = $pdo->prepare("
            INSERT INTO sms_messages (direction, from_number, to_number, message, status, voipms_id)
            VALUES ('outbound', ?, ?, ?, ?, ?)
        ");

        $stmt->execute([$from, $to, $message, $status, $voipms_id]);
    } catch (Exception $e) {
        // Log error but don't fail the send
        if (DEBUG_MODE) {
            error_log("Failed to log SMS: " . $e->getMessage());
        }
    }

    return $result;
}

/**
 * Send SMS with automatic message splitting
 *
 * @param string $from DID number sending the message
 * @param string $to Destination phone number
 * @param string $message Message content
 * @return array Response with results for each chunk
 */
function send_sms($from, $to, $message) {
    $chunks = split_sms_message($message);
    $results = [];
    $all_success = true;

    foreach ($chunks as $index => $chunk) {
        $result = send_voipms_sms($from, $to, $chunk);
        $results[] = [
            'chunk' => $index + 1,
            'message' => $chunk,
            'result' => $result
        ];

        if (!$result || $result['status'] !== 'success') {
            $all_success = false;
        }

        // Small delay between messages to avoid rate limiting
        if ($index < count($chunks) - 1) {
            usleep(500000); // 0.5 seconds
        }
    }

    return [
        'status' => $all_success ? 'success' : 'partial_failure',
        'total_chunks' => count($chunks),
        'results' => $results
    ];
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);

    $from = isset($input['from']) ? $input['from'] : VOIPMS_DID;
    $to = isset($input['to']) ? $input['to'] : null;
    $message = isset($input['message']) ? $input['message'] : null;

    if (!$to || !$message) {
        echo json_encode(['status' => 'error', 'message' => 'Missing required parameters']);
        exit;
    }

    $result = send_sms($from, $to, $message);
    echo json_encode($result);
    exit;
}

// If called directly without POST, show error
if (basename($_SERVER['PHP_SELF']) === basename(__FILE__)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
    exit;
}
