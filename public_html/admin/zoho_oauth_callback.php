<?php
/**
 * Zoho Books OAuth 2.0 Callback Handler
 *
 * This page handles the OAuth callback from Zoho after user authorization
 */

define('SNS_ENROLLMENT', true);
require_once __DIR__ . '/../src/config.php';

// Check authentication - must be staff user
session_start();
if (!isset($_SESSION['staff_id'])) {
    die('Unauthorized: You must be logged in as staff to configure integrations.');
}

// Check if we have the authorization code
if (!isset($_GET['code'])) {
    die('Error: No authorization code received from Zoho. Please try again.');
}

$auth_code = $_GET['code'];

// Get current Zoho configuration from database
try {
    $stmt = $pdo->prepare("
        SELECT additional_config FROM api_keys WHERE service_name = 'zoho_books'
    ");
    $stmt->execute();
    $result = $stmt->fetch();

    if (!$result) {
        die('Error: Zoho Books configuration not found in database.');
    }

    $config = json_decode($result['additional_config'], true);

    if (empty($config['client_id']) || empty($config['client_secret'])) {
        die('Error: Client ID and Client Secret must be configured before OAuth authorization. Please save them in settings first.');
    }

    $client_id = $config['client_id'];
    $client_secret = $config['client_secret'];

} catch (PDOException $e) {
    die('Database error: ' . htmlspecialchars($e->getMessage()));
}

// Build the redirect URI (must match what was used in authorization)
$redirect_uri = BASE_URL . '/admin/zoho_oauth_callback.php';

// Exchange authorization code for tokens
$token_url = 'https://accounts.zoho.com/oauth/v2/token';

$post_data = [
    'code' => $auth_code,
    'client_id' => $client_id,
    'client_secret' => $client_secret,
    'redirect_uri' => $redirect_uri,
    'grant_type' => 'authorization_code'
];

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $token_url,
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_POSTFIELDS => http_build_query($post_data),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/x-www-form-urlencoded'
    ]
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
curl_close($ch);

if ($curl_error) {
    die('cURL Error: ' . htmlspecialchars($curl_error));
}

if ($http_code !== 200) {
    die('Error: Token exchange failed with HTTP ' . $http_code . '<br><br>Response: ' . htmlspecialchars($response));
}

// Parse the response
$token_data = json_decode($response, true);

if (!isset($token_data['access_token']) || !isset($token_data['refresh_token'])) {
    die('Error: Invalid token response from Zoho.<br><br>Response: ' . htmlspecialchars($response));
}

$access_token = $token_data['access_token'];
$refresh_token = $token_data['refresh_token'];
$expires_in = $token_data['expires_in'] ?? 3600;
$token_expiry = time() + $expires_in;

// Fetch user's organizations to get organization_id
$orgs_url = 'https://www.zohoapis.com/books/v3/organizations';

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $orgs_url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Zoho-oauthtoken ' . $access_token
    ]
]);

$orgs_response = curl_exec($ch);
$orgs_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
curl_close($ch);

$organization_id = null;
$org_fetch_error = null;

if ($curl_error) {
    log_activity("Zoho org fetch curl error: " . $curl_error, 'ERROR');
    $org_fetch_error = $curl_error;
} elseif ($orgs_http_code === 200) {
    $orgs_data = json_decode($orgs_response, true);

    log_activity("Zoho organizations response: " . $orgs_response, 'INFO');

    if (isset($orgs_data['organizations']) && count($orgs_data['organizations']) > 0) {
        // Use the first organization by default
        $organization_id = $orgs_data['organizations'][0]['organization_id'];
        log_activity("Zoho organization ID detected: " . $organization_id, 'INFO');
    } else {
        $org_fetch_error = "No organizations found in response";
        log_activity("Zoho organizations response did not contain organizations array", 'WARNING');
    }
} else {
    $org_fetch_error = "HTTP $orgs_http_code";
    log_activity("Zoho org fetch failed HTTP {$orgs_http_code}: {$orgs_response}", 'ERROR');
}

// Update the database with tokens and organization_id
try {
    $config['access_token'] = $access_token;
    $config['refresh_token'] = $refresh_token;
    $config['token_expiry'] = $token_expiry;

    if ($organization_id) {
        $config['organization_id'] = $organization_id;
    }

    $stmt = $pdo->prepare("
        UPDATE api_keys
        SET additional_config = ?
        WHERE service_name = 'zoho_books'
    ");
    $stmt->execute([json_encode($config)]);

    log_activity("Zoho Books OAuth tokens saved successfully", 'INFO');

    // Success! Redirect back to settings
    if ($organization_id) {
        $success_message = urlencode("Zoho Books authorization successful! Refresh token and Organization ID ({$organization_id}) have been saved. You can now test the API.");
    } elseif ($org_fetch_error) {
        $success_message = urlencode("Zoho Books authorization successful! Refresh token saved, but Organization ID could not be fetched automatically ($org_fetch_error). Please enter it manually below.");
    } else {
        $success_message = urlencode('Zoho Books authorization successful! Refresh token saved. Please enter your Organization ID manually below.');
    }

    header('Location: settings.php?tab=api&zoho_success=' . $success_message);
    exit;

} catch (PDOException $e) {
    die('Database error while saving tokens: ' . htmlspecialchars($e->getMessage()));
}
