<?php
/**
 * Enrollment Step 5: Personal Details
 * Collects DOB and SSN (encrypted)
 */

define('SNS_ENROLLMENT', true);
require_once __DIR__ . '/../src/config.php';

session_start();

// Check if user has started enrollment
if (!isset($_SESSION['enrollment_session_id'])) {
    header('Location: ' . BASE_URL . '/enroll/');
    exit;
}

// Get enrollment data
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

// Handle form submission
$errors = [];
$form_data = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dob = trim($_POST['dob'] ?? '');
    $ssn = preg_replace('/\D/', '', $_POST['ssn'] ?? '');

    // Couple fields
    $spouse_first_name = trim($_POST['spouse_first_name'] ?? '');
    $spouse_last_name = trim($_POST['spouse_last_name'] ?? '');
    $spouse_email = trim($_POST['spouse_email'] ?? '');
    $spouse_phone = clean_phone($_POST['spouse_phone'] ?? '');
    $spouse_dob = trim($_POST['spouse_dob'] ?? '');
    $spouse_ssn = preg_replace('/\D/', '', $_POST['spouse_ssn'] ?? '');

    // Validation - Primary
    if (empty($dob)) {
        $errors[] = 'Date of birth is required';
    } else {
        $dob_date = DateTime::createFromFormat('Y-m-d', $dob);
        if (!$dob_date) {
            $errors[] = 'Invalid date of birth format';
        } else {
            $today = new DateTime();
            $age = $today->diff($dob_date)->y;
            if ($age < 18) $errors[] = 'You must be at least 18 years old';
            if ($age > 120) $errors[] = 'Invalid date of birth';
        }
    }

    if (empty($ssn) || strlen($ssn) !== 9) {
        $errors[] = 'Valid 9-digit SSN is required';
    }

    // Validation - Spouse (if couple plan)
    if ($is_couple) {
        if (empty($spouse_first_name)) $errors[] = 'Spouse first name is required';
        if (empty($spouse_last_name)) $errors[] = 'Spouse last name is required';
        if (empty($spouse_email) || !filter_var($spouse_email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Valid spouse email is required';
        }
        if (empty($spouse_phone) || strlen($spouse_phone) !== 10) {
            $errors[] = 'Valid spouse phone is required';
        }
        if (empty($spouse_dob)) {
            $errors[] = 'Spouse date of birth is required';
        } else {
            $spouse_dob_date = DateTime::createFromFormat('Y-m-d', $spouse_dob);
            if (!$spouse_dob_date) {
                $errors[] = 'Invalid spouse date of birth';
            } else {
                $age = $today->diff($spouse_dob_date)->y;
                if ($age < 18) $errors[] = 'Spouse must be at least 18 years old';
            }
        }
        if (empty($spouse_ssn) || strlen($spouse_ssn) !== 9) {
            $errors[] = 'Valid spouse SSN is required';
        }
    }

    if (empty($errors)) {
        try {
            // Encrypt SSNs
            $ssn_encrypted = encrypt_data($ssn);
            $spouse_ssn_encrypted = $is_couple ? encrypt_data($spouse_ssn) : null;

            if ($is_couple) {
                $stmt = $pdo->prepare("
                    UPDATE enrollment_users
                    SET date_of_birth = ?, ssn_encrypted = ?,
                        spouse_first_name = ?, spouse_last_name = ?,
                        spouse_email = ?, spouse_phone = ?,
                        spouse_dob = ?, spouse_ssn_encrypted = ?,
                        has_spouse = 1,
                        current_step = 5, last_activity = CURRENT_TIMESTAMP
                    WHERE session_id = ?
                ");
                $stmt->execute([
                    $dob, $ssn_encrypted,
                    $spouse_first_name, $spouse_last_name,
                    $spouse_email, $spouse_phone,
                    $spouse_dob, $spouse_ssn_encrypted,
                    $_SESSION['enrollment_session_id']
                ]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE enrollment_users
                    SET date_of_birth = ?, ssn_encrypted = ?,
                        current_step = 5, last_activity = CURRENT_TIMESTAMP
                    WHERE session_id = ?
                ");
                $stmt->execute([$dob, $ssn_encrypted, $_SESSION['enrollment_session_id']]);
            }

            // Create Systeme.io contact for spouse if couple plan
            if ($is_couple && !empty($spouse_email)) {
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
            }

            // Go to documents upload page
            header('Location: ' . BASE_URL . '/enroll/documents.php');
            exit;

        } catch (PDOException $e) {
            $errors[] = 'An error occurred. Please try again.';
            if (DEBUG_MODE) $errors[] = $e->getMessage();
        }
    }

    $form_data = compact('dob', 'spouse_first_name', 'spouse_last_name', 'spouse_email', 'spouse_dob');
    if (!empty($spouse_phone)) $form_data['spouse_phone'] = format_phone($spouse_phone);
} else {
    // Pre-populate
    $form_data = [
        'dob' => $enrollment['date_of_birth'] ?? '',
        'spouse_first_name' => $enrollment['spouse_first_name'] ?? '',
        'spouse_last_name' => $enrollment['spouse_last_name'] ?? '',
        'spouse_email' => $enrollment['spouse_email'] ?? '',
        'spouse_phone' => $enrollment['spouse_phone'] ? format_phone($enrollment['spouse_phone']) : '',
        'spouse_dob' => $enrollment['spouse_dob'] ?? ''
    ];
}

$page_title = 'Personal Information';
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

.section-divider {
    margin: 2.5rem 0;
    padding-top: 2.5rem;
    border-top: 2px solid #f3f4f6;
}

.section-title {
    font-size: 20px;
    font-weight: 700;
    color: var(--color-primary);
    margin-bottom: 1.5rem;
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
    margin-bottom: 1.5rem;
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
    padding-left: 0.5rem;
}

.field-hint svg {
    width: 16px;
    height: 16px;
    opacity: 0.7;
    flex-shrink: 0;
}

.security-note {
    background: linear-gradient(135deg, #fafbfc 0%, #f0f1f3 100%);
    border-radius: 14px;
    padding: 1.25rem;
    margin-bottom: 1.5rem;
    border: 2px solid #e5e7eb;
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
}

.security-note svg {
    width: 20px;
    height: 20px;
    color: var(--color-primary);
    flex-shrink: 0;
    margin-top: 2px;
}

.security-note p {
    margin: 0;
    font-size: 14px;
    color: #6b7280;
    line-height: 1.6;
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
            <h1>Personal Information</h1>
            <p>We need this info for identity verification</p>
        </div>

        <div class="card-body-modern">
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

            <div class="security-note">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                </svg>
                <p><strong>Your information is secure:</strong> Your SSN is encrypted with bank-level security and never stored in plain text.</p>
            </div>

            <form method="POST" action="">
                <div class="section-title">Your Information</div>

                <div class="form-grid">
                    <div class="field-modern <?php echo !empty($form_data['dob']) ? 'has-value' : ''; ?>">
                        <input type="date" name="dob" id="dob"
                               value="<?php echo htmlspecialchars($form_data['dob'] ?? ''); ?>"
                               required placeholder=" " max="<?php echo date('Y-m-d', strtotime('-18 years')); ?>">
                        <label>Date of Birth *</label>
                    </div>

                    <div class="field-modern">
                        <input type="text" name="ssn" id="ssn"
                               required placeholder=" " maxlength="11" autocomplete="off">
                        <label>Social Security Number *</label>
                    </div>
                </div>
                <div class="field-hint">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    Format: 123-45-6789
                </div>

                <?php if ($is_couple): ?>
                    <div class="section-divider">
                        <div class="section-title">Spouse Information</div>

                        <div class="form-grid">
                            <div class="field-modern <?php echo !empty($form_data['spouse_first_name']) ? 'has-value' : ''; ?>">
                                <input type="text" name="spouse_first_name" id="spouse_first_name"
                                       value="<?php echo htmlspecialchars($form_data['spouse_first_name'] ?? ''); ?>"
                                       required placeholder=" ">
                                <label>Spouse First Name *</label>
                            </div>

                            <div class="field-modern <?php echo !empty($form_data['spouse_last_name']) ? 'has-value' : ''; ?>">
                                <input type="text" name="spouse_last_name" id="spouse_last_name"
                                       value="<?php echo htmlspecialchars($form_data['spouse_last_name'] ?? ''); ?>"
                                       required placeholder=" ">
                                <label>Spouse Last Name *</label>
                            </div>
                        </div>

                        <div class="form-field-full">
                            <div class="field-modern <?php echo !empty($form_data['spouse_email']) ? 'has-value' : ''; ?>">
                                <input type="email" name="spouse_email" id="spouse_email"
                                       value="<?php echo htmlspecialchars($form_data['spouse_email'] ?? ''); ?>"
                                       required placeholder=" ">
                                <label>Spouse Email *</label>
                            </div>
                        </div>

                        <div class="form-field-full">
                            <div class="field-modern <?php echo !empty($form_data['spouse_phone']) ? 'has-value' : ''; ?>">
                                <input type="tel" name="spouse_phone" id="spouse_phone"
                                       value="<?php echo htmlspecialchars($form_data['spouse_phone'] ?? ''); ?>"
                                       required placeholder=" ">
                                <label>Spouse Phone *</label>
                            </div>
                        </div>

                        <div class="form-grid">
                            <div class="field-modern <?php echo !empty($form_data['spouse_dob']) ? 'has-value' : ''; ?>">
                                <input type="date" name="spouse_dob" id="spouse_dob"
                                       value="<?php echo htmlspecialchars($form_data['spouse_dob'] ?? ''); ?>"
                                       required placeholder=" " max="<?php echo date('Y-m-d', strtotime('-18 years')); ?>">
                                <label>Spouse Date of Birth *</label>
                            </div>

                            <div class="field-modern">
                                <input type="text" name="spouse_ssn" id="spouse_ssn"
                                       required placeholder=" " maxlength="11" autocomplete="off">
                                <label>Spouse SSN *</label>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="form-actions-modern">
                    <a href="<?php echo BASE_URL; ?>/enroll/congrats.php" class="btn-fancy btn-fancy-secondary">
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
// SSN formatting
function formatSSN(input) {
    let value = input.value.replace(/\D/g, '');
    if (value.length > 9) value = value.substr(0, 9);

    if (value.length >= 5) {
        value = value.substr(0, 3) + '-' + value.substr(3, 2) + '-' + value.substr(5);
    } else if (value.length >= 3) {
        value = value.substr(0, 3) + '-' + value.substr(3);
    }

    input.value = value;
}

document.getElementById('ssn').addEventListener('input', function(e) {
    formatSSN(e.target);
});

<?php if ($is_couple): ?>
document.getElementById('spouse_ssn').addEventListener('input', function(e) {
    formatSSN(e.target);
});

// Phone formatting for spouse
document.getElementById('spouse_phone').addEventListener('input', function(e) {
    let value = e.target.value.replace(/\D/g, '');
    if (value.length > 10) value = value.substr(0, 10);

    if (value.length >= 6) {
        e.target.value = '(' + value.substr(0,3) + ') ' + value.substr(3,3) + '-' + value.substr(6);
    } else if (value.length >= 3) {
        e.target.value = '(' + value.substr(0,3) + ') ' + value.substr(3);
    }
});
<?php endif; ?>

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
</script>

<?php include __DIR__ . '/../src/footer.php'; ?>
