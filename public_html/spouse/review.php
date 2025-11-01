<?php
/**
 * Spouse Enrollment - Final Review
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

// Get primary enrollment data
$stmt = $pdo->prepare("SELECT * FROM enrollment_users WHERE id = ?");
$stmt->execute([$spouse_enrollment['primary_enrollment_id']]);
$enrollment = $stmt->fetch();

if (!$enrollment) {
    session_destroy();
    header('Location: ' . BASE_URL . '/spouse/');
    exit;
}

// Check if contracts signed
if ($spouse_enrollment['current_step'] < 4) {
    header('Location: ' . BASE_URL . '/spouse/contracts.php');
    exit;
}

$plan = null;
if ($enrollment['plan_id']) {
    $stmt = $pdo->prepare("SELECT * FROM plans WHERE id = ?");
    $stmt->execute([$enrollment['plan_id']]);
    $plan = $stmt->fetch();
}

// Get signed contracts for SPOUSE (is_spouse = 1)
$stmt = $pdo->prepare("SELECT contract_type, signed_at, signed_by FROM contracts WHERE enrollment_id = ? AND is_spouse = 1 AND signed = 1 ORDER BY signed_at");
$stmt->execute([$enrollment['id']]);
$signed_contracts = $stmt->fetchAll();

$contracts_by_type = [];
foreach ($signed_contracts as $contract) {
    $contracts_by_type[$contract['contract_type']] = $contract;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = [];

    // Verify reCAPTCHA (if enabled)
    if (RECAPTCHA_ENABLED) {
        $recaptcha_token = $_POST['recaptcha_token'] ?? '';
        if (empty($recaptcha_token)) {
            $errors[] = 'reCAPTCHA verification failed. Please try again.';
        } else {
            // Use reCAPTCHA Enterprise API
            $recaptcha_url = 'https://recaptchaenterprise.googleapis.com/v1/projects/' . RECAPTCHA_PROJECT_ID . '/assessments?key=' . RECAPTCHA_SECRET_KEY;

            $recaptcha_data = json_encode([
                'event' => [
                    'token' => $recaptcha_token,
                    'siteKey' => RECAPTCHA_SITE_KEY,
                    'expectedAction' => 'spouse_review'
                ]
            ]);

            $recaptcha_options = [
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/json\r\n" .
                               "Content-Length: " . strlen($recaptcha_data) . "\r\n",
                    'content' => $recaptcha_data,
                    'ignore_errors' => true
                ]
            ];

            $recaptcha_context = stream_context_create($recaptcha_options);
            $recaptcha_result = @file_get_contents($recaptcha_url, false, $recaptcha_context);
            $recaptcha_json = json_decode($recaptcha_result);

            // Debug logging
            if (DEBUG_MODE) {
                error_log('reCAPTCHA Response: ' . print_r($recaptcha_json, true));
            }

            // Check if verification was successful
            if (!$recaptcha_json || !isset($recaptcha_json->tokenProperties->valid) || !$recaptcha_json->tokenProperties->valid) {
                if (DEBUG_MODE) {
                    $errors[] = 'reCAPTCHA verification failed. Response: ' . substr($recaptcha_result, 0, 200);
                } else {
                    $errors[] = 'reCAPTCHA verification failed. Please try again.';
                }
            } elseif (isset($recaptcha_json->riskAnalysis->score) && $recaptcha_json->riskAnalysis->score < 0.5) {
                $errors[] = 'reCAPTCHA security check failed. Please try again.';
            }
        }
    }

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("UPDATE spouse_enrollments SET current_step = 5, last_activity = CURRENT_TIMESTAMP WHERE session_id = ?");
            $stmt->execute([$_SESSION['spouse_enrollment_session_id']]);

            header('Location: ' . BASE_URL . '/spouse/congrats.php');
            exit;
        } catch (PDOException $e) {
            $errors[] = 'An error occurred. Please try again.';
        }
    }

    $error = !empty($errors) ? implode(' ', $errors) : null;
}

$page_title = 'Review Information';
$include_recaptcha = true;
include __DIR__ . '/../src/header.php';
?>
<style>
.main-container { padding: 1.5rem 1rem; }
.enroll-wrapper { max-width: 800px; margin: 0 auto; animation: slideUp 0.4s ease-out; }
@keyframes slideUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
@keyframes float { 0%, 100% { transform: translateY(0px); } 50% { transform: translateY(-6px); } }
.enroll-card { background: white; border-radius: 16px; box-shadow: 0 8px 32px rgba(0, 0, 0, 0.12); overflow: hidden; }
.card-hero { background: linear-gradient(135deg, var(--color-primary) 0%, #854d37 100%); color: white; padding: 2rem 2rem 2.5rem; text-align: center; position: relative; }
.card-hero::after { content: ''; position: absolute; bottom: 0; left: 0; right: 0; height: 20px; background: white; border-radius: 16px 16px 0 0; }
.hero-emoji { font-size: 48px; animation: float 2.5s ease-in-out infinite; display: inline-block; margin-bottom: 0.5rem; }
.card-hero h1 { font-size: 28px; font-weight: 700; margin: 0 0 0.5rem; color: white; position: relative; z-index: 1; }
.card-hero p { margin: 0; font-size: 15px; opacity: 0.95; position: relative; z-index: 1; font-weight: 500; }
.card-body-modern { padding: 2rem; }
.celebration-banner { background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); border-left: 4px solid #f59e0b; padding: 1rem 1.25rem; border-radius: 8px; margin-bottom: 1.5rem; }
.celebration-banner h2 { margin: 0 0 0.25rem; color: #92400e; font-size: 16px; font-weight: 700; }
.celebration-banner p { margin: 0; color: #78350f; font-size: 14px; line-height: 1.5; }
.review-section { background: #fafbfc; border-radius: 12px; padding: 1.5rem; margin-bottom: 1.25rem; border: 2px solid #e5e7eb; transition: all 0.3s; }
.review-section:hover { border-color: var(--color-primary); box-shadow: 0 4px 12px rgba(156, 96, 70, 0.1); }
.review-section h3 { font-size: 16px; font-weight: 700; color: var(--color-primary); margin: 0 0 1rem; display: flex; align-items: center; gap: 0.5rem; }
.review-section h3 .icon { font-size: 20px; }
.review-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
.review-item { display: flex; flex-direction: column; gap: 0.25rem; }
.review-label { font-size: 11px; font-weight: 600; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.5px; }
.review-value { font-size: 14px; color: #374151; font-weight: 500; }
.map-container { margin-top: 1rem; border-radius: 10px; overflow: hidden; border: 2px solid var(--color-primary); height: 200px; }
#addressMap { width: 100%; height: 100%; }
.agreements-section { background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%); border-radius: 12px; padding: 1.5rem; margin-bottom: 1.25rem; border: 2px solid var(--color-success); }
.agreements-section h3 { font-size: 16px; font-weight: 700; color: #155724; margin: 0 0 1rem; display: flex; align-items: center; gap: 0.5rem; }
.agreement-item { background: white; border-radius: 8px; padding: 1rem; margin-bottom: 0.75rem; display: flex; align-items: center; gap: 1rem; border: 2px solid var(--color-success); transition: all 0.2s; }
.agreement-item:hover { box-shadow: 0 2px 8px rgba(40, 167, 69, 0.15); }
.agreement-item:last-child { margin-bottom: 0; }
.agreement-check { width: 36px; height: 36px; background: var(--color-success); border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.agreement-check svg { width: 20px; height: 20px; stroke: white; stroke-width: 3; }
.agreement-details { flex: 1; }
.agreement-name { font-size: 14px; font-weight: 700; color: #155724; margin-bottom: 0.25rem; }
.agreement-meta { font-size: 12px; color: #6b7280; }
.form-actions-modern { display: flex; gap: 0.75rem; margin-top: 1.5rem; padding-top: 1.5rem; border-top: 2px solid #e5e7eb; }
.btn-fancy { flex: 1; padding: 0.875rem 1.5rem; border: none; border-radius: 8px; font-size: 15px; font-weight: 600; cursor: pointer; transition: all 0.3s; display: flex; align-items: center; justify-content: center; gap: 0.5rem; text-decoration: none; }
.btn-fancy-primary { background: linear-gradient(135deg, var(--color-primary) 0%, #854d37 100%); color: white; box-shadow: 0 4px 12px rgba(156, 96, 70, 0.3); }
.btn-fancy-primary:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(156, 96, 70, 0.4); }
.btn-fancy-secondary { background: #e5e7eb; color: #6b7280; }
.btn-fancy-secondary:hover { background: #d1d5db; }
.grecaptcha-badge { z-index: 999; }
@media (max-width: 768px) { .review-grid { grid-template-columns: 1fr; } .card-hero h1 { font-size: 24px; } .hero-emoji { font-size: 40px; } .card-body-modern { padding: 1.5rem; } .map-container { height: 180px; } }
</style>

<div class="enroll-wrapper">
    <div class="enroll-card">
        <div class="card-hero">
            <div class="hero-emoji">🎉</div>
            <h1>Final Review & Enrollment</h1>
            <p>Confirm your information and complete enrollment</p>
        </div>

        <div class="card-body-modern">
            <?php if (isset($error)): ?>
                <div class="alert alert-danger" style="margin-bottom: 1.5rem;">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <div class="celebration-banner">
                <h2>Almost Done!</h2>
                <p>Review the information below and click "Complete Enrollment" to finalize.</p>
            </div>

            <div class="review-section">
                <h3><span class="icon">👤</span> Personal Information</h3>
                <div class="review-grid">
                    <div class="review-item">
                        <div class="review-label">Name</div>
                        <div class="review-value"><?php echo htmlspecialchars($enrollment['spouse_first_name'] . ' ' . $enrollment['spouse_last_name']); ?></div>
                    </div>
                    <div class="review-item">
                        <div class="review-label">Email</div>
                        <div class="review-value"><?php echo htmlspecialchars($enrollment['spouse_email']); ?></div>
                    </div>
                    <div class="review-item">
                        <div class="review-label">Phone</div>
                        <div class="review-value"><?php echo format_phone($enrollment['spouse_phone']); ?></div>
                    </div>
                </div>
            </div>

            <div class="review-section">
                <h3><span class="icon">🏠</span> Address</h3>
                <div class="review-grid">
                    <div class="review-item">
                        <div class="review-label">Street Address</div>
                        <div class="review-value">
                            <?php echo htmlspecialchars($enrollment['address_line1']); ?>
                            <?php if ($enrollment['address_line2']): ?>
                                <br><?php echo htmlspecialchars($enrollment['address_line2']); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="review-item">
                        <div class="review-label">City, State ZIP</div>
                        <div class="review-value">
                            <?php echo htmlspecialchars($enrollment['city'] . ', ' . $enrollment['state'] . ' ' . $enrollment['zip_code']); ?>
                        </div>
                    </div>
                </div>

                <?php if (GOOGLE_MAPS_ENABLED): ?>
                    <div class="map-container">
                        <div id="addressMap"></div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="review-section">
                <h3><span class="icon">👥</span> Primary Member</h3>
                <div class="review-grid">
                    <div class="review-item">
                        <div class="review-label">Name</div>
                        <div class="review-value"><?php echo htmlspecialchars($enrollment['first_name'] . ' ' . $enrollment['last_name']); ?></div>
                    </div>
                    <div class="review-item">
                        <div class="review-label">Phone (Last 4)</div>
                        <div class="review-value">
                            <?php
                            $phone_clean = preg_replace('/[^0-9]/', '', $enrollment['phone']);
                            echo '***-***-' . substr($phone_clean, -4);
                            ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Signed Agreements Section -->
            <?php if (!empty($contracts_by_type)): ?>
                <div class="agreements-section">
                    <h3><span class="icon">✅</span> Signed Agreements</h3>

                    <?php if (isset($contracts_by_type['croa'])): ?>
                        <div class="agreement-item">
                            <div class="agreement-check">
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                </svg>
                            </div>
                            <div class="agreement-details">
                                <div class="agreement-name">Federal CROA Disclosure</div>
                                <div class="agreement-meta">
                                    Signed by <?php echo htmlspecialchars($contracts_by_type['croa']['signed_by']); ?>
                                    on <span class="local-time" data-utc="<?php echo date('c', strtotime($contracts_by_type['croa']['signed_at'])); ?>">Loading...</span>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (isset($contracts_by_type['client_agreement'])): ?>
                        <div class="agreement-item">
                            <div class="agreement-check">
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                </svg>
                            </div>
                            <div class="agreement-details">
                                <div class="agreement-name">Client Service Agreement</div>
                                <div class="agreement-meta">
                                    Signed by <?php echo htmlspecialchars($contracts_by_type['client_agreement']['signed_by']); ?>
                                    on <span class="local-time" data-utc="<?php echo date('c', strtotime($contracts_by_type['client_agreement']['signed_at'])); ?>">Loading...</span>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="" id="enrollmentForm">
                <input type="hidden" name="recaptcha_token" id="recaptchaToken">
                <div class="form-actions-modern">
                    <a href="<?php echo BASE_URL; ?>/spouse/contracts.php" class="btn-fancy btn-fancy-secondary">
                        <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                        </svg>
                        Back
                    </a>
                    <button type="submit" class="btn-fancy btn-fancy-primary" id="submitBtn">
                        Complete Enrollment
                        <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if (GOOGLE_MAPS_ENABLED): ?>
<script src="https://maps.googleapis.com/maps/api/js?key=<?php echo GOOGLE_MAPS_API_KEY; ?>&libraries=places"></script>
<script>
// Initialize Google Map
function initMap() {
    const address = "<?php echo htmlspecialchars($enrollment['address_line1'] . ', ' . $enrollment['city'] . ', ' . $enrollment['state'] . ' ' . $enrollment['zip_code']); ?>";
    const geocoder = new google.maps.Geocoder();

    geocoder.geocode({ address: address }, function(results, status) {
        if (status === 'OK' && results[0]) {
            const map = new google.maps.Map(document.getElementById('addressMap'), {
                zoom: 15,
                center: results[0].geometry.location,
                mapTypeControl: false,
                streetViewControl: false,
                styles: [
                    {
                        featureType: "poi",
                        elementType: "labels",
                        stylers: [{ visibility: "off" }]
                    }
                ]
            });

            new google.maps.Marker({
                map: map,
                position: results[0].geometry.location,
                animation: google.maps.Animation.DROP,
                icon: {
                    path: google.maps.SymbolPath.CIRCLE,
                    scale: 12,
                    fillColor: "#667eea",
                    fillOpacity: 1,
                    strokeColor: "#ffffff",
                    strokeWeight: 3
                }
            });
        }
    });
}

// Initialize map when page loads
if (document.getElementById('addressMap')) {
    initMap();
}
</script>
<?php endif; ?>

<script>
// Convert UTC timestamps to local time
document.addEventListener('DOMContentLoaded', function() {
    const timeElements = document.querySelectorAll('.local-time');

    timeElements.forEach(function(element) {
        const utcTime = element.getAttribute('data-utc');
        if (utcTime) {
            const date = new Date(utcTime);
            const options = {
                year: 'numeric',
                month: 'long',
                day: 'numeric',
                hour: 'numeric',
                minute: '2-digit',
                hour12: true
            };
            element.textContent = date.toLocaleString('en-US', options);
            console.log('Converted time:', utcTime, '->', element.textContent);
        }
    });

    <?php if (RECAPTCHA_ENABLED): ?>
    // Handle form submission with reCAPTCHA
    const form = document.getElementById('enrollmentForm');
    const submitBtn = document.getElementById('submitBtn');

    form.addEventListener('submit', function(e) {
        e.preventDefault();

        // Disable submit button
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner"></span> Processing...';

        // Execute reCAPTCHA
        if (typeof grecaptcha !== 'undefined' && grecaptcha.enterprise) {
            grecaptcha.enterprise.ready(function() {
                grecaptcha.enterprise.execute('<?php echo RECAPTCHA_SITE_KEY; ?>', {action: 'spouse_review'}).then(function(token) {
                    console.log('reCAPTCHA token generated:', token.substring(0, 50) + '...');
                    document.getElementById('recaptchaToken').value = token;
                    form.submit();
                }).catch(function(error) {
                    console.error('reCAPTCHA error:', error);
                    alert('reCAPTCHA failed to load. Please refresh and try again.');
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = 'Complete Enrollment <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>';
                });
            });
        } else {
            console.error('reCAPTCHA not loaded');
            alert('reCAPTCHA failed to load. Please refresh the page and try again.');
            submitBtn.disabled = false;
            submitBtn.innerHTML = 'Complete Enrollment <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>';
        }
    });
    <?php endif; ?>
});
</script>

<?php include __DIR__ . '/../src/footer.php'; ?>
