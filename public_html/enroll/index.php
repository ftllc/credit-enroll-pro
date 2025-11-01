<?php
/**
 * Enrollment Step 1: Welcome - Basic Contact Information
 * Collects: First Name, Last Name, Email, Phone, Referral Code
 */

define('SNS_ENROLLMENT', true);
require_once __DIR__ . '/../src/config.php';

session_start();

// Check if user already has an active enrollment session
if (isset($_SESSION['enrollment_session_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM enrollment_users WHERE session_id = ?");
    $stmt->execute([$_SESSION['enrollment_session_id']]);
    $existing_enrollment = $stmt->fetch();

    if ($existing_enrollment && $existing_enrollment['status'] !== 'abandoned' && $existing_enrollment['status'] !== 'cancelled') {
        // Redirect to their current step (only if they've progressed past this page)
        $redirect_url = null;

        // Handle both numeric and string step values
        // Flow: index(1) → questions(3) → address(2) → plan(4) → personal(5) → contracts(6) → review(7)
        switch ($existing_enrollment['current_step']) {
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
        }

        // Only redirect if there's a valid step (don't redirect if current_step is null or 1)
        if ($redirect_url) {
            header('Location: ' . $redirect_url);
            exit;
        }
    }
}

// Check if enrollments are enabled and get admin email
$stmt = $pdo->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('enrollments_enabled', 'admin_email')");
$stmt->execute();
$settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$enrollments_enabled = $settings['enrollments_enabled'] ?? 'false';
$admin_email = $settings['admin_email'] ?? 'admin@example.com';

$enrollment_disabled = ($enrollments_enabled !== 'true' && $enrollments_enabled !== '1');

// Handle form submission
$errors = [];
$form_data = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = clean_phone($_POST['phone'] ?? '');
    $affiliate_code = trim($_POST['affiliate_code'] ?? '');
    $recaptcha_token = $_POST['recaptcha_token'] ?? '';

    // Verify reCAPTCHA
    if (RECAPTCHA_ENABLED && !empty($recaptcha_token)) {
        $recaptcha_url = 'https://recaptchaenterprise.googleapis.com/v1/projects/' . RECAPTCHA_PROJECT_ID . '/assessments?key=' . RECAPTCHA_SECRET_KEY;

        $recaptcha_data = [
            'event' => [
                'token' => $recaptcha_token,
                'siteKey' => RECAPTCHA_SITE_KEY,
                'expectedAction' => 'enroll'
            ]
        ];

        $options = [
            'http' => [
                'header' => "Content-Type: application/json\r\n",
                'method' => 'POST',
                'content' => json_encode($recaptcha_data)
            ]
        ];

        $context = stream_context_create($options);
        $response = @file_get_contents($recaptcha_url, false, $context);

        if ($response) {
            $result = json_decode($response, true);
            $score = $result['riskAnalysis']['score'] ?? 0;

            // Log the score for monitoring
            log_activity("Enrollment reCAPTCHA score: {$score} for {$email}", 'INFO');

            // Require score of at least 0.5 (you can adjust this threshold)
            if ($score < 0.5) {
                $errors[] = 'Security verification failed. Please try again or contact support.';
                log_activity("Enrollment blocked - low reCAPTCHA score: {$score} for {$email}", 'WARNING');
            }
        } else {
            log_activity("Enrollment reCAPTCHA verification failed - no response for {$email}", 'ERROR');
            // Don't block on reCAPTCHA failure, just log it
        }
    }

    // Validation
    if (empty($first_name)) $errors[] = 'First name is required';
    if (empty($last_name)) $errors[] = 'Last name is required';
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email address is required';
    if (empty($phone) || strlen($phone) !== 10) $errors[] = 'Valid 10-digit phone number is required';

    // Check for existing enrollment
    if (empty($errors)) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM enrollment_users
            WHERE (email = ? OR phone = ?) AND status NOT IN ('abandoned', 'cancelled')
        ");
        $stmt->execute([$email, $phone]);
        if ($stmt->fetchColumn() > 0) {
            $errors[] = 'An enrollment already exists with this email or phone number.';
        }
    }

    // Validate affiliate code
    $validated_affiliate_code = null;
    if (!empty($affiliate_code) && empty($errors)) {
        $stmt = $pdo->prepare("SELECT affiliate_code FROM affiliates WHERE affiliate_code = ? AND is_active = 1");
        $stmt->execute([$affiliate_code]);
        $affiliate = $stmt->fetch();
        if ($affiliate) {
            $validated_affiliate_code = $affiliate['affiliate_code'];
        } else {
            $errors[] = 'Invalid referral code';
        }
    }

    // Create enrollment session
    if (empty($errors)) {
        try {
            $session_id = generate_session_id();
            $client_ip = get_client_ip();
            $referring_domain = get_referring_domain();

            $stmt = $pdo->prepare("
                INSERT INTO enrollment_users
                (session_id, first_name, last_name, email, phone,
                 current_step, status, ip_address, referring_domain, affiliate_code)
                VALUES (?, ?, ?, ?, ?, 1, 'in_progress', ?, ?, ?)
            ");

            $stmt->execute([
                $session_id, $first_name, $last_name, $email, $phone,
                $client_ip, $referring_domain, $validated_affiliate_code
            ]);

            $enrollment_id = $pdo->lastInsertId();

            // Create lead in Credit Repair Cloud
            $crc_result = crc_create_record([
                'first_name' => $first_name,
                'last_name' => $last_name,
                'email' => $email,
                'phone' => $phone
            ]);

            // Store CRC record ID if successful and set initial memo
            if ($crc_result && isset($crc_result['record_id'])) {
                $stmt = $pdo->prepare("UPDATE enrollment_users SET crc_record_id = ? WHERE id = ?");
                $stmt->execute([$crc_result['record_id'], $enrollment_id]);

                // Set initial memo
                crc_append_enrollment_memo($crc_result['record_id'], $enrollment_id, '=== NEW PORTAL ENROLLMENT ===\nStarted: ' . date('Y-m-d H:i:s'));
            }

            // Create contact in Systeme.io
            $systeme_result = systeme_create_contact([
                'first_name' => $first_name,
                'last_name' => $last_name,
                'email' => $email,
                'phone' => $phone
            ]);

            // Store Systeme.io contact ID if successful
            if ($systeme_result && isset($systeme_result['contact_id'])) {
                $stmt = $pdo->prepare("UPDATE enrollment_users SET systeme_contact_id = ? WHERE id = ?");
                $stmt->execute([$systeme_result['contact_id'], $enrollment_id]);
                log_activity("Systeme.io contact created for enrollment {$enrollment_id}: " . $systeme_result['contact_id'], 'INFO');
            }

            // Send New Lead (Staff Notification) - non-blocking
            try {
                require_once __DIR__ . '/../src/notification_helper.php';

                $notification_data = [
                    'client_name' => $first_name . ' ' . $last_name,
                    'client_first_name' => $first_name,
                    'client_last_name' => $last_name,
                    'client_email' => $email,
                    'client_phone' => format_phone($phone),
                    'enrollment_url' => BASE_URL . '/admin/enrollments.php?id=' . $enrollment_id
                ];

                $staff_result = notify_staff('new_lead_staff', 'notify_enrollment_started', $notification_data);
                log_activity("New Lead (Staff) notification sent to {$staff_result['staff_notified']} staff members", 'INFO');
            } catch (Exception $e) {
                log_activity("Error sending New Lead notification: " . $e->getMessage(), 'ERROR');
                // Don't block enrollment on notification failure
            }

            $_SESSION['enrollment_session_id'] = $session_id;
            $_SESSION['enrollment_id'] = $enrollment_id;

            // Check if questions are enabled
            $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'enrollment_questions_enabled'");
            $stmt->execute();
            $questions_enabled = $stmt->fetchColumn();

            if ($questions_enabled === 'true' || $questions_enabled === '1') {
                header('Location: ' . BASE_URL . '/enroll/questions.php');
            } else {
                header('Location: ' . BASE_URL . '/enroll/address.php');
            }
            exit;

        } catch (PDOException $e) {
            $errors[] = 'An error occurred. Please try again.';
            if (DEBUG_MODE) $errors[] = $e->getMessage();
        }
    }

    $form_data = compact('first_name', 'last_name', 'email', 'affiliate_code');
    if (!empty($phone)) $form_data['phone'] = format_phone($phone);
}

$page_title = 'Start Your Enrollment';
$include_recaptcha = true;
include __DIR__ . '/../src/header.php';
?>

<style>
body {
    background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
}

.main-container {
    min-height: calc(100vh - 200px);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 2rem 1rem;
}

.enroll-wrapper {
    max-width: 700px;
    width: 100%;
    animation: slideUp 0.5s ease-out;
}

@keyframes slideUp {
    from { opacity: 0; transform: translateY(30px); }
    to { opacity: 1; transform: translateY(0); }
}

.enroll-card {
    background: white;
    border-radius: 24px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
    overflow: hidden;
}

.card-hero {
    background: linear-gradient(135deg, var(--color-primary) 0%, #854d37 100%);
    color: white;
    padding: 3rem 2.5rem;
    text-align: center;
    position: relative;
}

.card-hero::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 30px;
    background: white;
    border-radius: 24px 24px 0 0;
}

.card-hero h1 {
    font-size: 32px;
    font-weight: 700;
    margin: 0 0 0.75rem;
    color: white;
}

.card-hero p {
    margin: 0;
    font-size: 16px;
    opacity: 0.95;
}

.card-body-modern {
    padding: 2.5rem;
}

.alert-fancy {
    background: linear-gradient(135deg, #fee 0%, #fdd 100%);
    border-left: 5px solid var(--color-danger);
    padding: 1.25rem;
    border-radius: 12px;
    margin-bottom: 2rem;
    animation: shake 0.3s;
}

@keyframes shake {
    0%, 100% { transform: translateX(0); }
    25% { transform: translateX(-10px); }
    75% { transform: translateX(10px); }
}

.alert-fancy strong {
    display: block;
    color: var(--color-danger);
    font-size: 16px;
    margin-bottom: 0.75rem;
}

.alert-fancy ul {
    margin: 0;
    padding-left: 1.5rem;
}

.alert-fancy li {
    margin-bottom: 0.5rem;
    color: #721c24;
}

.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.5rem;
}

.form-field-full {
    grid-column: 1 / -1;
}

.field-modern {
    position: relative;
    margin-bottom: 0.5rem;
}

.field-modern input {
    width: 100%;
    padding: 1.5rem 1rem 0.5rem;
    border: 2px solid #e5e7eb;
    border-radius: 14px;
    font-size: 16px;
    transition: all 0.3s;
    background: #fafafa;
    font-family: var(--font-family);
}

.field-modern input:focus {
    outline: none;
    border-color: var(--color-primary);
    background: white;
    box-shadow: 0 0 0 5px rgba(156, 96, 70, 0.1);
}

.field-modern label {
    position: absolute;
    left: 1rem;
    top: 1.125rem;
    color: #9ca3af;
    font-size: 16px;
    pointer-events: none;
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    background: transparent;
}

.field-modern input:focus + label,
.field-modern input:not(:placeholder-shown) + label,
.field-modern.has-value label {
    top: 0.5rem;
    font-size: 11px;
    font-weight: 600;
    color: var(--color-primary);
}

.field-hint {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 13px;
    color: #6b7280;
    margin-top: 0.5rem;
    margin-bottom: 1.5rem;
    padding-left: 0.5rem;
}

.field-hint svg {
    width: 16px;
    height: 16px;
    opacity: 0.7;
    flex-shrink: 0;
}

.form-actions-modern {
    display: flex;
    gap: 1rem;
    margin-top: 2.5rem;
    padding-top: 2rem;
    border-top: 2px solid #f3f4f6;
}

.btn-fancy {
    flex: 1;
    padding: 1.125rem 2rem;
    border: none;
    border-radius: 14px;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.625rem;
    text-decoration: none;
}

.btn-fancy-primary {
    background: linear-gradient(135deg, var(--color-primary) 0%, #854d37 100%);
    color: white;
    box-shadow: 0 4px 14px rgba(156, 96, 70, 0.35);
}

.btn-fancy-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 24px rgba(156, 96, 70, 0.45);
}

.btn-fancy-secondary {
    background: #f3f4f6;
    color: #6b7280;
}

.btn-fancy-secondary:hover {
    background: #e5e7eb;
}

.info-card {
    background: linear-gradient(135deg, #fafbfc 0%, #f0f1f3 100%);
    border-radius: 16px;
    padding: 1.75rem;
    margin-top: 2rem;
    border: 2px solid #e5e7eb;
}

.info-card h3 {
    color: var(--color-primary);
    font-size: 17px;
    margin: 0 0 1.125rem;
    display: flex;
    align-items: center;
    gap: 0.625rem;
}

.info-card ul {
    margin: 0;
    padding-left: 1.5rem;
    color: #4b5563;
    font-size: 14px;
    line-height: 1.8;
}

.info-card li {
    margin-bottom: 0.625rem;
}

@media (max-width: 768px) {
    .main-container {
        padding: 1rem;
    }

    .form-grid {
        grid-template-columns: 1fr;
    }

    .card-hero {
        padding: 2rem 1.5rem;
    }

    .card-hero h1 {
        font-size: 26px;
    }

    .card-body-modern {
        padding: 1.75rem;
    }

    .form-actions-modern {
        flex-direction: column-reverse;
    }
}
</style>

<div class="enroll-wrapper">
    <div class="enroll-card">
        <div class="card-hero">
            <h1>Welcome!</h1>
            <p>Let's begin your credit repair journey</p>
        </div>

        <div class="card-body-modern">
            <?php if ($enrollment_disabled): ?>
                <div style="text-align: center; padding: 2rem 1rem;">
                    <svg width="80" height="80" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color: #ffc107; margin-bottom: 1.5rem;">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                    <h2 style="color: var(--color-primary); margin-bottom: 1rem; font-size: 24px;">Enrollments Currently Unavailable</h2>
                    <p style="color: #6b7280; font-size: 16px; line-height: 1.6; margin-bottom: 1.5rem;">
                        We are not accepting new enrollments through our online system at this time.
                    </p>
                    <p style="color: #374151; font-size: 16px; margin-bottom: 1rem;">
                        To inquire about enrollment, please contact us at:
                    </p>
                    <a href="mailto:<?php echo htmlspecialchars($admin_email); ?>" style="display: inline-block; padding: 1rem 2rem; background: linear-gradient(135deg, var(--color-primary) 0%, #854d37 100%); color: white; text-decoration: none; border-radius: 12px; font-weight: 600; font-size: 18px; margin-top: 1rem; transition: transform 0.3s;">
                        <?php echo htmlspecialchars($admin_email); ?>
                    </a>
                    <p style="margin-top: 2rem;">
                        <a href="<?php echo BASE_URL; ?>" style="color: var(--color-primary); text-decoration: none;">← Return to Home</a>
                    </p>
                </div>
            <?php else: ?>
                <?php if (!empty($errors)): ?>
                    <div class="alert-fancy">
                        <strong>Please correct the following:</strong>
                        <ul>
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" id="enrollForm">
                <div class="form-grid">
                    <div class="field-modern <?php echo !empty($form_data['first_name']) ? 'has-value' : ''; ?>">
                        <input type="text" name="first_name" id="first_name"
                               value="<?php echo htmlspecialchars($form_data['first_name'] ?? ''); ?>"
                               required placeholder=" " autocomplete="given-name">
                        <label>First Name *</label>
                    </div>

                    <div class="field-modern <?php echo !empty($form_data['last_name']) ? 'has-value' : ''; ?>">
                        <input type="text" name="last_name" id="last_name"
                               value="<?php echo htmlspecialchars($form_data['last_name'] ?? ''); ?>"
                               required placeholder=" " autocomplete="family-name">
                        <label>Last Name *</label>
                    </div>
                </div>

                <div class="form-field-full">
                    <div class="field-modern <?php echo !empty($form_data['email']) ? 'has-value' : ''; ?>">
                        <input type="email" name="email" id="email"
                               value="<?php echo htmlspecialchars($form_data['email'] ?? ''); ?>"
                               required placeholder=" " autocomplete="email">
                        <label>Email Address *</label>
                    </div>
                    <div class="field-hint">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        We'll send enrollment updates to this address
                    </div>
                </div>

                <div class="form-field-full">
                    <div class="field-modern <?php echo !empty($form_data['phone']) ? 'has-value' : ''; ?>">
                        <input type="tel" name="phone" id="phone"
                               value="<?php echo htmlspecialchars($form_data['phone'] ?? ''); ?>"
                               required placeholder=" " autocomplete="tel">
                        <label>Mobile Phone *</label>
                    </div>
                    <div class="field-hint">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                        </svg>
                        10-digit US phone number
                    </div>
                </div>

                <div class="form-field-full">
                    <div class="field-modern <?php echo !empty($form_data['affiliate_code']) ? 'has-value' : ''; ?>">
                        <input type="text" name="affiliate_code" id="affiliate_code"
                               value="<?php echo htmlspecialchars($form_data['affiliate_code'] ?? ''); ?>"
                               placeholder=" ">
                        <label>Referral Code (Optional)</label>
                    </div>
                </div>

                <div class="form-actions-modern">
                    <a href="<?php echo BASE_URL; ?>" class="btn-fancy btn-fancy-secondary">
                        <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                        </svg>
                        Cancel
                    </a>
                    <button type="submit" class="btn-fancy btn-fancy-primary">
                        Continue
                        <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                        </svg>
                    </button>
                </div>
            </form>

            <div class="info-card">
                <h3>
                    <svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                    </svg>
                    Your Information is Secure
                </h3>
                <ul>
                    <li>All data is encrypted with bank-level security</li>
                    <li>We never share your information with third parties</li>
                    <li>Save and continue your enrollment anytime</li>
                </ul>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// Phone formatting with proper backspace support
const phoneInput = document.getElementById('phone');
let previousValue = '';

phoneInput.addEventListener('input', function(e) {
    let value = e.target.value.replace(/\D/g, '');

    // Limit to 10 digits
    if (value.length > 10) {
        value = value.substr(0, 10);
    }

    // Format the number
    let formatted = '';
    if (value.length >= 6) {
        formatted = '(' + value.substr(0,3) + ') ' + value.substr(3,3) + '-' + value.substr(6);
    } else if (value.length >= 3) {
        formatted = '(' + value.substr(0,3) + ') ' + value.substr(3);
    } else if (value.length > 0) {
        formatted = value;
    }

    e.target.value = formatted;
    previousValue = formatted;
});

// Update labels on input
document.querySelectorAll('.field-modern input').forEach(input => {
    input.addEventListener('input', function() {
        if (this.value) {
            this.parentElement.classList.add('has-value');
        } else {
            this.parentElement.classList.remove('has-value');
        }
    });
});

// reCAPTCHA v3 integration with loading state
<?php if (RECAPTCHA_ENABLED): ?>
document.getElementById('enrollForm').addEventListener('submit', function(e) {
    e.preventDefault();

    // Get submit button
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalHTML = submitBtn.innerHTML;

    // Disable button and show loading state
    submitBtn.disabled = true;
    submitBtn.style.opacity = '0.7';
    submitBtn.style.cursor = 'not-allowed';
    submitBtn.innerHTML = '<svg style="display:inline-block;margin-right:8px;animation:spin 1s linear infinite;" width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke-width="4" stroke="currentColor" stroke-dasharray="32" stroke-linecap="round" fill="none" opacity="0.25"/><path d="M12 2a10 10 0 0 1 10 10" stroke-width="4" stroke="currentColor" stroke-linecap="round" fill="none"/></svg>Processing...';

    grecaptcha.enterprise.ready(function() {
        grecaptcha.enterprise.execute('<?php echo RECAPTCHA_SITE_KEY; ?>', {action: 'enroll'}).then(function(token) {
            // Add token to form
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'recaptcha_token';
            input.value = token;
            document.getElementById('enrollForm').appendChild(input);

            // Submit form
            document.getElementById('enrollForm').submit();
        }).catch(function(error) {
            // Re-enable button on error
            submitBtn.disabled = false;
            submitBtn.style.opacity = '1';
            submitBtn.style.cursor = 'pointer';
            submitBtn.innerHTML = originalHTML;
            alert('Security verification failed. Please try again.');
        });
    });
});
<?php else: ?>
// No reCAPTCHA - just show loading state
document.getElementById('enrollForm').addEventListener('submit', function(e) {
    const submitBtn = this.querySelector('button[type="submit"]');
    submitBtn.disabled = true;
    submitBtn.style.opacity = '0.7';
    submitBtn.style.cursor = 'not-allowed';
    submitBtn.innerHTML = '<svg style="display:inline-block;margin-right:8px;animation:spin 1s linear infinite;" width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke-width="4" stroke="currentColor" stroke-dasharray="32" stroke-linecap="round" fill="none" opacity="0.25"/><path d="M12 2a10 10 0 0 1 10 10" stroke-width="4" stroke="currentColor" stroke-linecap="round" fill="none"/></svg>Processing...';
});
<?php endif; ?>
</script>

<style>
@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}
</style>

<?php include __DIR__ . '/../src/footer.php'; ?>
