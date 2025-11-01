<?php
/**
 * Enrollment Step 2: Address Verification
 * Uses Google Maps Autocomplete for address validation
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

// Update CRC memo to "Pending address" when user reaches this page
if (!empty($enrollment['crc_record_id']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    crc_append_enrollment_memo($enrollment['crc_record_id'], $enrollment['id'], '--- STATUS: Pending address');
}

// Handle form submission
$errors = [];
$form_data = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $address_line1 = trim($_POST['address_line1'] ?? '');
    $address_line2 = trim($_POST['address_line2'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $state = trim($_POST['state'] ?? '');
    $zip_code = trim($_POST['zip_code'] ?? '');

    // Validation
    if (empty($address_line1)) $errors[] = 'Street address is required';
    if (empty($city)) $errors[] = 'City is required';
    if (empty($state)) $errors[] = 'State is required';
    if (empty($zip_code)) $errors[] = 'ZIP code is required';
    if (!empty($zip_code) && !preg_match('/^\d{5}(-\d{4})?$/', $zip_code)) {
        $errors[] = 'Invalid ZIP code format';
    }

    // Update enrollment record
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("
                UPDATE enrollment_users
                SET address_line1 = ?, address_line2 = ?, city = ?, state = ?, zip_code = ?,
                    current_step = 2, last_activity = CURRENT_TIMESTAMP
                WHERE session_id = ?
            ");

            $stmt->execute([
                $address_line1, $address_line2, $city, $state, $zip_code,
                $_SESSION['enrollment_session_id']
            ]);

            // Update CRC with address and append memo
            if (!empty($enrollment['crc_record_id'])) {
                // Build address memo
                $address_memo = "--- ADDRESS ENTERED ---\n{$address_line1}";
                if (!empty($address_line2)) $address_memo .= " {$address_line2}";
                $address_memo .= "\n{$city}, {$state} {$zip_code}";
                $address_memo .= "\n\n--- STATUS: Pending plan selection";

                // Get current memo and append
                $stmt = $pdo->prepare("SELECT crc_memo FROM enrollment_users WHERE id = ?");
                $stmt->execute([$enrollment['id']]);
                $row = $stmt->fetch();
                $current_memo = $row['crc_memo'] ?? '';

                if (!empty($current_memo)) {
                    $updated_memo = $current_memo . "\n\n" . $address_memo;
                } else {
                    $updated_memo = $address_memo;
                }

                // Update CRC with address fields AND memo in one call
                crc_update_record($enrollment['crc_record_id'], [
                    'address_line1' => $address_line1,
                    'city' => $city,
                    'state' => $state,
                    'zip_code' => $zip_code,
                    'memo' => $updated_memo
                ]);

                // Store memo locally
                $stmt = $pdo->prepare("UPDATE enrollment_users SET crc_memo = ? WHERE id = ?");
                $stmt->execute([$updated_memo, $enrollment['id']]);
            }

            // Update Systeme.io contact with address
            if (!empty($enrollment['systeme_contact_id'])) {
                $update_result = systeme_update_contact($enrollment['systeme_contact_id'], [
                    'address_line1' => $address_line1,
                    'city' => $city,
                    'state' => $state,
                    'zip_code' => $zip_code,
                    'country' => 'US'
                ]);

                if ($update_result) {
                    log_activity("Systeme.io contact {$enrollment['systeme_contact_id']} updated with address for enrollment {$enrollment['id']}", 'INFO');
                }
            }

            // Go to plan selection
            header('Location: ' . BASE_URL . '/enroll/plan.php');
            exit;

        } catch (PDOException $e) {
            $errors[] = 'An error occurred. Please try again.';
            if (DEBUG_MODE) $errors[] = $e->getMessage();
        }
    }

    $form_data = compact('address_line1', 'address_line2', 'city', 'state', 'zip_code');
} else {
    // Pre-populate with existing data
    $form_data = [
        'address_line1' => $enrollment['address_line1'] ?? '',
        'address_line2' => $enrollment['address_line2'] ?? '',
        'city' => $enrollment['city'] ?? '',
        'state' => $enrollment['state'] ?? '',
        'zip_code' => $enrollment['zip_code'] ?? ''
    ];
}

$page_title = 'Your Address';
$include_google_maps = true;
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

.pac-container {
    border-radius: 12px;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
    border: none;
    margin-top: 8px;
    font-family: var(--font-family);
}

.pac-item {
    padding: 12px 16px;
    cursor: pointer;
    border: none;
}

.pac-item:hover {
    background: #f3f4f6;
}

.pac-icon {
    margin-top: 4px;
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
            <h1>Where Do You Live?</h1>
            <p>We'll use this address for your credit repair services</p>
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

            <form method="POST" action="" id="addressForm">
                <div class="form-field-full">
                    <div class="field-modern <?php echo !empty($form_data['address_line1']) ? 'has-value' : ''; ?>">
                        <input type="text" name="address_line1" id="address_line1"
                               value="<?php echo htmlspecialchars($form_data['address_line1'] ?? ''); ?>"
                               required placeholder=" " autocomplete="off">
                        <label>Street Address *</label>
                    </div>
                    <div class="field-hint">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                        Start typing and select from suggestions
                    </div>
                </div>

                <div class="form-field-full">
                    <div class="field-modern <?php echo !empty($form_data['address_line2']) ? 'has-value' : ''; ?>">
                        <input type="text" name="address_line2" id="address_line2"
                               value="<?php echo htmlspecialchars($form_data['address_line2'] ?? ''); ?>"
                               placeholder=" ">
                        <label>Apartment, Suite, Unit (Optional)</label>
                    </div>
                </div>

                <div class="form-grid">
                    <div class="field-modern <?php echo !empty($form_data['city']) ? 'has-value' : ''; ?>">
                        <input type="text" name="city" id="city"
                               value="<?php echo htmlspecialchars($form_data['city'] ?? ''); ?>"
                               required placeholder=" ">
                        <label>City *</label>
                    </div>

                    <div class="field-modern <?php echo !empty($form_data['state']) ? 'has-value' : ''; ?>">
                        <input type="text" name="state" id="state"
                               value="<?php echo htmlspecialchars($form_data['state'] ?? ''); ?>"
                               required placeholder=" " maxlength="2">
                        <label>State *</label>
                    </div>
                </div>

                <div class="field-modern <?php echo !empty($form_data['zip_code']) ? 'has-value' : ''; ?>">
                    <input type="text" name="zip_code" id="zip_code"
                           value="<?php echo htmlspecialchars($form_data['zip_code'] ?? ''); ?>"
                           required placeholder=" " maxlength="10">
                    <label>ZIP Code *</label>
                </div>

                <div class="form-actions-modern">
                    <a href="<?php echo BASE_URL; ?>/enroll/" class="btn-fancy btn-fancy-secondary">
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
let autocomplete;

function initAutocomplete() {
    const input = document.getElementById('address_line1');

    autocomplete = new google.maps.places.Autocomplete(input, {
        types: ['address'],
        componentRestrictions: { country: 'us' }
    });

    autocomplete.addListener('place_changed', fillInAddress);
}

function fillInAddress() {
    const place = autocomplete.getPlace();

    if (!place.address_components) {
        return;
    }

    let streetNumber = '';
    let route = '';
    let city = '';
    let state = '';
    let zipCode = '';

    for (const component of place.address_components) {
        const type = component.types[0];

        switch (type) {
            case 'street_number':
                streetNumber = component.long_name;
                break;
            case 'route':
                route = component.long_name;
                break;
            case 'locality':
                city = component.long_name;
                break;
            case 'administrative_area_level_1':
                state = component.short_name;
                break;
            case 'postal_code':
                zipCode = component.long_name;
                break;
        }
    }

    // Fill in the fields
    document.getElementById('address_line1').value = `${streetNumber} ${route}`.trim();
    document.getElementById('city').value = city;
    document.getElementById('state').value = state;
    document.getElementById('zip_code').value = zipCode;

    // Update has-value classes
    document.querySelectorAll('.field-modern input').forEach(input => {
        if (input.value) {
            input.parentElement.classList.add('has-value');
        }
    });

    // Focus on next empty field
    if (!document.getElementById('address_line2').value) {
        document.getElementById('address_line2').focus();
    }
}

// Initialize autocomplete when Google Maps loads
if (typeof google !== 'undefined') {
    google.maps.event.addDomListener(window, 'load', initAutocomplete);
} else {
    window.addEventListener('load', initAutocomplete);
}

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

// ZIP code formatting
document.getElementById('zip_code').addEventListener('input', function(e) {
    let value = e.target.value.replace(/\D/g, '');
    if (value.length > 5) {
        e.target.value = value.substr(0, 5) + '-' + value.substr(5, 4);
    } else {
        e.target.value = value;
    }
});
</script>

<?php include __DIR__ . '/../src/footer.php'; ?>
