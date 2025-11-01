<?php
/**
 * Spouse Enrollment - Confirm Information Page
 * Allows spouse to verify their name, email, phone, and address
 */

define('SNS_ENROLLMENT', true);
require_once __DIR__ . '/../src/config.php';

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

// Check if verified
if (!$spouse_enrollment['verified']) {
    header('Location: ' . BASE_URL . '/spouse/verify.php');
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

// If already confirmed, skip to contracts
if ($spouse_enrollment['info_confirmed']) {
    header('Location: ' . BASE_URL . '/spouse/contracts.php');
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $confirmed = isset($_POST['confirm_info']) ? 1 : 0;

    if (!$confirmed) {
        $errors[] = 'Please confirm that your information is correct';
    } else {
        try {
            // Mark information as confirmed
            $stmt = $pdo->prepare("
                UPDATE spouse_enrollments
                SET info_confirmed = 1, current_step = 3, last_activity = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([$spouse_enrollment['id']]);

            log_activity("Spouse enrollment {$spouse_enrollment['session_id']} information confirmed", 'INFO');

            header('Location: ' . BASE_URL . '/spouse/contracts.php');
            exit;

        } catch (PDOException $e) {
            $errors[] = 'An error occurred. Please try again.';
            if (DEBUG_MODE) $errors[] = $e->getMessage();
        }
    }
}

$page_title = 'Confirm Your Information';
include __DIR__ . '/../src/header.php';
?>

<style>
body { background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%); min-height: 100vh; }
.main-container { min-height: calc(100vh - 100px); display: flex; align-items: center; justify-content: center; padding: 2rem 1rem; }
.enroll-wrapper { max-width: 700px; width: 100%; animation: slideUp 0.5s ease-out; }
@keyframes slideUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
.enroll-card { background: white; border-radius: 24px; box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15); overflow: hidden; }
.card-hero { background: linear-gradient(135deg, var(--color-primary) 0%, #854d37 100%); color: white; padding: 3rem 2.5rem; text-align: center; position: relative; }
.card-hero::after { content: ''; position: absolute; bottom: 0; left: 0; right: 0; height: 30px; background: white; border-radius: 24px 24px 0 0; }
.card-hero h1 { font-size: 32px; font-weight: 700; margin: 0 0 0.75rem; color: white; }
.card-hero p { margin: 0; font-size: 16px; opacity: 0.95; line-height: 1.6; }
.card-body-modern { padding: 2.5rem; }
.info-section { background: #f9fafb; border-radius: 14px; padding: 1.5rem; margin-bottom: 1.5rem; }
.info-section h3 { margin: 0 0 1rem; font-size: 18px; font-weight: 600; color: #111827; }
.info-row { display: flex; justify-content: space-between; padding: 0.75rem 0; border-bottom: 1px solid #e5e7eb; }
.info-row:last-child { border-bottom: none; }
.info-label { font-weight: 600; color: #6b7280; }
.info-value { color: #111827; }
.consent-checkbox { margin-top: 1.5rem; padding: 1.5rem; background: #f9fafb; border-radius: 14px; border: 2px solid #e5e7eb; }
.checkbox-container { display: flex; align-items: flex-start; gap: 0.75rem; cursor: pointer; position: relative; padding-left: 2rem; }
.checkbox-container input[type="checkbox"] { position: absolute; opacity: 0; cursor: pointer; height: 0; width: 0; }
.checkmark { position: absolute; left: 0; top: 2px; height: 22px; width: 22px; background-color: white; border: 2px solid #d1d5db; border-radius: 6px; transition: all 0.3s; }
.checkbox-container:hover .checkmark { border-color: var(--color-primary); }
.checkbox-container input:checked ~ .checkmark { background-color: var(--color-primary); border-color: var(--color-primary); }
.checkmark:after { content: ""; position: absolute; display: none; left: 6px; top: 2px; width: 5px; height: 10px; border: solid white; border-width: 0 2px 2px 0; transform: rotate(45deg); }
.checkbox-container input:checked ~ .checkmark:after { display: block; }
.checkbox-text { font-size: 15px; color: #374151; line-height: 1.6; }
.btn-fancy { width: 100%; padding: 1.125rem 2rem; border: none; border-radius: 14px; font-size: 16px; font-weight: 600; cursor: pointer; transition: all 0.3s; display: flex; align-items: center; justify-content: center; gap: 0.625rem; background: linear-gradient(135deg, var(--color-primary) 0%, #854d37 100%); color: white; box-shadow: 0 4px 14px rgba(156, 96, 70, 0.35); margin-top: 2rem; }
.btn-fancy:hover { transform: translateY(-2px); box-shadow: 0 6px 24px rgba(156, 96, 70, 0.45); }
.error-box { background: #fef2f2; border: 2px solid #fecaca; border-radius: 14px; padding: 1rem 1.5rem; margin-bottom: 1.5rem; }
.error-box ul { margin: 0; padding-left: 1.25rem; color: #991b1b; }
.help-text { text-align: center; margin-top: 1rem; color: #6b7280; font-size: 14px; }
</style>

<div class="enroll-wrapper">
    <div class="enroll-card">
        <div class="card-hero">
            <h1>Confirm Your Information</h1>
            <p>Please verify that the information below is correct</p>
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

            <div class="info-section">
                <h3>Personal Information</h3>
                <div class="info-row">
                    <span class="info-label">First Name:</span>
                    <span class="info-value"><?php echo htmlspecialchars($primary_enrollment['spouse_first_name']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Last Name:</span>
                    <span class="info-value"><?php echo htmlspecialchars($primary_enrollment['spouse_last_name']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Email:</span>
                    <span class="info-value"><?php echo htmlspecialchars($primary_enrollment['spouse_email']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Phone:</span>
                    <span class="info-value"><?php echo format_phone($primary_enrollment['spouse_phone']); ?></span>
                </div>
            </div>

            <div class="info-section">
                <h3>Address</h3>
                <div class="info-row">
                    <span class="info-label">Address:</span>
                    <span class="info-value"><?php echo htmlspecialchars($primary_enrollment['address_line1']); ?></span>
                </div>
                <?php if (!empty($primary_enrollment['address_line2'])): ?>
                <div class="info-row">
                    <span class="info-label">Address Line 2:</span>
                    <span class="info-value"><?php echo htmlspecialchars($primary_enrollment['address_line2']); ?></span>
                </div>
                <?php endif; ?>
                <div class="info-row">
                    <span class="info-label">City:</span>
                    <span class="info-value"><?php echo htmlspecialchars($primary_enrollment['city']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">State:</span>
                    <span class="info-value"><?php echo htmlspecialchars($primary_enrollment['state']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">ZIP Code:</span>
                    <span class="info-value"><?php echo htmlspecialchars($primary_enrollment['zip_code']); ?></span>
                </div>
            </div>

            <form method="POST" action="">
                <div class="consent-checkbox">
                    <label class="checkbox-container">
                        <input type="checkbox" name="confirm_info" id="confirm_info" required>
                        <span class="checkmark"></span>
                        <span class="checkbox-text">I confirm that all the information above is correct *</span>
                    </label>
                </div>

                <button type="submit" class="btn-fancy">
                    Continue to Contracts
                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                    </svg>
                </button>
            </form>

            <div class="help-text">
                If any information is incorrect, please contact your spouse or our support team.
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../src/footer.php'; ?>
