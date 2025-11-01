<?php
/**
 * XactoAuth OAuth Callback Handler
 */

define('SNS_ENROLLMENT', true);
require_once __DIR__ . '/../src/config.php';

// Start session
session_start();

// Check if we have an authorization code
if (!isset($_GET['code'])) {
    die('Error: No authorization code received');
}

// Verify state to prevent CSRF
if (!isset($_GET['state']) || !isset($_SESSION['xactoauth_state']) || $_GET['state'] !== $_SESSION['xactoauth_state']) {
    die('Error: Invalid state parameter');
}

// Clear the state
unset($_SESSION['xactoauth_state']);

// Exchange code for access token
$token_data = xactoauth_exchange_code($_GET['code']);

if (!$token_data) {
    die('Error: Failed to exchange authorization code for access token. Check logs for details.');
}

// Check if token exchange returned an error
if (isset($token_data['error'])) {
    echo '<h2>XactoAuth Token Exchange Failed</h2>';
    echo '<p><strong>Error:</strong> ' . htmlspecialchars($token_data['error']) . '</p>';
    echo '<p><strong>Details:</strong> ' . htmlspecialchars($token_data['message']) . '</p>';
    if (isset($token_data['http_code'])) {
        echo '<p><strong>HTTP Code:</strong> ' . htmlspecialchars($token_data['http_code']) . '</p>';
    }
    echo '<p><a href="login.php">Return to Login</a></p>';
    exit;
}

// Store access token in session
$_SESSION['xactoauth_access_token'] = $token_data['access_token'];
$_SESSION['xactoauth_refresh_token'] = $token_data['refresh_token'] ?? null;
$_SESSION['xactoauth_expires_at'] = time() + ($token_data['expires_in'] ?? 3600);

// Get user identity from token response (no separate API call needed!)
if (!isset($token_data['identity'])) {
    echo '<h2>XactoAuth Identity Missing</h2>';
    echo '<p><strong>Error:</strong> Token exchange response missing identity data.</p>';
    echo '<p><a href="login.php">Return to Login</a></p>';
    exit;
}

$user_info = $token_data['identity'];

// Store identity in session
$_SESSION['xactoauth_user'] = $user_info;
$_SESSION['xactoauth_identity'] = $user_info;

log_activity("XactoAuth identity retrieved from token response: " . ($user_info['email'] ?? 'unknown'), 'INFO');

// Extract XactoAuth identity ID
$xactoauth_identity_id = $user_info['id'] ?? $user_info['user_id'] ?? null;
$user_email = $user_info['email'] ?? null;

if (!$xactoauth_identity_id || !$user_email) {
    echo '<h2>Invalid Identity Data</h2>';
    echo '<p><strong>Error:</strong> Missing required identity fields (id or email).</p>';
    echo '<p><a href="login.php">Return to Login</a></p>';
    exit;
}

// Store XactoAuth identity ID in session
$_SESSION['xactoauth_identity_id'] = $xactoauth_identity_id;

// CHECK: If user is ALREADY logged in, just link to their current account!
if (isset($_SESSION['staff_id']) && isset($_SESSION['staff_logged_in'])) {
    // User is logged in - link XactoAuth to their current account
    $stmt = $pdo->prepare("UPDATE staff SET xactoauth_user_id = ? WHERE id = ?");
    $stmt->execute([$xactoauth_identity_id, $_SESSION['staff_id']]);

    log_activity("XactoAuth linked to logged-in user ID: {$_SESSION['staff_id']} (identity: {$xactoauth_identity_id})", 'INFO');

    // Redirect back to preferences to show success
    header('Location: ' . BASE_URL . '/admin/preferences.php?linked=1');
    exit;
}

// STEP 1: Check if this XactoAuth identity is already linked to a user account
$stmt = $pdo->prepare("SELECT * FROM staff WHERE xactoauth_user_id = ? AND is_active = 1");
$stmt->execute([$xactoauth_identity_id]);
$existing_user = $stmt->fetch();

if ($existing_user) {
    // User already linked - log them in!
    $_SESSION['staff_id'] = $existing_user['id'];
    $_SESSION['staff_email'] = $existing_user['email'];
    $_SESSION['staff_name'] = $existing_user['full_name'];
    $_SESSION['staff_logged_in'] = true;

    log_activity("XactoAuth login successful for linked user: {$existing_user['email']} (identity: {$xactoauth_identity_id})", 'INFO');

    // Redirect to admin panel
    header('Location: ' . BASE_URL . '/admin/panel.php');
    exit;
}

// STEP 2: Check if a user with this email exists (auto-link)
$stmt = $pdo->prepare("SELECT * FROM staff WHERE email = ? AND is_active = 1");
$stmt->execute([$user_email]);
$user_by_email = $stmt->fetch();

if ($user_by_email) {
    // User exists with this email - automatically link it!
    $stmt = $pdo->prepare("UPDATE staff SET xactoauth_user_id = ? WHERE id = ?");
    $stmt->execute([$xactoauth_identity_id, $user_by_email['id']]);

    // Log them in
    $_SESSION['staff_id'] = $user_by_email['id'];
    $_SESSION['staff_email'] = $user_by_email['email'];
    $_SESSION['staff_name'] = $user_by_email['full_name'];
    $_SESSION['staff_logged_in'] = true;

    log_activity("XactoAuth auto-linked and logged in user: {$user_by_email['email']} (identity: {$xactoauth_identity_id})", 'INFO');

    // Redirect to admin panel
    header('Location: ' . BASE_URL . '/admin/panel.php');
    exit;
}

// STEP 3: No existing user - create new staff account
$display_name = $user_info['display_name'] ?? (($user_info['first_name'] ?? '') . ' ' . ($user_info['last_name'] ?? '')) ?? $user_info['username'] ?? $user_email;
$username = $user_info['username'] ?? explode('@', $user_email)[0];

// Create user with XactoAuth - no password needed since they login via OAuth
$random_password = bin2hex(random_bytes(32)); // Random password they'll never use
$password_hash = password_hash($random_password, PASSWORD_ARGON2ID);

$stmt = $pdo->prepare("INSERT INTO staff (username, email, full_name, password_hash, xactoauth_user_id, is_active, role, created_at) VALUES (?, ?, ?, ?, ?, 1, 'staff', NOW())");
$stmt->execute([$username, $user_email, $display_name, $password_hash, $xactoauth_identity_id]);
$new_user_id = $pdo->lastInsertId();

// Log them in
$_SESSION['staff_id'] = $new_user_id;
$_SESSION['staff_email'] = $user_email;
$_SESSION['staff_name'] = $display_name;
$_SESSION['staff_logged_in'] = true;

log_activity("XactoAuth created new user and logged in: {$user_email} (identity: {$xactoauth_identity_id})", 'INFO');

// Redirect to admin panel
header('Location: ' . BASE_URL . '/admin/panel.php');
exit;
