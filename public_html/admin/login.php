<?php
/**
 * Credit Enroll Pro - Admin Login with 2FA
 */

define('SNS_ENROLLMENT', true);
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/email_helper.php';

// Start session
session_start();

$error = '';
$success = '';
$show_2fa = false;
$staff_id = null;
$staff_2fa_methods = [];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['login_step']) && $_POST['login_step'] === 'credentials') {
        // Step 1: Validate username and password
        $username = sanitize_input($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($password)) {
            $error = 'Please enter both username and password.';
        } else {
            try {
                $stmt = $pdo->prepare("SELECT id, password_hash, email, phone, totp_enabled, sms_2fa_enabled, email_2fa_enabled, is_active FROM staff WHERE username = ?");
                $stmt->execute([$username]);
                $staff = $stmt->fetch();

                if ($staff && password_verify($password, $staff['password_hash'])) {
                    if (!$staff['is_active']) {
                        $error = 'Your account has been deactivated. Please contact an administrator.';
                    } else {
                        // Check if 2FA is enabled
                        $has_2fa = $staff['totp_enabled'] || $staff['sms_2fa_enabled'] || $staff['email_2fa_enabled'];

                        // Check for trusted browser
                        $trusted_browser = false;
                        if ($has_2fa && isset($_COOKIE['sns_browser_token'])) {
                            try {
                                $browser_token = $_COOKIE['sns_browser_token'];
                                $stmt = $pdo->prepare("SELECT id FROM trusted_browsers WHERE staff_id = ? AND browser_token = ? AND expires_at > NOW() AND revoked = 0");
                                $stmt->execute([$staff['id'], $browser_token]);
                                if ($stmt->fetch()) {
                                    $trusted_browser = true;
                                }
                            } catch (PDOException $e) {
                                log_activity("Trusted browser check error: " . $e->getMessage(), 'ERROR');
                            }
                        }

                        if ($has_2fa && !$trusted_browser) {
                            // Store staff ID in session temporarily
                            $_SESSION['temp_staff_id'] = $staff['id'];
                            $_SESSION['temp_staff_username'] = $username;

                            // Determine available 2FA methods
                            if ($staff['totp_enabled']) {
                                $staff_2fa_methods[] = 'totp';
                            }
                            if ($staff['sms_2fa_enabled'] && !empty($staff['phone'])) {
                                $staff_2fa_methods[] = 'sms';
                            }
                            if ($staff['email_2fa_enabled'] && !empty($staff['email'])) {
                                $staff_2fa_methods[] = 'email';
                            }

                            $_SESSION['temp_2fa_methods'] = $staff_2fa_methods;
                            $_SESSION['temp_staff_email'] = $staff['email'];
                            $_SESSION['temp_staff_phone'] = $staff['phone'];
                            $_SESSION['show_method_selection'] = true;
                            $show_2fa = true;
                        } else {
                            // No 2FA, log in directly
                            $_SESSION['staff_id'] = $staff['id'];
                            $_SESSION['staff_username'] = $username;
                            $_SESSION['staff_logged_in'] = true;

                            // Update last login
                            $stmt = $pdo->prepare("UPDATE staff SET last_login = NOW() WHERE id = ?");
                            $stmt->execute([$staff['id']]);

                            header('Location: panel.php');
                            exit;
                        }
                    }
                } else {
                    $error = 'Invalid username or password.';
                }
            } catch (PDOException $e) {
                log_activity("Login error: " . $e->getMessage(), 'ERROR');
                $error = 'An error occurred. Please try again.';
            }
        }
    } elseif (isset($_POST['login_step']) && $_POST['login_step'] === 'select_2fa_method') {
        // Step 2: User selects 2FA method
        if (!isset($_SESSION['temp_staff_id'])) {
            $error = 'Session expired. Please log in again.';
        } else {
            $selected_method = $_POST['2fa_method'] ?? '';

            if (empty($selected_method)) {
                $error = 'Please select a verification method.';
                $show_2fa = true;
                $staff_2fa_methods = $_SESSION['temp_2fa_methods'] ?? [];
            } else {
                // Validate that the selected method is available
                $available_methods = $_SESSION['temp_2fa_methods'] ?? [];
                if (!in_array($selected_method, $available_methods)) {
                    $error = 'Invalid verification method selected.';
                    $show_2fa = true;
                    $staff_2fa_methods = $available_methods;
                } else {
                    $_SESSION['temp_2fa_method'] = $selected_method;
                    unset($_SESSION['show_method_selection']);

                    // If SMS or email, send the code
                    if ($selected_method === 'sms' || $selected_method === 'email') {
                        try {
                            $code = generate_2fa_code(6);
                            $expires_at = gmdate('Y-m-d H:i:s', strtotime('+15 minutes'));

                            $identifier = $selected_method === 'sms' ? $_SESSION['temp_staff_phone'] : $_SESSION['temp_staff_email'];

                            // Store code in database
                            $stmt = $pdo->prepare("INSERT INTO `2fa_codes` (code, identifier, identifier_type, purpose, staff_id, expires_at, ip_address) VALUES (?, ?, ?, 'staff_login', ?, ?, ?)");
                            $stmt->execute([$code, $identifier, $selected_method === 'sms' ? 'phone' : 'email', $_SESSION['temp_staff_id'], $expires_at, get_client_ip()]);

                            // Send code via SMS or email
                            if ($selected_method === 'email') {
                                // Get staff name for personalization
                                $stmt = $pdo->prepare("SELECT full_name FROM staff WHERE id = ?");
                                $stmt->execute([$_SESSION['temp_staff_id']]);
                                $staff_data = $stmt->fetch();
                                $staff_name = $staff_data['full_name'] ?? '';

                                $email_result = send_2fa_code_email($identifier, $code, $staff_name);
                                if ($email_result['success']) {
                                    $success = 'A verification code has been sent to your email.';
                                } else {
                                    $error = 'Failed to send verification code. Please try again.';
                                }
                            } elseif ($selected_method === 'sms') {
                                // Check if VoIP.ms is enabled
                                $stmt = $pdo->prepare("SELECT is_enabled FROM api_keys WHERE service_name = 'voipms'");
                                $stmt->execute();
                                $voip_config = $stmt->fetch();

                                if ($voip_config && $voip_config['is_enabled']) {
                                    // TODO: Implement VoIP.ms SMS sending
                                    $success = 'A verification code has been sent to your phone.';
                                } else {
                                    $error = 'SMS verification is coming soon. Please use Email or TOTP instead.';
                                }
                            }
                        } catch (PDOException $e) {
                            log_activity("Send 2FA code error: " . $e->getMessage(), 'ERROR');
                            $error = 'Failed to send verification code. Please try again.';
                        }
                    }

                    $show_2fa = true;
                    $staff_2fa_methods = $available_methods;
                }
            }
        }
    } elseif (isset($_POST['login_step']) && $_POST['login_step'] === '2fa') {
        // Step 2: Verify 2FA code
        if (!isset($_SESSION['temp_staff_id'])) {
            $error = 'Session expired. Please log in again.';
        } else {
            $code = sanitize_input($_POST['2fa_code'] ?? '');
            $method = $_SESSION['temp_2fa_method'] ?? '';

            if (empty($code)) {
                $error = 'Please enter the verification code.';
                $show_2fa = true;
                $staff_2fa_methods = $_SESSION['temp_2fa_methods'] ?? [];
            } else {
                try {
                    if ($method === 'totp') {
                        // Verify TOTP code using OTPHP library
                        $stmt = $pdo->prepare("SELECT totp_secret FROM staff WHERE id = ?");
                        $stmt->execute([$_SESSION['temp_staff_id']]);
                        $staff = $stmt->fetch();

                        if ($staff && !empty($staff['totp_secret'])) {
                            try {
                                $totp = OTPHP\TOTP::createFromSecret($staff['totp_secret']);
                                $verified = $totp->verify($code, null, 1); // Allow 1 time window (30 seconds before/after)
                                if ($verified) {
                                    $result = ['success' => true];
                                } else {
                                    $result = ['success' => false, 'error' => 'Invalid TOTP code'];
                                }
                            } catch (Exception $e) {
                                log_activity("TOTP verification error: " . $e->getMessage(), 'ERROR');
                                $result = ['success' => false, 'error' => 'TOTP verification failed'];
                            }
                        } else {
                            $result = ['success' => false, 'error' => 'TOTP not configured for this account'];
                        }

                        if ($result['success']) {
                            // Handle "Save Browser for 7 Days" option
                            $save_browser = isset($_POST['save_browser']) && $_POST['save_browser'] === '1';
                            if ($save_browser) {
                                // Generate unique browser token
                                $browser_token = bin2hex(random_bytes(32));
                                $expires_at = date('Y-m-d H:i:s', strtotime('+7 days'));
                                $ip_address = get_client_ip();
                                $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

                                // Store browser token in database
                                try {
                                    $stmt = $pdo->prepare("INSERT INTO trusted_browsers (staff_id, browser_token, ip_address, user_agent, expires_at) VALUES (?, ?, ?, ?, ?)");
                                    $stmt->execute([$_SESSION['temp_staff_id'], $browser_token, $ip_address, $user_agent, $expires_at]);

                                    // Set cookie for 7 days
                                    setcookie('sns_browser_token', $browser_token, [
                                        'expires' => strtotime('+7 days'),
                                        'path' => '/',
                                        'secure' => true,
                                        'httponly' => true,
                                        'samesite' => 'Lax'
                                    ]);
                                } catch (PDOException $e) {
                                    log_activity("Save browser token error: " . $e->getMessage(), 'ERROR');
                                    // Continue with login even if saving browser fails
                                }
                            }

                            // Log in the user
                            $_SESSION['staff_id'] = $_SESSION['temp_staff_id'];
                            $_SESSION['staff_username'] = $_SESSION['temp_staff_username'];
                            $_SESSION['staff_logged_in'] = true;

                            // Update last login
                            $stmt = $pdo->prepare("UPDATE staff SET last_login = NOW() WHERE id = ?");
                            $stmt->execute([$_SESSION['temp_staff_id']]);

                            // Clean up temp session variables
                            unset($_SESSION['temp_staff_id']);
                            unset($_SESSION['temp_staff_username']);
                            unset($_SESSION['temp_2fa_methods']);
                            unset($_SESSION['temp_2fa_method']);
                            unset($_SESSION['temp_staff_email']);
                            unset($_SESSION['temp_staff_phone']);
                            unset($_SESSION['show_method_selection']);

                            header('Location: panel.php');
                            exit;
                        } else {
                            $error = $result['error'] ?? 'Verification failed';
                            $show_2fa = true;
                            $staff_2fa_methods = $_SESSION['temp_2fa_methods'] ?? [];
                        }
                    } else {
                        // Verify SMS or email code
                        $stmt = $pdo->prepare("
                            SELECT id, attempts
                            FROM `2fa_codes`
                            WHERE staff_id = ?
                            AND code = ?
                            AND purpose = 'staff_login'
                            AND verified = 0
                            AND expires_at > NOW()
                            ORDER BY created_at DESC
                            LIMIT 1
                        ");
                        $stmt->execute([$_SESSION['temp_staff_id'], $code]);
                        $code_record = $stmt->fetch();

                        if ($code_record) {
                            if ($code_record['attempts'] >= MAX_2FA_ATTEMPTS) {
                                $error = 'Too many failed attempts. Please request a new code.';
                                $show_2fa = true;
                                $staff_2fa_methods = $_SESSION['temp_2fa_methods'] ?? [];
                            } else {
                                // Mark code as verified
                                $stmt = $pdo->prepare("UPDATE `2fa_codes` SET verified = 1, verified_at = NOW() WHERE id = ?");
                                $stmt->execute([$code_record['id']]);

                                // Handle "Save Browser for 7 Days" option
                                $save_browser = isset($_POST['save_browser']) && $_POST['save_browser'] === '1';
                                if ($save_browser) {
                                    // Generate unique browser token
                                    $browser_token = bin2hex(random_bytes(32));
                                    $expires_at = date('Y-m-d H:i:s', strtotime('+7 days'));
                                    $ip_address = get_client_ip();
                                    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

                                    // Store browser token in database
                                    try {
                                        $stmt = $pdo->prepare("INSERT INTO trusted_browsers (staff_id, browser_token, ip_address, user_agent, expires_at) VALUES (?, ?, ?, ?, ?)");
                                        $stmt->execute([$_SESSION['temp_staff_id'], $browser_token, $ip_address, $user_agent, $expires_at]);

                                        // Set cookie for 7 days
                                        setcookie('sns_browser_token', $browser_token, [
                                            'expires' => strtotime('+7 days'),
                                            'path' => '/',
                                            'secure' => true,
                                            'httponly' => true,
                                            'samesite' => 'Lax'
                                        ]);
                                    } catch (PDOException $e) {
                                        log_activity("Save browser token error: " . $e->getMessage(), 'ERROR');
                                        // Continue with login even if saving browser fails
                                    }
                                }

                                // Log in the user
                                $_SESSION['staff_id'] = $_SESSION['temp_staff_id'];
                                $_SESSION['staff_username'] = $_SESSION['temp_staff_username'];
                                $_SESSION['staff_logged_in'] = true;

                                // Update last login
                                $stmt = $pdo->prepare("UPDATE staff SET last_login = NOW() WHERE id = ?");
                                $stmt->execute([$_SESSION['temp_staff_id']]);

                                // Clean up temp session variables
                                unset($_SESSION['temp_staff_id']);
                                unset($_SESSION['temp_staff_username']);
                                unset($_SESSION['temp_2fa_methods']);
                                unset($_SESSION['temp_2fa_method']);
                                unset($_SESSION['temp_staff_email']);
                                unset($_SESSION['temp_staff_phone']);
                                unset($_SESSION['show_method_selection']);

                                header('Location: panel.php');
                                exit;
                            }
                        } else {
                            // Increment attempts
                            $stmt = $pdo->prepare("
                                UPDATE `2fa_codes`
                                SET attempts = attempts + 1
                                WHERE staff_id = ?
                                AND purpose = 'staff_login'
                                AND verified = 0
                                AND expires_at > NOW()
                                ORDER BY created_at DESC
                                LIMIT 1
                            ");
                            $stmt->execute([$_SESSION['temp_staff_id']]);

                            $error = 'Invalid or expired verification code.';
                            $show_2fa = true;
                            $staff_2fa_methods = $_SESSION['temp_2fa_methods'] ?? [];
                        }
                    }
                } catch (PDOException $e) {
                    log_activity("2FA verification error: " . $e->getMessage(), 'ERROR');
                    $error = 'An error occurred. Please try again.';
                    $show_2fa = true;
                    $staff_2fa_methods = $_SESSION['temp_2fa_methods'] ?? [];
                }
            }
        }
    } elseif (isset($_POST['resend_code'])) {
        // Resend 2FA code
        if (!isset($_SESSION['temp_staff_id'])) {
            $error = 'Session expired. Please log in again.';
        } else {
            $method = $_POST['2fa_method'] ?? $_SESSION['temp_2fa_method'] ?? '';

            if (empty($method)) {
                $error = 'Please select a verification method.';
                $show_2fa = true;
                $staff_2fa_methods = $_SESSION['temp_2fa_methods'] ?? [];
            } else {
                try {
                    // Get staff info
                    $stmt = $pdo->prepare("SELECT email, phone FROM staff WHERE id = ?");
                    $stmt->execute([$_SESSION['temp_staff_id']]);
                    $staff = $stmt->fetch();

                    $code = generate_2fa_code(6);
                    $expires_at = gmdate('Y-m-d H:i:s', strtotime('+15 minutes'));

                    // Invalidate old codes
                    $stmt = $pdo->prepare("UPDATE `2fa_codes` SET verified = 1 WHERE staff_id = ? AND purpose = 'staff_login' AND verified = 0");
                    $stmt->execute([$_SESSION['temp_staff_id']]);

                    // Store new code
                    $stmt = $pdo->prepare("INSERT INTO `2fa_codes` (code, identifier, identifier_type, purpose, staff_id, expires_at, ip_address) VALUES (?, ?, ?, 'staff_login', ?, ?, ?)");
                    $identifier = $method === 'sms' ? $staff['phone'] : $staff['email'];
                    $stmt->execute([$code, $identifier, $method === 'sms' ? 'phone' : 'email', $_SESSION['temp_staff_id'], $expires_at, get_client_ip()]);

                    $_SESSION['temp_2fa_method'] = $method;

                    // Send code via SMS or email
                    if ($method === 'email') {
                        // Get staff name for personalization
                        $stmt = $pdo->prepare("SELECT full_name FROM staff WHERE id = ?");
                        $stmt->execute([$_SESSION['temp_staff_id']]);
                        $staff_data = $stmt->fetch();
                        $staff_name = $staff_data['full_name'] ?? '';

                        $email_result = send_2fa_code_email($identifier, $code, $staff_name);
                        if ($email_result['success']) {
                            $success = 'A new verification code has been sent to your email.';
                        } else {
                            $error = 'Failed to send verification code. Please try again.';
                        }
                    } elseif ($method === 'sms') {
                        // Check if VoIP.ms is enabled
                        $stmt = $pdo->prepare("SELECT is_enabled FROM api_keys WHERE service_name = 'voipms'");
                        $stmt->execute();
                        $voip_config = $stmt->fetch();

                        if ($voip_config && $voip_config['is_enabled']) {
                            // TODO: Implement VoIP.ms SMS sending
                            $success = 'A new verification code has been sent to your phone.';
                        } else {
                            $error = 'SMS verification is coming soon. Please use Email or TOTP instead.';
                        }
                    }

                    $show_2fa = true;
                    $staff_2fa_methods = $_SESSION['temp_2fa_methods'] ?? [];
                } catch (PDOException $e) {
                    log_activity("Resend code error: " . $e->getMessage(), 'ERROR');
                    $error = 'An error occurred. Please try again.';
                    $show_2fa = true;
                    $staff_2fa_methods = $_SESSION['temp_2fa_methods'] ?? [];
                }
            }
        }
    }
}

// Check if returning to 2FA step
if (isset($_SESSION['temp_staff_id']) && !$show_2fa && empty($error)) {
    $show_2fa = true;
    $staff_2fa_methods = $_SESSION['temp_2fa_methods'] ?? [];
}

$page_title = 'Admin Login';
include __DIR__ . '/../src/header.php';
?>

<div class="login-container">
    <div class="login-card card">
        <div class="card-header">
            <h1 class="card-title">Admin Login</h1>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <?php if (!$show_2fa): ?>
            <!-- Step 1: Username and Password -->
            <form method="POST" action="" id="loginForm">
                <input type="hidden" name="login_step" value="credentials">

                <div class="form-group">
                    <label for="username" class="form-label required">Username</label>
                    <input type="text" id="username" name="username" class="form-control" required autocomplete="username">
                    <div class="form-error"></div>
                </div>

                <div class="form-group">
                    <label for="password" class="form-label required">Password</label>
                    <input type="password" id="password" name="password" class="form-control" required autocomplete="current-password">
                    <div class="form-error"></div>
                </div>

                <button type="submit" class="btn btn-primary btn-block">Login</button>
            </form>

            <?php if (get_xactoauth_credentials()): ?>
                <div style="text-align: center; margin: var(--spacing-lg) 0; position: relative;">
                    <div style="border-top: 1px solid #ddd; position: absolute; top: 50%; left: 0; right: 0; z-index: 0;"></div>
                    <span style="background: #fff; padding: 0 var(--spacing-md); position: relative; z-index: 1; color: #666; font-size: 0.9em;">or</span>
                </div>

                <a href="xactoauth-login.php" class="btn btn-outline btn-block" style="display: flex; align-items: center; justify-content: center; gap: 0.5rem;">
                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1" />
                    </svg>
                    Login with XactoAuth
                </a>
            <?php endif; ?>
        <?php else: ?>
            <?php if (isset($_SESSION['show_method_selection']) && $_SESSION['show_method_selection']): ?>
                <!-- Step 2A: Select 2FA Method -->
                <form method="POST" action="" id="methodForm">
                    <input type="hidden" name="login_step" value="select_2fa_method">

                    <div class="form-group">
                        <label class="form-label required">Choose Verification Method</label>
                        <div class="method-options">
                            <?php foreach ($staff_2fa_methods as $method): ?>
                                <label class="method-option">
                                    <input type="radio" name="2fa_method" value="<?php echo htmlspecialchars($method); ?>" required>
                                    <span class="method-label">
                                        <?php if ($method === 'totp'): ?>
                                            <i class="fas fa-mobile-alt"></i> Authenticator App (TOTP)
                                        <?php elseif ($method === 'sms'): ?>
                                            <i class="fas fa-sms"></i> Text Message (SMS)
                                        <?php elseif ($method === 'email'): ?>
                                            <i class="fas fa-envelope"></i> Email
                                        <?php endif; ?>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <div class="form-error"></div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-block">Continue</button>
                </form>
            <?php else: ?>
                <!-- Step 2B: 2FA Verification -->
                <form method="POST" action="" id="2faForm">
                    <input type="hidden" name="login_step" value="2fa">

                    <div class="form-group">
                        <label for="2fa_code" class="form-label required">Verification Code</label>
                        <input type="text" id="2fa_code" name="2fa_code" class="form-control" required maxlength="6" pattern="[0-9]{6}" placeholder="Enter 6-digit code">
                        <div class="form-help">
                            <?php
                            $method = $_SESSION['temp_2fa_method'] ?? '';
                            if ($method === 'totp') {
                                echo 'Enter the 6-digit code from your authenticator app.';
                            } elseif ($method === 'sms') {
                                echo 'Enter the 6-digit verification code sent to your phone.';
                            } elseif ($method === 'email') {
                                echo 'Enter the 6-digit verification code sent to your email.';
                            }
                            ?>
                        </div>
                        <div class="form-error"></div>
                    </div>

                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="save_browser" value="1">
                            <span>Save this browser for 7 days</span>
                        </label>
                        <div class="form-help">
                            You won't need to enter a code on this device for the next 7 days. Only use on trusted devices.
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-block">Verify</button>
                </form>
            <?php endif; ?>

            <!-- Resend code options (only show when verifying code) -->
            <?php if (!isset($_SESSION['show_method_selection']) || !$_SESSION['show_method_selection']): ?>
            <div class="resend-section">
                <p>Didn't receive the code?</p>
                <form method="POST" action="" style="display: inline;">
                    <input type="hidden" name="resend_code" value="1">
                    <?php if (count($staff_2fa_methods) > 1): ?>
                        <label for="2fa_method" class="form-label">Send code via:</label>
                        <select name="2fa_method" id="2fa_method" class="form-control" style="margin-bottom: 1rem;">
                            <?php foreach ($staff_2fa_methods as $method): ?>
                                <?php if ($method !== 'totp'): ?>
                                    <option value="<?php echo $method; ?>" <?php echo ($method === $_SESSION['temp_2fa_method']) ? 'selected' : ''; ?>>
                                        <?php echo $method === 'sms' ? 'Text Message' : 'Email'; ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input type="hidden" name="2fa_method" value="<?php echo $_SESSION['temp_2fa_method']; ?>">
                    <?php endif; ?>
                    <button type="submit" class="btn btn-secondary">Resend Code</button>
                </form>
            </div>

            <div class="back-link">
                <a href="login.php">← Back to login</a>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <div class="login-footer">
        <a href="<?php echo BASE_URL; ?>">← Back to Home</a>
    </div>
</div>

<style>
    .login-container {
        max-width: 450px;
        margin: 0 auto;
        padding: var(--spacing-xl) var(--spacing-md);
    }

    .login-card {
        margin-top: var(--spacing-lg);
    }

    .login-card .card-header {
        text-align: center;
    }

    .login-card form {
        margin-top: var(--spacing-md);
    }

    .resend-section {
        margin-top: var(--spacing-lg);
        padding-top: var(--spacing-lg);
        border-top: 1px solid var(--color-light-2);
        text-align: center;
    }

    .resend-section p {
        margin-bottom: var(--spacing-sm);
        color: #666;
    }

    .back-link {
        margin-top: var(--spacing-md);
        text-align: center;
    }

    .back-link a {
        color: var(--color-primary);
        text-decoration: none;
    }

    .back-link a:hover {
        text-decoration: underline;
    }

    .login-footer {
        text-align: center;
        margin-top: var(--spacing-lg);
    }

    .login-footer a {
        color: var(--color-primary);
        text-decoration: none;
    }

    .login-footer a:hover {
        text-decoration: underline;
    }

    /* 2FA Method Selection */
    .method-options {
        display: flex;
        flex-direction: column;
        gap: var(--spacing-sm);
        margin-top: var(--spacing-sm);
    }

    .method-option {
        display: flex;
        align-items: center;
        padding: var(--spacing-md);
        border: 2px solid var(--color-light-2);
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.2s ease;
        background: white;
    }

    .method-option:hover {
        border-color: var(--color-primary);
        background: rgba(0, 123, 255, 0.05);
    }

    .method-option input[type="radio"] {
        margin-right: var(--spacing-sm);
        cursor: pointer;
    }

    .method-option input[type="radio"]:checked + .method-label {
        color: var(--color-primary);
        font-weight: 600;
    }

    .method-option:has(input[type="radio"]:checked) {
        border-color: var(--color-primary);
        background: rgba(0, 123, 255, 0.1);
    }

    .method-label {
        display: flex;
        align-items: center;
        gap: var(--spacing-sm);
        flex: 1;
        cursor: pointer;
    }

    .method-label i {
        font-size: 1.2em;
        width: 24px;
        text-align: center;
    }

    /* Checkbox styling */
    .checkbox-label {
        display: flex;
        align-items: flex-start;
        gap: var(--spacing-sm);
        cursor: pointer;
        font-weight: normal;
    }

    .checkbox-label input[type="checkbox"] {
        margin-top: 3px;
        cursor: pointer;
        width: 18px;
        height: 18px;
    }

    .checkbox-label span {
        flex: 1;
    }
</style>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Auto-focus on first input
        const firstInput = document.querySelector('input[type="text"], input[type="password"]');
        if (firstInput) {
            firstInput.focus();
        }

        // Format 2FA code input (digits only)
        const codeInput = document.getElementById('2fa_code');
        if (codeInput) {
            codeInput.addEventListener('input', function(e) {
                this.value = this.value.replace(/[^0-9]/g, '').substr(0, 6);
            });
        }
    });
</script>

<?php include __DIR__ . '/../src/footer.php'; ?>
