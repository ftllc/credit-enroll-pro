<?php
/**
 * Spouse Enrollment - Verification Page
 * Sends verification codes to spouse's email and phone, validates them
 */

define('SNS_ENROLLMENT', true);
require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/email_helper.php';

session_start();

if (!isset($_SESSION['spouse_enrollment_session_id'])) {
    header('Location: ' . BASE_URL . '/spouse/');
    exit;
}

// Get spouse enrollment
$stmt = $pdo->prepare("SELECT * FROM spouse_enrollments WHERE session_id = ?");
$stmt->execute([$_SESSION['spouse_enrollment_session_id']]);
$spouse_enrollment = $stmt->fetch();

if (!$spouse_enrollment) {
    session_destroy();
    header('Location: ' . BASE_URL . '/spouse/');
    exit;
}

// Get primary enrollment data
$stmt = $pdo->prepare("SELECT * FROM enrollment_users WHERE id = ?");
$stmt->execute([$spouse_enrollment['primary_enrollment_id']]);
$primary_enrollment = $stmt->fetch();

if (!$primary_enrollment) {
    session_destroy();
    header('Location: ' . BASE_URL . '/spouse/');
    exit;
}

// If already verified, skip to info confirmation
if ($spouse_enrollment['verified']) {
    header('Location: ' . BASE_URL . '/spouse/confirm-info.php');
    exit;
}

$errors = [];
$verification_sent = false;
$step = isset($_GET['step']) ? $_GET['step'] : 'send'; // 'send' or 'validate'

// Debug: Log page load
log_activity("Spouse verify.php loaded - Step: {$step}, Session: " . ($spouse_enrollment['session_id'] ?? 'none'), 'INFO');

// Handle sending verification codes
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 'send') {
    log_activity("POST received - sending verification codes", 'INFO');
    // Generate single verification code for both email and phone
    $verification_code = generate_2fa_code(6);
    $expiry_minutes = defined('2FA_CODE_EXPIRY_MINUTES') ? constant('2FA_CODE_EXPIRY_MINUTES') : 15;
    // Use gmdate for UTC time since MySQL NOW() returns UTC
    $expiry = gmdate('Y-m-d H:i:s', strtotime('+' . $expiry_minutes . ' minutes'));

    try {
        // Delete any existing codes for this spouse enrollment
        $stmt = $pdo->prepare("DELETE FROM 2fa_codes WHERE identifier LIKE ? AND identifier_type = 'session_id'");
        $stmt->execute([$spouse_enrollment['session_id'] . '%']);

        // Insert single verification code
        $stmt = $pdo->prepare("
            INSERT INTO 2fa_codes (code, identifier, identifier_type, purpose, expires_at, attempts)
            VALUES (?, ?, 'session_id', 'verify_contact', ?, 0)
        ");
        $stmt->execute([$verification_code, $spouse_enrollment['session_id'], $expiry]);

        // Send email with verification code
        $spouse_name = $primary_enrollment['spouse_first_name'] . ' ' . $primary_enrollment['spouse_last_name'];
        $email_result = send_2fa_code_email($primary_enrollment['spouse_email'], $verification_code, $spouse_name);

        if ($email_result['success']) {
            log_activity("Spouse verification email sent to {$primary_enrollment['spouse_email']} with code: {$verification_code}", 'INFO');
        } else {
            log_activity("Failed to send spouse verification email: " . ($email_result['error'] ?? 'Unknown error'), 'ERROR');
        }

        // Load company name from database for SMS
        $company_name_sms = COMPANY_NAME; // Fallback
        try {
            $stmt_company = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'company_name' LIMIT 1");
            $stmt_company->execute();
            $result_company = $stmt_company->fetch();
            if ($result_company && !empty($result_company['setting_value'])) {
                $company_name_sms = $result_company['setting_value'];
            }
        } catch (Exception $e) {
            // Use fallback if query fails
        }

        // Send SMS with verification code using direct API call
        $sms_message = "Your " . $company_name_sms . " verification code is: {$verification_code}. Code expires in {$expiry_minutes} minutes.";

        // Get VoIP.ms config
        try {
            $voipms_stmt = $pdo->prepare("SELECT * FROM api_keys WHERE service_name = 'voipms'");
            $voipms_stmt->execute();
            $voipms_config = $voipms_stmt->fetch();

            if ($voipms_config && $voipms_config['is_enabled']) {
                $config = json_decode($voipms_config['additional_config'], true);
                $api_user = $config['username'] ?? '';
                $api_pass = $config['password'] ?? '';
                $did = $config['did'] ?? '';

                $from = clean_phone($did);
                $to = clean_phone($primary_enrollment['spouse_phone']);

                if (!empty($api_user) && !empty($api_pass) && !empty($from) && !empty($to)) {
                    // Build API URL
                    $url = 'https://voip.ms/api/v1/rest.php?' . http_build_query([
                        'api_username' => $api_user,
                        'api_password' => $api_pass,
                        'method' => 'sendSMS',
                        'did' => $from,
                        'dst' => $to,
                        'message' => $sms_message
                    ]);

                    // Make API request
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

                    $response = curl_exec($ch);
                    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);

                    $result = json_decode($response, true);

                    if ($http_code === 200 && isset($result['status']) && $result['status'] === 'success') {
                        log_activity("Spouse verification SMS sent to {$primary_enrollment['spouse_phone']} with code: {$verification_code}", 'INFO');
                        $sms_result = ['status' => 'success'];
                    } else {
                        log_activity("Failed to send spouse verification SMS: HTTP {$http_code}, Response: {$response}", 'ERROR');
                        $sms_result = ['status' => 'error', 'message' => 'SMS send failed'];
                    }
                } else {
                    log_activity("VoIP.ms not configured properly", 'ERROR');
                    $sms_result = ['status' => 'error', 'message' => 'SMS not configured'];
                }
            } else {
                log_activity("VoIP.ms not enabled", 'ERROR');
                $sms_result = ['status' => 'error', 'message' => 'SMS not enabled'];
            }
        } catch (Exception $e) {
            log_activity("SMS sending error: " . $e->getMessage(), 'ERROR');
            $sms_result = ['status' => 'error', 'message' => 'SMS error'];
        }

        if (isset($sms_result['status']) && ($sms_result['status'] === 'success' || $sms_result['status'] === 'partial_failure')) {
            log_activity("Spouse verification SMS sent to {$primary_enrollment['spouse_phone']} with code: {$verification_code}", 'INFO');
        } else {
            log_activity("Failed to send spouse verification SMS: " . ($sms_result['message'] ?? 'Unknown error'), 'ERROR');
        }

        $verification_sent = true;
        $_SESSION['verification_sent'] = true;

        // Redirect to validation step
        header('Location: ' . BASE_URL . '/spouse/verify.php?step=validate');
        exit;

    } catch (PDOException $e) {
        $errors[] = 'An error occurred. Please try again.';
        if (DEBUG_MODE) $errors[] = $e->getMessage();
    }
}

// Handle validating verification code
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 'validate') {
    $code_input = trim($_POST['verification_code'] ?? '');

    if (empty($code_input)) {
        $errors[] = 'Verification code is required';
    }

    if (empty($errors)) {
        // Verify code
        $stmt = $pdo->prepare("
            SELECT * FROM 2fa_codes
            WHERE identifier = ? AND identifier_type = 'session_id' AND expires_at > NOW()
        ");
        $stmt->execute([$spouse_enrollment['session_id']]);
        $code_record = $stmt->fetch();

        $code_valid = $code_record && $code_record['code'] === $code_input;

        if (!$code_valid) {
            // Increment attempts
            if ($code_record) {
                $stmt = $pdo->prepare("UPDATE 2fa_codes SET attempts = attempts + 1 WHERE id = ?");
                $stmt->execute([$code_record['id']]);
            }

            $errors[] = 'Invalid verification code. Please check and try again.';
        } else {
            // Mark as verified
            $stmt = $pdo->prepare("
                UPDATE spouse_enrollments
                SET verified = 1, current_step = 2, last_activity = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([$spouse_enrollment['id']]);

            // Mark code as verified
            $stmt = $pdo->prepare("
                UPDATE 2fa_codes
                SET verified = 1, verified_at = CURRENT_TIMESTAMP
                WHERE identifier = ? AND identifier_type = 'session_id'
            ");
            $stmt->execute([$spouse_enrollment['session_id']]);

            log_activity("Spouse enrollment {$spouse_enrollment['session_id']} verified successfully", 'INFO');

            header('Location: ' . BASE_URL . '/spouse/confirm-info.php');
            exit;
        }
    }
}

$page_title = 'Verify Your Identity';
include __DIR__ . '/../src/header.php';
?>

<style>
body { background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%); min-height: 100vh; }
.main-container { min-height: calc(100vh - 100px); display: flex; align-items: center; justify-content: center; padding: 2rem 1rem; }
.enroll-wrapper { max-width: 600px; width: 100%; animation: slideUp 0.5s ease-out; }
@keyframes slideUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
.enroll-card { background: white; border-radius: 24px; box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15); overflow: hidden; }
.card-hero { background: linear-gradient(135deg, var(--color-primary) 0%, #854d37 100%); color: white; padding: 3rem 2.5rem; text-align: center; position: relative; }
.card-hero::after { content: ''; position: absolute; bottom: 0; left: 0; right: 0; height: 30px; background: white; border-radius: 24px 24px 0 0; }
.card-hero h1 { font-size: 32px; font-weight: 700; margin: 0 0 0.75rem; color: white; }
.card-hero p { margin: 0; font-size: 16px; opacity: 0.95; line-height: 1.6; }
.card-body-modern { padding: 2.5rem; }
.info-box { background: #f0f9ff; border: 2px solid #bae6fd; border-radius: 14px; padding: 1.5rem; margin-bottom: 2rem; }
.info-box p { margin: 0 0 0.5rem 0; color: #0c4a6e; font-size: 15px; line-height: 1.6; }
.info-box p:last-child { margin-bottom: 0; }
.success-box { background: #f0fdf4; border: 2px solid #bbf7d0; border-radius: 14px; padding: 1.5rem; margin-bottom: 2rem; }
.success-box p { margin: 0; color: #166534; font-size: 15px; line-height: 1.6; }
.field-modern { position: relative; margin-bottom: 1.5rem; }
.field-modern input { width: 100%; padding: 1.5rem 1rem 0.5rem; border: 2px solid #e5e7eb; border-radius: 14px; font-size: 16px; transition: all 0.3s; background: #fafafa; font-family: var(--font-family); }
.field-modern input:focus { outline: none; border-color: var(--color-primary); background: white; box-shadow: 0 0 0 5px rgba(156, 96, 70, 0.1); }
.field-modern label { position: absolute; left: 1rem; top: 1.125rem; color: #9ca3af; font-size: 16px; pointer-events: none; transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1); }
.field-modern input:focus + label, .field-modern input:not(:placeholder-shown) + label { top: 0.5rem; font-size: 11px; font-weight: 600; color: var(--color-primary); }
.btn-fancy { width: 100%; padding: 1.125rem 2rem; border: none; border-radius: 14px; font-size: 16px; font-weight: 600; cursor: pointer; transition: all 0.3s; display: flex; align-items: center; justify-content: center; gap: 0.625rem; background: linear-gradient(135deg, var(--color-primary) 0%, #854d37 100%); color: white; box-shadow: 0 4px 14px rgba(156, 96, 70, 0.35); }
.btn-fancy:hover { transform: translateY(-2px); box-shadow: 0 6px 24px rgba(156, 96, 70, 0.45); }
.error-box { background: #fef2f2; border: 2px solid #fecaca; border-radius: 14px; padding: 1rem 1.5rem; margin-bottom: 1.5rem; }
.error-box ul { margin: 0; padding-left: 1.25rem; color: #991b1b; }
.masked-info { font-family: monospace; font-weight: 600; }
</style>

<div class="enroll-wrapper">
    <div class="enroll-card">
        <div class="card-hero">
            <h1>Verify Your Identity</h1>
            <p>For security, we need to verify your email and phone number</p>
        </div>

        <div class="card-body-modern">
            <?php if (!empty($errors)): ?>
                <div class="error-box">
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ($step === 'send'): ?>
                <div class="info-box">
                    <p>
                        <strong>We will send verification codes to:</strong><br>
                        Email: <span class="masked-info"><?php echo htmlspecialchars($primary_enrollment['spouse_email']); ?></span><br>
                        Phone: <span class="masked-info"><?php echo format_phone($primary_enrollment['spouse_phone']); ?></span>
                    </p>
                </div>

                <form method="POST" action="">
                    <button type="submit" class="btn-fancy">
                        Send Verification Codes
                        <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                        </svg>
                    </button>
                </form>

            <?php else: ?>
                <?php if (isset($_SESSION['verification_sent']) && $_SESSION['verification_sent']): ?>
                    <div class="success-box">
                        <p>
                            <strong>Verification code sent!</strong><br>
                            Please check your email and text messages for the same 6-digit verification code.
                        </p>
                    </div>
                    <?php unset($_SESSION['verification_sent']); ?>
                <?php endif; ?>

                <div class="info-box">
                    <p>
                        Enter the 6-digit code sent to your email and phone number.<br>
                        The same code was sent to both. Codes expire in <?php echo defined('2FA_CODE_EXPIRY_MINUTES') ? constant('2FA_CODE_EXPIRY_MINUTES') : 15; ?> minutes.
                    </p>
                </div>

                <form method="POST" action="?step=validate">
                    <div class="field-modern">
                        <input type="text" name="verification_code" id="verification_code" required placeholder=" "
                               pattern="[0-9]{6}" maxlength="6" autocomplete="off">
                        <label>Verification Code *</label>
                    </div>

                    <button type="submit" class="btn-fancy">
                        Verify
                        <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// Only allow numbers in verification code field
const codeInput = document.getElementById('verification_code');
if (codeInput) {
    codeInput.addEventListener('input', function(e) {
        this.value = this.value.replace(/[^0-9]/g, '');
    });
}
</script>

<?php include __DIR__ . '/../src/footer.php'; ?>
