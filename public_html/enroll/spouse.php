<?php
/**
 * Enrollment Step 5: Add Spouse (Couples Plan Only)
 */

define('SNS_ENROLLMENT', true);
require_once __DIR__ . '/../src/config.php';

session_start();

if (!isset($_SESSION['enrollment_session_id'])) {
    header('Location: ' . BASE_URL . '/enroll/');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM enrollment_users WHERE session_id = ?");
$stmt->execute([$_SESSION['enrollment_session_id']]);
$enrollment = $stmt->fetch();

if (!$enrollment) {
    session_destroy();
    header('Location: ' . BASE_URL . '/enroll/');
    exit;
}

// Check if couple plan
$is_couple = false;
if ($enrollment['plan_id']) {
    $stmt = $pdo->prepare("SELECT plan_type FROM plans WHERE id = ?");
    $stmt->execute([$enrollment['plan_id']]);
    $plan = $stmt->fetch();
    $is_couple = ($plan && $plan['plan_type'] === 'couple');
}

// If not couple plan, skip to contracts
if (!$is_couple) {
    header('Location: ' . BASE_URL . '/enroll/contracts.php');
    exit;
}

$errors = [];
$form_data = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $spouse_first_name = trim($_POST['spouse_first_name'] ?? '');
    $spouse_last_name = trim($_POST['spouse_last_name'] ?? '');
    $spouse_email = trim($_POST['spouse_email'] ?? '');
    $spouse_phone = clean_phone($_POST['spouse_phone'] ?? '');
    $spouse_consent = isset($_POST['spouse_consent']) ? 1 : 0;

    if (empty($spouse_first_name)) $errors[] = 'Spouse first name is required';
    if (empty($spouse_last_name)) $errors[] = 'Spouse last name is required';
    if (empty($spouse_email) || !filter_var($spouse_email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Valid spouse email is required';
    }
    if (empty($spouse_phone) || strlen($spouse_phone) !== 10) {
        $errors[] = 'Valid spouse phone is required';
    }
    if (!$spouse_consent) {
        $errors[] = 'You must confirm permission to send automated messages to your spouse';
    }

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("
                UPDATE enrollment_users
                SET spouse_first_name = ?, spouse_last_name = ?,
                    spouse_email = ?, spouse_phone = ?,
                    has_spouse = 1,
                    current_step = 5, last_activity = CURRENT_TIMESTAMP
                WHERE session_id = ?
            ");
            $stmt->execute([
                $spouse_first_name, $spouse_last_name,
                $spouse_email, $spouse_phone,
                $_SESSION['enrollment_session_id']
            ]);

            // Update CRC with spouse info
            if (!empty($enrollment['crc_record_id'])) {
                $memo = "--- SPOUSE INFO ---\n";
                $memo .= "Name: {$spouse_first_name} {$spouse_last_name}\n";
                $memo .= "Email: {$spouse_email}\n";
                $memo .= "Phone: " . format_phone($spouse_phone);

                crc_append_enrollment_memo($enrollment['crc_record_id'], $enrollment['id'], $memo);
            }

            // Create Systeme.io contact for spouse
            $spouse_systeme_data = [
                'first_name' => $spouse_first_name,
                'last_name' => $spouse_last_name,
                'email' => $spouse_email,
                'phone' => $spouse_phone
            ];

            // Add address if already entered
            if (!empty($enrollment['address_line1'])) {
                $spouse_systeme_data['address_line1'] = $enrollment['address_line1'];
                $spouse_systeme_data['city'] = $enrollment['city'];
                $spouse_systeme_data['state'] = $enrollment['state'];
                $spouse_systeme_data['zip_code'] = $enrollment['zip_code'];
                $spouse_systeme_data['country'] = 'US';
            }

            $spouse_systeme_result = systeme_create_contact($spouse_systeme_data);

            // Store Systeme.io spouse contact ID if successful
            if ($spouse_systeme_result && isset($spouse_systeme_result['contact_id'])) {
                $stmt = $pdo->prepare("UPDATE enrollment_users SET systeme_spouse_contact_id = ? WHERE id = ?");
                $stmt->execute([$spouse_systeme_result['contact_id'], $enrollment['id']]);
                log_activity("Systeme.io spouse contact created for enrollment {$enrollment['id']}: " . $spouse_systeme_result['contact_id'], 'INFO');
            }

            // Send Spouse Contract Request notification to the spouse
            require_once __DIR__ . '/../src/notification_helper.php';

            // Use the primary enrollment's session_id for the spouse URL
            $spouse_url = BASE_URL . '/spouse/?id=' . $enrollment['session_id'];

            $notification_data = [
                'client_name' => $enrollment['first_name'] . ' ' . $enrollment['last_name'],
                'client_spouse_name' => $spouse_first_name . ' ' . $spouse_last_name,
                'spouse_url' => $spouse_url
            ];

            $spouse_recipient = [
                'name' => $spouse_first_name . ' ' . $spouse_last_name,
                'email' => $spouse_email,
                'phone' => $spouse_phone
            ];

            send_notification('spouse_contract_request_client', 'client', $spouse_recipient, $notification_data);
            log_activity("Spouse Contract Request notification sent to {$spouse_email}", 'INFO');

            header('Location: ' . BASE_URL . '/enroll/contracts.php');
            exit;

        } catch (PDOException $e) {
            $errors[] = 'An error occurred. Please try again.';
            if (DEBUG_MODE) $errors[] = $e->getMessage();
        }
    }

    $form_data = compact('spouse_first_name', 'spouse_last_name', 'spouse_email');
    if (!empty($spouse_phone)) $form_data['spouse_phone'] = format_phone($spouse_phone);
} else {
    $form_data = [
        'spouse_first_name' => $enrollment['spouse_first_name'] ?? '',
        'spouse_last_name' => $enrollment['spouse_last_name'] ?? '',
        'spouse_email' => $enrollment['spouse_email'] ?? '',
        'spouse_phone' => $enrollment['spouse_phone'] ? format_phone($enrollment['spouse_phone']) : ''
    ];
}

$page_title = 'Add Spouse';
include __DIR__ . '/../src/header.php';
?>

<style>
body { background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%); }
.main-container { min-height: calc(100vh - 200px); display: flex; align-items: center; justify-content: center; padding: 2rem 1rem; }
.enroll-wrapper { max-width: 700px; width: 100%; animation: slideUp 0.5s ease-out; }
@keyframes slideUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
.enroll-card { background: white; border-radius: 24px; box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15); overflow: hidden; }
.card-hero { background: linear-gradient(135deg, var(--color-primary) 0%, #854d37 100%); color: white; padding: 3rem 2.5rem; text-align: center; position: relative; }
.card-hero::after { content: ''; position: absolute; bottom: 0; left: 0; right: 0; height: 30px; background: white; border-radius: 24px 24px 0 0; }
.card-hero h1 { font-size: 32px; font-weight: 700; margin: 0 0 0.75rem; color: white; }
.card-hero p { margin: 0; font-size: 16px; opacity: 0.95; }
.card-body-modern { padding: 2.5rem; }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; }
.form-field-full { grid-column: 1 / -1; }
.field-modern { position: relative; margin-bottom: 1.5rem; }
.field-modern input { width: 100%; padding: 1.5rem 1rem 0.5rem; border: 2px solid #e5e7eb; border-radius: 14px; font-size: 16px; transition: all 0.3s; background: #fafafa; font-family: var(--font-family); }
.field-modern input:focus { outline: none; border-color: var(--color-primary); background: white; box-shadow: 0 0 0 5px rgba(156, 96, 70, 0.1); }
.field-modern label { position: absolute; left: 1rem; top: 1.125rem; color: #9ca3af; font-size: 16px; pointer-events: none; transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1); }
.field-modern input:focus + label, .field-modern input:not(:placeholder-shown) + label, .field-modern.has-value label {
    top: 0.5rem; font-size: 11px; font-weight: 600; color: var(--color-primary);
}
.form-actions-modern { display: flex; gap: 1rem; margin-top: 2.5rem; padding-top: 2rem; border-top: 2px solid #f3f4f6; }
.btn-fancy { flex: 1; padding: 1.125rem 2rem; border: none; border-radius: 14px; font-size: 16px; font-weight: 600; cursor: pointer; transition: all 0.3s; display: flex; align-items: center; justify-content: center; gap: 0.625rem; text-decoration: none; }
.btn-fancy-primary { background: linear-gradient(135deg, var(--color-primary) 0%, #854d37 100%); color: white; box-shadow: 0 4px 14px rgba(156, 96, 70, 0.35); }
.btn-fancy-primary:hover { transform: translateY(-2px); box-shadow: 0 6px 24px rgba(156, 96, 70, 0.45); }
.btn-fancy-secondary { background: #f3f4f6; color: #6b7280; }
.btn-fancy-secondary:hover { background: #e5e7eb; }
.consent-checkbox { margin-top: 1.5rem; padding: 1.5rem; background: #f9fafb; border-radius: 14px; border: 2px solid #e5e7eb; }
.checkbox-container { display: flex; align-items: flex-start; gap: 0.75rem; cursor: pointer; position: relative; padding-left: 2rem; }
.checkbox-container input[type="checkbox"] { position: absolute; opacity: 0; cursor: pointer; height: 0; width: 0; }
.checkmark { position: absolute; left: 0; top: 2px; height: 22px; width: 22px; background-color: white; border: 2px solid #d1d5db; border-radius: 6px; transition: all 0.3s; }
.checkbox-container:hover .checkmark { border-color: var(--color-primary); }
.checkbox-container input:checked ~ .checkmark { background-color: var(--color-primary); border-color: var(--color-primary); }
.checkmark:after { content: ""; position: absolute; display: none; left: 6px; top: 2px; width: 5px; height: 10px; border: solid white; border-width: 0 2px 2px 0; transform: rotate(45deg); }
.checkbox-container input:checked ~ .checkmark:after { display: block; }
.checkbox-text { font-size: 15px; color: #374151; line-height: 1.6; }
@media (max-width: 768px) {
    .form-grid { grid-template-columns: 1fr; }
    .form-actions-modern { flex-direction: column-reverse; }
}
</style>

<div class="enroll-wrapper">
    <div class="enroll-card">
        <div class="card-hero">
            <h1>Add Your Spouse</h1>
            <p>Include your spouse in this credit repair journey</p>
        </div>

        <div class="card-body-modern">
            <form method="POST" action="">
                <div class="form-grid">
                    <div class="field-modern <?php echo !empty($form_data['spouse_first_name']) ? 'has-value' : ''; ?>">
                        <input type="text" name="spouse_first_name" id="spouse_first_name"
                               value="<?php echo htmlspecialchars($form_data['spouse_first_name'] ?? ''); ?>"
                               required placeholder=" ">
                        <label>First Name *</label>
                    </div>

                    <div class="field-modern <?php echo !empty($form_data['spouse_last_name']) ? 'has-value' : ''; ?>">
                        <input type="text" name="spouse_last_name" id="spouse_last_name"
                               value="<?php echo htmlspecialchars($form_data['spouse_last_name'] ?? ''); ?>"
                               required placeholder=" ">
                        <label>Last Name *</label>
                    </div>
                </div>

                <div class="form-field-full">
                    <div class="field-modern <?php echo !empty($form_data['spouse_email']) ? 'has-value' : ''; ?>">
                        <input type="email" name="spouse_email" id="spouse_email"
                               value="<?php echo htmlspecialchars($form_data['spouse_email'] ?? ''); ?>"
                               required placeholder=" ">
                        <label>Email Address *</label>
                    </div>
                </div>

                <div class="form-field-full">
                    <div class="field-modern <?php echo !empty($form_data['spouse_phone']) ? 'has-value' : ''; ?>">
                        <input type="tel" name="spouse_phone" id="spouse_phone"
                               value="<?php echo htmlspecialchars($form_data['spouse_phone'] ?? ''); ?>"
                               required placeholder=" ">
                        <label>Mobile Phone *</label>
                    </div>
                </div>

                <div class="form-field-full">
                    <div class="consent-checkbox">
                        <label class="checkbox-container">
                            <input type="checkbox" name="spouse_consent" id="spouse_consent" required>
                            <span class="checkmark"></span>
                            <span class="checkbox-text">I confirm I have permission for you to send automated text / email messages to my Spouse or other party listed above *</span>
                        </label>
                    </div>
                </div>

                <div class="form-actions-modern">
                    <a href="<?php echo BASE_URL; ?>/enroll/plan.php" class="btn-fancy btn-fancy-secondary">
                        <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                        </svg>
                        Back
                    </a>
                    <button type="submit" class="btn-fancy btn-fancy-primary">
                        Continue
                        <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                        </svg>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.getElementById('spouse_phone').addEventListener('input', function(e) {
    let value = e.target.value.replace(/\D/g, '');
    if (value.length > 10) value = value.substr(0, 10);
    if (value.length >= 6) {
        e.target.value = '(' + value.substr(0,3) + ') ' + value.substr(3,3) + '-' + value.substr(6);
    } else if (value.length >= 3) {
        e.target.value = '(' + value.substr(0,3) + ') ' + value.substr(3);
    }
});

document.querySelectorAll('.field-modern input').forEach(input => {
    input.addEventListener('input', function() {
        if (this.value) {
            this.parentElement.classList.add('has-value');
        } else {
            this.parentElement.classList.remove('has-value');
        }
    });
});
</script>

<?php include __DIR__ . '/../src/footer.php'; ?>
