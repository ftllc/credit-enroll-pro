<?php
/**
 * Admin Panel - User Preferences
 * Manage email, password, phone, and TOTP settings
 */

define('SNS_ENROLLMENT', true);
require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../vendor/autoload.php';

// Start session
session_start();

// Check if logged in
if (!isset($_SESSION['staff_id']) || !isset($_SESSION['staff_logged_in'])) {
    header('Location: login.php');
    exit;
}

// Get staff info
$stmt = $pdo->prepare("SELECT * FROM staff WHERE id = ?");
$stmt->execute([$_SESSION['staff_id']]);
$staff = $stmt->fetch();

if (!$staff || !$staff['is_active']) {
    session_destroy();
    header('Location: login.php');
    exit;
}

$errors = [];
$success = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Update Email
    if (isset($_POST['update_email'])) {
        $new_email = filter_var(trim($_POST['email']), FILTER_VALIDATE_EMAIL);
        $current_password = $_POST['current_password'] ?? '';

        if (!$new_email) {
            $errors[] = 'Please enter a valid email address';
        } elseif (!password_verify($current_password, $staff['password_hash'])) {
            $errors[] = 'Current password is incorrect';
        } else {
            // Check if email already exists
            $stmt = $pdo->prepare("SELECT id FROM staff WHERE email = ? AND id != ?");
            $stmt->execute([$new_email, $staff['id']]);
            if ($stmt->fetch()) {
                $errors[] = 'Email address is already in use';
            } else {
                $stmt = $pdo->prepare("UPDATE staff SET email = ? WHERE id = ?");
                $stmt->execute([$new_email, $staff['id']]);
                $success = 'Email address updated successfully';
                $staff['email'] = $new_email;
            }
        }
    }

    // Update Password
    if (isset($_POST['update_password'])) {
        $current_password = $_POST['current_password_pw'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (!password_verify($current_password, $staff['password_hash'])) {
            $errors[] = 'Current password is incorrect';
        } elseif (strlen($new_password) < 8) {
            $errors[] = 'New password must be at least 8 characters';
        } elseif ($new_password !== $confirm_password) {
            $errors[] = 'New passwords do not match';
        } else {
            $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE staff SET password_hash = ? WHERE id = ?");
            $stmt->execute([$new_hash, $staff['id']]);
            $success = 'Password updated successfully';
        }
    }

    // Update Phone
    if (isset($_POST['update_phone'])) {
        $new_phone = preg_replace('/\D/', '', $_POST['phone'] ?? '');

        if (!empty($new_phone) && strlen($new_phone) !== 10) {
            $errors[] = 'Please enter a valid 10-digit phone number';
        } else {
            $stmt = $pdo->prepare("UPDATE staff SET phone = ? WHERE id = ?");
            $stmt->execute([$new_phone ?: null, $staff['id']]);
            $success = 'Phone number updated successfully';
            $staff['phone'] = $new_phone;
        }
    }

    // Enable TOTP
    if (isset($_POST['enable_totp'])) {
        $totp_secret = $_POST['totp_secret'] ?? '';
        $verification_code = $_POST['totp_code'] ?? '';

        $totp = OTPHP\TOTP::createFromSecret($totp_secret);

        if ($totp->verify($verification_code, null, 2)) {
            $stmt = $pdo->prepare("UPDATE staff SET totp_secret = ?, totp_enabled = TRUE WHERE id = ?");
            $stmt->execute([$totp_secret, $staff['id']]);
            $success = 'Two-Factor Authentication enabled successfully';
            $staff['totp_enabled'] = true;
            $staff['totp_secret'] = $totp_secret;
        } else {
            $errors[] = 'Invalid verification code. Please try again.';
        }
    }

    // Disable TOTP
    if (isset($_POST['disable_totp'])) {
        $current_password = $_POST['current_password_totp'] ?? '';

        if (!password_verify($current_password, $staff['password_hash'])) {
            $errors[] = 'Current password is incorrect';
        } else {
            $stmt = $pdo->prepare("UPDATE staff SET totp_secret = NULL, totp_enabled = FALSE WHERE id = ?");
            $stmt->execute([$staff['id']]);
            $success = 'Two-Factor Authentication disabled successfully';
            $staff['totp_enabled'] = false;
            $staff['totp_secret'] = null;
        }
    }

    // Generate new TOTP secret
    if (isset($_POST['generate_totp'])) {
        $totp = OTPHP\TOTP::generate();
        $_SESSION['temp_totp_secret'] = $totp->getSecret();
    }

    // Enable/Disable Email 2FA
    if (isset($_POST['toggle_email_2fa'])) {
        $enable = isset($_POST['email_2fa_enabled']) ? 1 : 0;

        if ($enable && empty($staff['email'])) {
            $errors[] = 'You must have an email address configured to enable email 2FA';
        } else {
            $stmt = $pdo->prepare("UPDATE staff SET email_2fa_enabled = ? WHERE id = ?");
            $stmt->execute([$enable, $staff['id']]);
            $staff['email_2fa_enabled'] = $enable;
            $success = $enable ? 'Email 2FA enabled successfully' : 'Email 2FA disabled successfully';
        }
    }

    // Enable/Disable SMS 2FA
    if (isset($_POST['toggle_sms_2fa'])) {
        $enable = isset($_POST['sms_2fa_enabled']) ? 1 : 0;

        if ($enable && empty($staff['phone'])) {
            $errors[] = 'You must have a phone number configured to enable SMS 2FA';
        } else {
            $stmt = $pdo->prepare("UPDATE staff SET sms_2fa_enabled = ? WHERE id = ?");
            $stmt->execute([$enable, $staff['id']]);
            $staff['sms_2fa_enabled'] = $enable;
            $success = $enable ? 'SMS 2FA enabled successfully' : 'SMS 2FA disabled successfully';
        }
    }

    // Link XactoAuth Account
    if (isset($_POST['link_xactoauth'])) {
        if (isset($_SESSION['xactoauth_identity_id']) && isset($_SESSION['xactoauth_pending_link'])) {
            $xactoauth_identity_id = $_SESSION['xactoauth_identity_id'];
            $xactoauth_user = $_SESSION['xactoauth_user'] ?? [];

            // Check if this identity is already linked to another account
            $stmt = $pdo->prepare("SELECT id, email FROM staff WHERE xactoauth_user_id = ? AND id != ?");
            $stmt->execute([$xactoauth_identity_id, $staff['id']]);
            $already_linked = $stmt->fetch();

            if ($already_linked) {
                $errors[] = 'This XactoAuth identity is already linked to another account (' . htmlspecialchars($already_linked['email']) . ')';
            } else {
                // Link the XactoAuth identity to this staff account
                $stmt = $pdo->prepare("UPDATE staff SET xactoauth_user_id = ? WHERE id = ?");
                $stmt->execute([$xactoauth_identity_id, $staff['id']]);

                $staff['xactoauth_user_id'] = $xactoauth_identity_id;

                // Clear pending link flag
                unset($_SESSION['xactoauth_pending_link']);

                $success = 'XactoAuth account linked successfully! You can now use XactoAuth to log in.';
                log_activity("XactoAuth identity {$xactoauth_identity_id} linked for user: {$staff['email']}", 'INFO');
            }
        } else {
            $errors[] = 'No pending XactoAuth account to link. Please click "Login with XactoAuth" first.';
        }
    }

    // Unlink XactoAuth Account
    if (isset($_POST['unlink_xactoauth'])) {
        $stmt = $pdo->prepare("UPDATE staff SET xactoauth_user_id = NULL WHERE id = ?");
        $stmt->execute([$staff['id']]);

        $staff['xactoauth_user_id'] = null;

        // Clear XactoAuth session data
        unset($_SESSION['xactoauth_access_token']);
        unset($_SESSION['xactoauth_refresh_token']);
        unset($_SESSION['xactoauth_user']);

        $success = 'XactoAuth account unlinked successfully';
        log_activity("XactoAuth account unlinked for user: {$staff['email']}", 'INFO');
    }
}

$page_title = 'User Preferences';
include __DIR__ . '/../src/header.php';
?>

<style>
.admin-container {
    max-width: 1000px;
    margin: 0 auto;
}

.admin-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: var(--spacing-lg);
}

.admin-header h1 {
    color: var(--color-primary);
    margin: 0;
}

.preferences-grid {
    display: grid;
    gap: var(--spacing-lg);
}

.preference-card {
    background: white;
    border-radius: var(--border-radius);
    box-shadow: var(--box-shadow);
    padding: var(--spacing-lg);
}

.card-header {
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
    margin-bottom: var(--spacing-md);
    padding-bottom: var(--spacing-md);
    border-bottom: 2px solid var(--color-light-2);
}

.card-header svg {
    color: var(--color-primary);
    flex-shrink: 0;
}

.card-header h2 {
    margin: 0;
    font-size: 20px;
    color: var(--color-primary);
}

.form-group {
    margin-bottom: var(--spacing-md);
}

.form-group label {
    display: block;
    font-weight: 600;
    margin-bottom: var(--spacing-xs);
    color: #333;
}

.form-group input {
    width: 100%;
    padding: var(--spacing-sm);
    border: 1px solid var(--color-light-2);
    border-radius: var(--border-radius);
    font-size: var(--font-size-base);
}

.form-group input:focus {
    outline: none;
    border-color: var(--color-primary);
}

.form-actions {
    display: flex;
    gap: var(--spacing-sm);
    margin-top: var(--spacing-md);
}

.current-value {
    padding: var(--spacing-sm);
    background: var(--color-light-1);
    border-radius: var(--border-radius);
    margin-bottom: var(--spacing-md);
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
}

.current-value strong {
    color: #666;
    min-width: 120px;
}

.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 1rem;
    border-radius: 20px;
    font-weight: 600;
    font-size: 14px;
}

.status-badge.enabled {
    background: #d4edda;
    color: #155724;
}

.status-badge.disabled {
    background: #f8d7da;
    color: #721c24;
}

.totp-setup {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--spacing-lg);
    margin-top: var(--spacing-md);
}

.qr-section {
    text-align: center;
}

.qr-section img {
    max-width: 200px;
    margin: var(--spacing-md) 0;
}

.manual-entry {
    background: var(--color-light-1);
    padding: var(--spacing-md);
    border-radius: var(--border-radius);
    margin-top: var(--spacing-sm);
}

.manual-entry code {
    font-size: 14px;
    font-weight: 600;
    color: var(--color-primary);
    word-break: break-all;
}

@media (max-width: 768px) {
    .totp-setup {
        grid-template-columns: 1fr;
    }

    .form-actions {
        flex-direction: column;
    }

    .form-actions .btn {
        width: 100%;
    }
}
</style>

<div class="admin-container">
    <div class="admin-header">
        <h1>User Preferences</h1>
        <a href="panel.php" class="btn btn-secondary">Back to Dashboard</a>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <strong>Error:</strong>
            <ul style="margin: 0.5rem 0 0; padding-left: 1.5rem;">
                <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success">
            <strong>Success:</strong> <?php echo htmlspecialchars($success); ?>
        </div>
    <?php endif; ?>

    <div class="preferences-grid">
        <!-- Email Settings -->
        <div class="preference-card">
            <div class="card-header">
                <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                </svg>
                <h2>Email Address</h2>
            </div>

            <div class="current-value">
                <strong>Current Email:</strong>
                <span><?php echo htmlspecialchars($staff['email']); ?></span>
            </div>

            <form method="POST" action="">
                <div class="form-group">
                    <label for="email">New Email Address</label>
                    <input type="email" id="email" name="email" required placeholder="Enter new email address">
                </div>
                <div class="form-group">
                    <label for="current_password">Current Password (to confirm)</label>
                    <input type="password" id="current_password" name="current_password" required placeholder="Enter your current password">
                </div>
                <div class="form-actions">
                    <button type="submit" name="update_email" class="btn btn-primary">Update Email</button>
                </div>
            </form>
        </div>

        <!-- Password Settings -->
        <div class="preference-card">
            <div class="card-header">
                <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                </svg>
                <h2>Change Password</h2>
            </div>

            <form method="POST" action="">
                <div class="form-group">
                    <label for="current_password_pw">Current Password</label>
                    <input type="password" id="current_password_pw" name="current_password_pw" required placeholder="Enter your current password">
                </div>
                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <input type="password" id="new_password" name="new_password" required placeholder="Minimum 8 characters" minlength="8">
                </div>
                <div class="form-group">
                    <label for="confirm_password">Confirm New Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" required placeholder="Re-enter new password">
                </div>
                <div class="form-actions">
                    <button type="submit" name="update_password" class="btn btn-primary">Update Password</button>
                </div>
            </form>
        </div>

        <!-- Phone Settings -->
        <div class="preference-card">
            <div class="card-header">
                <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                </svg>
                <h2>Phone Number</h2>
            </div>

            <div class="current-value">
                <strong>Current Phone:</strong>
                <span><?php echo $staff['phone'] ? format_phone($staff['phone']) : 'Not set'; ?></span>
            </div>

            <form method="POST" action="">
                <div class="form-group">
                    <label for="phone">Phone Number</label>
                    <input type="tel" id="phone" name="phone" placeholder="(555) 555-5555" value="<?php echo htmlspecialchars($staff['phone'] ? format_phone($staff['phone']) : ''); ?>">
                    <small style="color: #666; display: block; margin-top: 0.25rem;">Leave blank to remove phone number</small>
                </div>
                <div class="form-actions">
                    <button type="submit" name="update_phone" class="btn btn-primary">Update Phone</button>
                </div>
            </form>
        </div>

        <!-- TOTP Two-Factor Authentication -->
        <div class="preference-card">
            <div class="card-header">
                <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                </svg>
                <h2>Two-Factor Authentication (TOTP)</h2>
            </div>

            <div class="current-value">
                <strong>Status:</strong>
                <?php if ($staff['totp_enabled']): ?>
                    <span class="status-badge enabled">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
                        </svg>
                        Enabled
                    </span>
                <?php else: ?>
                    <span class="status-badge disabled">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                        Disabled
                    </span>
                <?php endif; ?>
            </div>

            <?php if (!$staff['totp_enabled']): ?>
                <?php if (isset($_SESSION['temp_totp_secret'])): ?>
                    <?php
                    $totp = OTPHP\TOTP::createFromSecret($_SESSION['temp_totp_secret']);
                    $totp->setLabel($staff['email']);
                    $totp->setIssuer(COMPANY_NAME);
                    $qr_url = $totp->getQrCodeUri('https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=[DATA]', '[DATA]');
                    ?>

                    <div class="totp-setup">
                        <div class="qr-section">
                            <p><strong>Step 1:</strong> Scan this QR code with your authenticator app</p>
                            <img src="<?php echo htmlspecialchars($qr_url); ?>" alt="QR Code">
                            <div class="manual-entry">
                                <strong>Or enter manually:</strong>
                                <br>
                                <code><?php echo htmlspecialchars($_SESSION['temp_totp_secret']); ?></code>
                            </div>
                        </div>

                        <div>
                            <p><strong>Step 2:</strong> Enter the 6-digit code from your app</p>
                            <form method="POST" action="">
                                <input type="hidden" name="totp_secret" value="<?php echo htmlspecialchars($_SESSION['temp_totp_secret']); ?>">
                                <div class="form-group">
                                    <label for="totp_code">Verification Code</label>
                                    <input type="text" id="totp_code" name="totp_code" required placeholder="000000" maxlength="6" pattern="[0-9]{6}">
                                </div>
                                <div class="form-actions">
                                    <button type="submit" name="enable_totp" class="btn btn-success">Enable 2FA</button>
                                    <a href="preferences.php" class="btn btn-secondary">Cancel</a>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php else: ?>
                    <p style="color: #666; margin-bottom: var(--spacing-md);">
                        Two-factor authentication adds an extra layer of security to your account.
                        You'll need an authenticator app like Google Authenticator or Authy.
                    </p>
                    <form method="POST" action="">
                        <button type="submit" name="generate_totp" class="btn btn-primary">Setup Two-Factor Authentication</button>
                    </form>
                <?php endif; ?>
            <?php else: ?>
                <p style="color: #666; margin-bottom: var(--spacing-md);">
                    Two-factor authentication is currently enabled for your account.
                    You'll be prompted for a code from your authenticator app when you log in.
                </p>
                <form method="POST" action="">
                    <div class="form-group">
                        <label for="current_password_totp">Current Password (to confirm)</label>
                        <input type="password" id="current_password_totp" name="current_password_totp" required placeholder="Enter your current password">
                    </div>
                    <div class="form-actions">
                        <button type="submit" name="disable_totp" class="btn btn-danger">Disable Two-Factor Authentication</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>

        <!-- Email 2FA Settings -->
        <div class="preference-card">
            <div class="card-header">
                <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                </svg>
                <h2>Email 2FA</h2>
            </div>

            <div class="current-value">
                <strong>Status:</strong>
                <?php if ($staff['email_2fa_enabled']): ?>
                    <span class="status-badge enabled">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
                        </svg>
                        Enabled
                    </span>
                <?php else: ?>
                    <span class="status-badge disabled">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                        Disabled
                    </span>
                <?php endif; ?>
            </div>

            <p style="color: #666; margin-bottom: var(--spacing-md);">
                Receive verification codes via email when logging in.
                <?php if (empty($staff['email'])): ?>
                    <br><strong style="color: var(--color-danger);">You must set an email address first.</strong>
                <?php endif; ?>
            </p>

            <form method="POST" action="">
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="email_2fa_enabled" value="1" <?php echo $staff['email_2fa_enabled'] ? 'checked' : ''; ?> <?php echo empty($staff['email']) ? 'disabled' : ''; ?>>
                        <span>Enable Email 2FA</span>
                    </label>
                </div>
                <div class="form-actions">
                    <button type="submit" name="toggle_email_2fa" class="btn btn-primary" <?php echo empty($staff['email']) ? 'disabled' : ''; ?>>
                        Save Email 2FA Settings
                    </button>
                </div>
            </form>
        </div>

        <!-- SMS 2FA Settings -->
        <div class="preference-card">
            <div class="card-header">
                <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
                </svg>
                <h2>SMS 2FA</h2>
            </div>

            <div class="current-value">
                <strong>Status:</strong>
                <?php if ($staff['sms_2fa_enabled']): ?>
                    <span class="status-badge enabled">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
                        </svg>
                        Enabled
                    </span>
                <?php else: ?>
                    <span class="status-badge disabled">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                        Disabled
                    </span>
                <?php endif; ?>
            </div>

            <p style="color: #666; margin-bottom: var(--spacing-md);">
                Receive verification codes via text message when logging in.
                <?php if (empty($staff['phone'])): ?>
                    <br><strong style="color: var(--color-danger);">You must set a phone number first.</strong>
                <?php else: ?>
                    <br><strong style="color: var(--color-warning);">Note: SMS 2FA is coming soon.</strong>
                <?php endif; ?>
            </p>

            <form method="POST" action="">
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="sms_2fa_enabled" value="1" <?php echo $staff['sms_2fa_enabled'] ? 'checked' : ''; ?> <?php echo empty($staff['phone']) ? 'disabled' : ''; ?>>
                        <span>Enable SMS 2FA</span>
                    </label>
                </div>
                <div class="form-actions">
                    <button type="submit" name="toggle_sms_2fa" class="btn btn-primary" <?php echo empty($staff['phone']) ? 'disabled' : ''; ?>>
                        Save SMS 2FA Settings
                    </button>
                </div>
            </form>
        </div>

        <!-- XactoAuth SSO -->
        <div class="preference-card">
            <div class="card-header">
                <h2>XactoAuth Single Sign-On</h2>
                <?php if (!empty($staff['xactoauth_user_id'])): ?>
                    <span class="badge badge-success">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                        </svg>
                        Linked
                    </span>
                <?php else: ?>
                    <span class="badge badge-danger">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                        Not Linked
                    </span>
                <?php endif; ?>
            </div>

            <p style="color: #666; margin-bottom: var(--spacing-md);">
                Link your XactoAuth account to enable single sign-on across all TransXacto applications. Once linked, you can use one account to access EnrollMagic and all other TransXacto services.
            </p>

            <?php if (isset($_SESSION['xactoauth_pending_link']) && isset($_GET['xactoauth_link'])): ?>
                <div class="alert alert-info" style="margin-bottom: var(--spacing-md);">
                    <strong>XactoAuth Account Ready to Link</strong>
                    <p style="margin: 0.5rem 0 0 0;">
                        You've successfully authenticated with XactoAuth as <strong><?php echo htmlspecialchars($_SESSION['xactoauth_user']['email'] ?? 'Unknown'); ?></strong>.
                        Click the button below to link this XactoAuth account to your EnrollMagic profile.
                    </p>
                </div>
                <form method="POST" action="">
                    <button type="submit" name="link_xactoauth" class="btn btn-primary">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="display: inline-block; vertical-align: middle; margin-right: 0.5rem;">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                        </svg>
                        Link XactoAuth Account
                    </button>
                </form>
            <?php elseif (!empty($staff['xactoauth_user_id'])): ?>
                <div class="alert alert-success" style="margin-bottom: var(--spacing-md);">
                    <strong>Account Linked</strong>
                    <p style="margin: 0.5rem 0 0 0;">
                        Your XactoAuth account is linked. You can now use XactoAuth to log in to EnrollMagic and access all your TransXacto applications from one place.
                    </p>
                </div>
                <form method="POST" action="" onsubmit="return confirm('Are you sure you want to unlink your XactoAuth account? You will need to use your password to log in.');">
                    <button type="submit" name="unlink_xactoauth" class="btn btn-danger">
                        Unlink XactoAuth Account
                    </button>
                </form>
            <?php else: ?>
                <a href="xactoauth-login.php" class="btn btn-primary">
                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="display: inline-block; vertical-align: middle; margin-right: 0.5rem;">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1" />
                    </svg>
                    Login with XactoAuth to Link Account
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// Phone number formatting
document.getElementById('phone')?.addEventListener('input', function(e) {
    let value = e.target.value.replace(/\D/g, '');
    if (value.length > 10) value = value.substr(0, 10);

    if (value.length >= 6) {
        e.target.value = '(' + value.substr(0,3) + ') ' + value.substr(3,3) + '-' + value.substr(6);
    } else if (value.length >= 3) {
        e.target.value = '(' + value.substr(0,3) + ') ' + value.substr(3);
    } else {
        e.target.value = value;
    }
});

// Auto-focus TOTP code field
document.getElementById('totp_code')?.focus();
</script>

<?php
// Clear temp TOTP secret after enabling
if (isset($_POST['enable_totp']) && empty($errors)) {
    unset($_SESSION['temp_totp_secret']);
}

include __DIR__ . '/../src/footer.php';
?>
