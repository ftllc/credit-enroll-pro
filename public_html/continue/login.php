<?php
/**
 * Continue Enrollment - Login Page
 * Allows users to resume their enrollment by entering their enrollment code, email, or phone
 */

define('SNS_ENROLLMENT', true);
require_once __DIR__ . '/../src/config.php';

session_start();

$errors = [];
$prefilled_id = '';

// Check if enrollment ID is provided in URL
if (isset($_GET['id']) && !empty($_GET['id'])) {
    $prefilled_id = strtoupper(trim($_GET['id']));
    if (preg_match('/^[A-Z0-9]{4}-[A-Z0-9]{4}$/', $prefilled_id)) {
        $_POST['identifier'] = $prefilled_id;
        $_SERVER['REQUEST_METHOD'] = 'POST';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['identifier'] ?? '');
    $recaptcha_token = $_POST['recaptcha_token'] ?? '';

    // Verify reCAPTCHA
    if (RECAPTCHA_ENABLED && !empty($recaptcha_token)) {
        $recaptcha_url = 'https://recaptchaenterprise.googleapis.com/v1/projects/' . RECAPTCHA_PROJECT_ID . '/assessments?key=' . RECAPTCHA_SECRET_KEY;

        $recaptcha_data = [
            'event' => [
                'token' => $recaptcha_token,
                'siteKey' => RECAPTCHA_SITE_KEY,
                'expectedAction' => 'continue_enrollment'
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
            log_activity("Continue enrollment reCAPTCHA score: {$score}", 'INFO');

            // Require score of at least 0.5 (you can adjust this threshold)
            if ($score < 0.5) {
                $errors[] = 'Security verification failed. Please try again.';
                log_activity("Continue enrollment blocked - low reCAPTCHA score: {$score}", 'WARNING');
            }
        } else {
            log_activity("Continue enrollment reCAPTCHA verification failed - no response", 'ERROR');
            // Don't block on reCAPTCHA failure, just log it
        }
    }

    if (empty($identifier)) {
        $errors[] = 'Please enter your email, phone number, or enrollment code';
    } elseif (empty($errors)) {
        $lookup_type = null;
        $enrollment = null;

        // Check if it's an enrollment code (format: XXXX-XXXX)
        if (preg_match('/^[A-Z0-9]{4}-[A-Z0-9]{4}$/i', $identifier)) {
            $lookup_type = 'code';
            $stmt = $pdo->prepare("
                SELECT * FROM enrollment_users
                WHERE session_id = ? AND status NOT IN ('completed', 'abandoned', 'cancelled')
            ");
            $stmt->execute([strtoupper($identifier)]);
            $enrollment = $stmt->fetch();

        } elseif (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            $lookup_type = 'email';
            $stmt = $pdo->prepare("
                SELECT * FROM enrollment_users
                WHERE email = ? AND status NOT IN ('completed', 'abandoned', 'cancelled')
                ORDER BY created_at DESC
                LIMIT 1
            ");
            $stmt->execute([$identifier]);
            $enrollment = $stmt->fetch();

        } else {
            $lookup_type = 'phone';
            $clean_phone = clean_phone($identifier);
            if (strlen($clean_phone) === 10) {
                $stmt = $pdo->prepare("
                    SELECT * FROM enrollment_users
                    WHERE phone = ? AND status NOT IN ('completed', 'abandoned', 'cancelled')
                    ORDER BY created_at DESC
                    LIMIT 1
                ");
                $stmt->execute([$clean_phone]);
                $enrollment = $stmt->fetch();
            }
        }

        if (!$enrollment) {
            $errors[] = 'No enrollment found with that information. Please check and try again.';
        } elseif ($enrollment['status'] === 'completed') {
            $errors[] = 'This enrollment has already been completed. Please contact support if you need assistance.';
        } else {
            $_SESSION['continue_enrollment_id'] = $enrollment['id'];
            $_SESSION['continue_session_id'] = $enrollment['session_id'];
            $_SESSION['continue_lookup_method'] = $lookup_type;
            log_activity("Continue enrollment initiated for enrollment ID {$enrollment['id']}, Session: {$enrollment['session_id']}", 'INFO');
            header('Location: ' . BASE_URL . '/continue/verify.php');
            exit;
        }
    }
}

$page_title = 'Continue Enrollment';
$include_recaptcha = true;
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
.info-box p { margin: 0; color: #0c4a6e; font-size: 15px; line-height: 1.6; }
.field-modern { position: relative; margin-bottom: 1.5rem; }
.field-modern input { width: 100%; padding: 1.5rem 1rem 0.5rem; border: 2px solid #e5e7eb; border-radius: 14px; font-size: 16px; transition: all 0.3s; background: #fafafa; font-family: var(--font-family); }
.field-modern input:focus { outline: none; border-color: var(--color-primary); background: white; box-shadow: 0 0 0 5px rgba(156, 96, 70, 0.1); }
.field-modern label { position: absolute; left: 1rem; top: 1.125rem; color: #9ca3af; font-size: 16px; pointer-events: none; transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1); }
.field-modern input:focus + label, .field-modern input:not(:placeholder-shown) + label { top: 0.5rem; font-size: 11px; font-weight: 600; color: var(--color-primary); }
.btn-fancy { width: 100%; padding: 1.125rem 2rem; border: none; border-radius: 14px; font-size: 16px; font-weight: 600; cursor: pointer; transition: all 0.3s; display: flex; align-items: center; justify-content: center; gap: 0.625rem; background: linear-gradient(135deg, var(--color-primary) 0%, #854d37 100%); color: white; box-shadow: 0 4px 14px rgba(156, 96, 70, 0.35); }
.btn-fancy:hover { transform: translateY(-2px); box-shadow: 0 6px 24px rgba(156, 96, 70, 0.45); }
.error-box { background: #fef2f2; border: 2px solid #fecaca; border-radius: 14px; padding: 1rem 1.5rem; margin-bottom: 1.5rem; }
.error-box ul { margin: 0; padding-left: 1.25rem; color: #991b1b; }
.help-text { text-align: center; margin-top: 1.5rem; color: #6b7280; font-size: 14px; }
.new-enrollment-link { text-align: center; margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #e5e7eb; }
.new-enrollment-link a { color: var(--color-primary); text-decoration: none; font-weight: 600; }
.new-enrollment-link a:hover { text-decoration: underline; }
</style>

<div class="enroll-wrapper">
    <div class="enroll-card">
        <div class="card-hero">
            <h1>Continue Your Enrollment</h1>
            <p>Welcome back! Let's pick up where you left off.</p>
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

            <div class="info-box">
                <p>
                    <strong>To continue, enter one of the following:</strong><br>
                    • Your email address<br>
                    • Your mobile phone number<br>
                    • Your enrollment code (format: XXXX-XXXX)
                </p>
            </div>

            <form method="POST" action="" id="continueForm">
                <div class="field-modern">
                    <input type="text" name="identifier" id="identifier" required placeholder=" " autocomplete="off" value="<?php echo htmlspecialchars($prefilled_id); ?>">
                    <label>Email, Phone, or Enrollment Code *</label>
                </div>

                <button type="submit" class="btn-fancy">
                    Continue
                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                    </svg>
                </button>
            </form>

            <div class="help-text">
                Having trouble? Contact our support team for assistance.
            </div>

            <div class="new-enrollment-link">
                <a href="<?php echo BASE_URL; ?>/enroll/">Start a New Enrollment</a>
            </div>
        </div>
    </div>
</div>

<script>
// reCAPTCHA v3 integration
<?php if (RECAPTCHA_ENABLED): ?>
document.getElementById('continueForm').addEventListener('submit', function(e) {
    e.preventDefault();

    grecaptcha.enterprise.ready(function() {
        grecaptcha.enterprise.execute('<?php echo RECAPTCHA_SITE_KEY; ?>', {action: 'continue_enrollment'}).then(function(token) {
            // Add token to form
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'recaptcha_token';
            input.value = token;
            document.getElementById('continueForm').appendChild(input);

            // Submit form
            document.getElementById('continueForm').submit();
        });
    });
});
<?php endif; ?>
</script>

<?php include __DIR__ . '/../src/footer.php'; ?>
