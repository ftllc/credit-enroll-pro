<?php
/**
 * Continue Enrollment - Verification Page
 * Sends verification codes to user's email and phone, validates them, then restores session
 */

define('SNS_ENROLLMENT', true);
require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/email_helper.php';

session_start();

if (!isset($_SESSION['continue_enrollment_id'])) {
    header('Location: ' . BASE_URL . '/continue/login.php');
    exit;
}

// Get enrollment
$stmt = $pdo->prepare("SELECT * FROM enrollment_users WHERE id = ?");
$stmt->execute([$_SESSION['continue_enrollment_id']]);
$enrollment = $stmt->fetch();

if (!$enrollment) {
    session_destroy();
    header('Location: ' . BASE_URL . '/continue/login.php');
    exit;
}

$errors = [];
$step = isset($_GET['step']) ? $_GET['step'] : 'send';

log_activity("Continue verify.php loaded - Step: {$step}, Session: " . ($enrollment['session_id'] ?? 'none'), 'INFO');

// Handle sending verification codes
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 'send') {
    $verification_code = generate_2fa_code(6);
    $expiry_minutes = defined('2FA_CODE_EXPIRY_MINUTES') ? constant('2FA_CODE_EXPIRY_MINUTES') : 15;
    $expiry = gmdate('Y-m-d H:i:s', strtotime('+' . $expiry_minutes . ' minutes'));

    try {
        // Delete any existing codes for this enrollment
        $stmt = $pdo->prepare("DELETE FROM 2fa_codes WHERE identifier LIKE ? AND identifier_type = 'session_id'");
        $stmt->execute([$enrollment['session_id'] . '%']);

        // Insert verification code
        $stmt = $pdo->prepare("
            INSERT INTO 2fa_codes (code, identifier, identifier_type, purpose, expires_at, attempts)
            VALUES (?, ?, 'session_id', 'verify_contact', ?, 0)
        ");
        $stmt->execute([$verification_code, $enrollment['session_id'], $expiry]);

        // Send email
        $user_name = $enrollment['first_name'] . ' ' . $enrollment['last_name'];
        $email_result = send_2fa_code_email($enrollment['email'], $verification_code, $user_name);

        if ($email_result['success']) {
            log_activity("Continue verification email sent to {$enrollment['email']} with code: {$verification_code}", 'INFO');
        } else {
            log_activity("Failed to send continue verification email: " . ($email_result['error'] ?? 'Unknown error'), 'ERROR');
        }

        // Get company name for SMS
        $company_name_sms = COMPANY_NAME;
        try {
            $stmt_company = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'company_name' LIMIT 1");
            $stmt_company->execute();
            $result_company = $stmt_company->fetch();
            if ($result_company && !empty($result_company['setting_value'])) {
                $company_name_sms = $result_company['setting_value'];
            }
        } catch (Exception $e) {
            // Use fallback
        }

        // Send SMS
        $sms_message = "Your " . $company_name_sms . " verification code is: {$verification_code}. Code expires in {$expiry_minutes} minutes.";

        $stmt = $pdo->prepare("SELECT is_enabled, additional_config FROM api_keys WHERE service_name = 'voipms' LIMIT 1");
        $stmt->execute();
        $voipms_api = $stmt->fetch();

        $sms_sent = false;
        if ($voipms_api && $voipms_api['is_enabled']) {
            $config = json_decode($voipms_api['additional_config'], true);
            if (!empty($config['username']) && !empty($config['password']) && !empty($config['did'])) {
                $url = 'https://voip.ms/api/v1/rest.php?' . http_build_query([
                    'api_username' => $config['username'],
                    'api_password' => $config['password'],
                    'method' => 'sendSMS',
                    'did' => $config['did'],
                    'dst' => $enrollment['phone'],
                    'message' => $sms_message
                ]);

                $response = file_get_contents($url);
                $result = json_decode($response, true);
                $sms_sent = ($result['status'] === 'success');
                log_activity("Continue SMS sent to {$enrollment['phone']}: " . ($sms_sent ? 'success' : 'failed'), 'INFO');
            }
        }

        header('Location: ' . BASE_URL . '/continue/verify.php?step=validate');
        exit;

    } catch (Exception $e) {
        $errors[] = 'Failed to send verification codes. Please try again.';
        log_activity("Continue verification error: " . $e->getMessage(), 'ERROR');
    }
}

// Handle validating verification codes
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 'validate') {
    $entered_code = trim($_POST['verification_code'] ?? '');

    if (empty($entered_code)) {
        $errors[] = 'Please enter the verification code';
    } else {
        $stmt = $pdo->prepare("
            SELECT * FROM 2fa_codes
            WHERE identifier = ? AND identifier_type = 'session_id'
            AND purpose = 'verify_contact'
            AND expires_at > UTC_TIMESTAMP()
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$enrollment['session_id']]);
        $code_record = $stmt->fetch();

        if (!$code_record) {
            $errors[] = 'Verification code has expired. Please request a new one.';
        } elseif ($code_record['attempts'] >= MAX_2FA_ATTEMPTS) {
            $errors[] = 'Too many attempts. Please request a new verification code.';
        } elseif ($code_record['code'] !== $entered_code) {
            $stmt = $pdo->prepare("UPDATE 2fa_codes SET attempts = attempts + 1 WHERE id = ?");
            $stmt->execute([$code_record['id']]);
            $remaining = MAX_2FA_ATTEMPTS - ($code_record['attempts'] + 1);
            $errors[] = "Invalid verification code. {$remaining} attempts remaining.";
        } else {
            // Code is valid - delete it and restore the session
            $stmt = $pdo->prepare("DELETE FROM 2fa_codes WHERE id = ?");
            $stmt->execute([$code_record['id']]);

            // Restore the original enrollment session
            $_SESSION['enrollment_id'] = $enrollment['id'];
            $_SESSION['enrollment_session_id'] = $enrollment['session_id'];
            
            // Clear continue session vars
            unset($_SESSION['continue_enrollment_id']);
            unset($_SESSION['continue_session_id']);
            unset($_SESSION['continue_lookup_method']);

            log_activity("Continue enrollment verified for enrollment ID {$enrollment['id']}, restoring session", 'INFO');

            // Determine which step to redirect to based on current_step (handle both numeric and string)
            // Flow: index(1) → questions(3) → address(2) → plan(4) → personal(5) → contracts(6) → review(7)
            $redirect_url = BASE_URL . '/enroll/';

            switch ($enrollment['current_step']) {
                case 2:
                case '2':
                case 'address':
                    $redirect_url = BASE_URL . '/enroll/address.php';
                    break;
                case 3:
                case '3':
                case 'questions':
                    $redirect_url = BASE_URL . '/enroll/questions.php';
                    break;
                case 4:
                case '4':
                case 'plan':
                    $redirect_url = BASE_URL . '/enroll/plan.php';
                    break;
                case 5:
                case '5':
                case 'personal':
                case 'spouse':
                    $redirect_url = BASE_URL . '/enroll/personal.php';
                    break;
                case 6:
                case '6':
                case 'contracts':
                    $redirect_url = BASE_URL . '/enroll/contracts.php';
                    break;
                case 7:
                case '7':
                case 'review':
                    $redirect_url = BASE_URL . '/enroll/review.php';
                    break;
                default:
                    // Step 1 or null - start from beginning
                    $redirect_url = BASE_URL . '/enroll/';
                    break;
            }

            header('Location: ' . $redirect_url);
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
.info-box p { margin: 0.5rem 0; color: #0c4a6e; font-size: 15px; line-height: 1.6; }
.field-modern { position: relative; margin-bottom: 1.5rem; }
.field-modern input { width: 100%; padding: 1.5rem 1rem 0.5rem; border: 2px solid #e5e7eb; border-radius: 14px; font-size: 16px; transition: all 0.3s; background: #fafafa; font-family: var(--font-family); text-align: center; letter-spacing: 0.25rem; font-weight: 600; }
.field-modern input:focus { outline: none; border-color: var(--color-primary); background: white; box-shadow: 0 0 0 5px rgba(156, 96, 70, 0.1); }
.field-modern label { position: absolute; left: 1rem; top: 1.125rem; color: #9ca3af; font-size: 16px; pointer-events: none; transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1); }
.field-modern input:focus + label, .field-modern input:not(:placeholder-shown) + label { top: 0.5rem; font-size: 11px; font-weight: 600; color: var(--color-primary); }
.btn-fancy { width: 100%; padding: 1.125rem 2rem; border: none; border-radius: 14px; font-size: 16px; font-weight: 600; cursor: pointer; transition: all 0.3s; display: flex; align-items: center; justify-content: center; gap: 0.625rem; background: linear-gradient(135deg, var(--color-primary) 0%, #854d37 100%); color: white; box-shadow: 0 4px 14px rgba(156, 96, 70, 0.35); }
.btn-fancy:hover { transform: translateY(-2px); box-shadow: 0 6px 24px rgba(156, 96, 70, 0.45); }
.error-box { background: #fef2f2; border: 2px solid #fecaca; border-radius: 14px; padding: 1rem 1.5rem; margin-bottom: 1.5rem; }
.error-box ul { margin: 0; padding-left: 1.25rem; color: #991b1b; }
.help-text { text-align: center; margin-top: 1.5rem; color: #6b7280; font-size: 14px; }
.resend-link { text-align: center; margin-top: 1rem; }
.resend-link a { color: var(--color-primary); text-decoration: none; font-weight: 600; }
.resend-link a:hover { text-decoration: underline; }
</style>

<div class="enroll-wrapper">
    <div class="enroll-card">
        <div class="card-hero">
            <h1>Verify Your Identity</h1>
            <p>For security, we need to verify it's really you.</p>
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
                    <p><strong>Hello, <?php echo htmlspecialchars($enrollment['first_name']); ?>!</strong></p>
                    <p>We'll send a verification code to:</p>
                    <p>
                        📧 <strong><?php echo htmlspecialchars($enrollment['email']); ?></strong><br>
                        📱 <strong><?php echo format_phone($enrollment['phone']); ?></strong>
                    </p>
                </div>

                <form method="POST" action="">
                    <button type="submit" class="btn-fancy">
                        Send Verification Code
                        <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                        </svg>
                    </button>
                </form>

            <?php else: ?>
                <div class="info-box">
                    <p>We've sent a 6-digit verification code to your email and phone.</p>
                    <p>Please check your messages and enter the code below.</p>
                </div>

                <form method="POST" action="">
                    <div class="field-modern">
                        <input type="text" name="verification_code" id="verification_code" required placeholder=" " autocomplete="off" maxlength="6" pattern="[0-9]{6}">
                        <label>6-Digit Verification Code *</label>
                    </div>

                    <button type="submit" class="btn-fancy">
                        Verify & Continue
                        <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                        </svg>
                    </button>
                </form>

                <div class="resend-link">
                    <a href="<?php echo BASE_URL; ?>/continue/verify.php?step=send">Resend Verification Code</a>
                </div>
            <?php endif; ?>

            <div class="help-text">
                Need help? Contact our support team for assistance.
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../src/footer.php'; ?>
