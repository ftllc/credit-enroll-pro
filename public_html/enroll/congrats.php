<?php
/**
 * Step 8: Congratulations Page with Speed Up Option
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

// Load company name from database settings
$company_name = COMPANY_NAME; // Fallback to constant
try {
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'company_name' LIMIT 1");
    $stmt->execute();
    $result = $stmt->fetch();
    if ($result && !empty($result['setting_value'])) {
        $company_name = $result['setting_value'];
    }
} catch (Exception $e) {
    // Use fallback if query fails
}

// Convert Lead to Client in CRC on first visit to congrats page
if (!empty($enrollment['crc_record_id']) && !isset($_SESSION['crc_client_updated'])) {
    // Get CRC credentials
    $credentials = get_crc_credentials();

    if ($credentials) {
        // Build XML directly to convert to Client - same format as the working admin test
        $xml = new SimpleXMLElement('<crcloud/>');
        $lead = $xml->addChild('lead');
        $lead->addChild('id', htmlspecialchars($enrollment['crc_record_id'], ENT_XML1));
        $lead->addChild('firstname', htmlspecialchars($enrollment['first_name'], ENT_XML1));
        $lead->addChild('lastname', htmlspecialchars($enrollment['last_name'], ENT_XML1));
        $lead->addChild('email', htmlspecialchars($enrollment['email'], ENT_XML1));
        $lead->addChild('phone_mobile', htmlspecialchars(clean_phone($enrollment['phone']), ENT_XML1));
        $lead->addChild('street_address', htmlspecialchars($enrollment['address_line1'], ENT_XML1));
        $lead->addChild('city', htmlspecialchars($enrollment['city'], ENT_XML1));
        $lead->addChild('state', htmlspecialchars($enrollment['state'], ENT_XML1));
        $lead->addChild('zip', htmlspecialchars($enrollment['zip_code'], ENT_XML1));
        $lead->addChild('type', 'Client'); // Explicitly set to Client - MUST be last like the admin test

        // Add client portal access
        $lead->addChild('client_portal_access', 'on');
        $lead->addChild('client_userid', htmlspecialchars($enrollment['email'], ENT_XML1));
        $lead->addChild('client_agreement', 'Sample Agreement');
        $lead->addChild('portal_language', 'en');

        $xmlData = $xml->asXML();

        // Build API URL - EXACT same as working admin test
        $url = 'https://app.creditrepaircloud.com/api/lead/updateRecord';
        $url .= '?apiauthkey=' . urlencode($credentials['auth_key']);
        $url .= '&secretkey=' . urlencode($credentials['secret_key']);
        $url .= '&xmlData=' . urlencode($xmlData);

        // Make API call - EXACT same as working admin test
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        log_activity("CRC Lead to Client conversion on congrats.php: Record {$enrollment['crc_record_id']}, HTTP {$http_code}, Response: {$response}", 'INFO');

        // Update memo separately to preserve it AND maintain Client status
        if ($http_code === 200 && !empty($enrollment['crc_memo'])) {
            log_activity("CRC updating memo with Client status for record {$enrollment['crc_record_id']}", 'INFO');
            crc_update_memo($enrollment['crc_record_id'], $enrollment['crc_memo'], 'Client');
        }

        $_SESSION['crc_client_updated'] = true;
    }
}

// Create contact in Zoho Books on first visit to congrats page
if (!isset($_SESSION['zoho_contact_created'])) {
    // Get Zoho Books credentials
    $zoho_credentials = get_zoho_credentials();

    if ($zoho_credentials) {
        // Prepare contact data from enrollment
        $contact_data = [
            'first_name' => $enrollment['first_name'],
            'last_name' => $enrollment['last_name'],
            'email' => $enrollment['email'],
            'phone' => $enrollment['phone'],
            'address_line1' => $enrollment['address_line1'],
            'address_line2' => $enrollment['address_line2'] ?? '',
            'city' => $enrollment['city'],
            'state' => $enrollment['state'],
            'zip_code' => $enrollment['zip_code'],
            'country' => 'United States'
        ];

        // Create contact in Zoho Books
        $zoho_result = zoho_create_contact($contact_data);

        if ($zoho_result && isset($zoho_result['contact_id'])) {
            // Store Zoho contact ID in enrollment record
            $stmt = $pdo->prepare("UPDATE enrollment_users SET zoho_contact_id = ? WHERE id = ?");
            $stmt->execute([$zoho_result['contact_id'], $enrollment['id']]);

            log_activity("Zoho Books contact created for enrollment {$enrollment['id']}: Contact ID {$zoho_result['contact_id']}", 'INFO');
        } else {
            log_activity("Failed to create Zoho Books contact for enrollment {$enrollment['id']}", 'WARNING');
        }

        $_SESSION['zoho_contact_created'] = true;
    }
}

// Trigger package generation on first load
if (empty($enrollment['package_status']) && !empty($enrollment['xactosign_package_id'])) {
    // Start package generation
    $client_ip = get_client_ip();
    $client_user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown Browser';

    $stmt = $pdo->prepare("UPDATE enrollment_users SET package_status = 'processing' WHERE id = ?");
    $stmt->execute([$enrollment['id']]);

    // Trigger background package generation (fire and forget)
    $url = BASE_URL . '/enroll/generate_enrollment_package.php';
    $post_data = http_build_query([
        'enrollment_id' => $enrollment['id'],
        'client_ip' => $client_ip,
        'client_user_agent' => $client_user_agent
    ]);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT_MS, 500);
    curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
    curl_exec($ch);
    curl_close($ch);

    // Refresh to show processing status
    $enrollment['package_status'] = 'processing';
}

// Handle "Speed Up" or "Continue Later" button
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['speed_up'])) {
        // They want to speed up - go to personal info
        header('Location: ' . BASE_URL . '/enroll/personal.php');
    } else {
        // They'll do it later - go to complete page
        header('Location: ' . BASE_URL . '/enroll/complete.php');
    }
    exit;
}

$page_title = 'Congratulations!';
include __DIR__ . '/../src/header.php';
?>

<style>
body { background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%); }
.main-container { min-height: calc(100vh - 200px); display: flex; align-items: center; justify-content: center; padding: 2rem 1rem; }
.congrats-wrapper { max-width: 800px; width: 100%; animation: slideUp 0.5s ease-out; position: relative; z-index: 1; }
@keyframes slideUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
.congrats-card { background: white; border-radius: 24px; box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15); overflow: hidden; }
.success-icon { background: linear-gradient(135deg, #10b981 0%, #059669 100%); padding: 3rem 2rem; position: relative; text-align: center; }
.success-icon::after { content: ''; position: absolute; bottom: 0; left: 0; right: 0; height: 30px; background: white; border-radius: 24px 24px 0 0; }
.checkmark { width: 100px; height: 100px; background: white; border-radius: 50%; margin: 0 auto 1.5rem; display: flex; align-items: center; justify-content: center; animation: scaleIn 0.5s ease-out; }
@keyframes scaleIn { 0% { transform: scale(0); } 50% { transform: scale(1.1); } 100% { transform: scale(1); } }
.checkmark svg { width: 60px; height: 60px; color: #10b981; }
.success-title { font-size: 32px; font-weight: 700; color: white; margin: 0 0 0.75rem; }
.success-subtitle { font-size: 16px; color: white; opacity: 0.95; margin: 0; }
.congrats-body { padding: 2.5rem; }
.info-box { background: linear-gradient(135deg, #fff9e6 0%, #fff3cd 100%); border: 2px solid #ffc107; border-radius: 14px; padding: 1.5rem; margin-bottom: 2rem; }
.info-box h3 { font-size: 20px; font-weight: 700; color: #856404; margin: 0 0 0.75rem; }
.info-box p { margin: 0; font-size: 15px; color: #856404; line-height: 1.6; }
.download-section { background: #fafbfc; border-radius: 16px; padding: 2rem; margin-bottom: 2rem; border: 2px solid #e5e7eb; text-align: center; }
.download-section.processing { border-color: #fbbf24; background: #fef3c7; }
.download-section.completed { border-color: #10b981; background: #d1fae5; }
.download-section.failed { border-color: #ef4444; background: #fee2e2; }
.download-section h3 { font-size: 20px; font-weight: 700; margin: 0 0 1rem; }
.download-message { font-size: 14px; color: #6b7280; margin-bottom: 1.5rem; line-height: 1.6; }
.spinner { border: 4px solid rgba(0,0,0,0.1); border-top-color: #fbbf24; border-radius: 50%; width: 50px; height: 50px; animation: spin 1s linear infinite; margin: 0 auto 1.5rem; }
@keyframes spin { to { transform: rotate(360deg); } }
.btn-download { display: inline-flex; align-items: center; gap: 0.625rem; padding: 1.25rem 2.5rem; background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; border: none; border-radius: 14px; font-size: 18px; font-weight: 700; cursor: pointer; text-decoration: none; transition: all 0.3s; box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4); }
.btn-download:hover { transform: translateY(-3px); box-shadow: 0 8px 30px rgba(16, 185, 129, 0.5); }
.package-icon { font-size: 60px; margin-bottom: 1rem; }
.form-actions-modern { display: flex; gap: 1rem; margin-top: 2rem; }
.btn-fancy { flex: 1; padding: 1.125rem 2rem; border: none; border-radius: 14px; font-size: 16px; font-weight: 600; cursor: pointer; transition: all 0.3s; display: flex; align-items: center; justify-content: center; gap: 0.625rem; text-decoration: none; }
.btn-fancy-primary { background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; box-shadow: 0 4px 14px rgba(16, 185, 129, 0.35); }
.btn-fancy-primary:hover { transform: translateY(-2px); box-shadow: 0 6px 24px rgba(16, 185, 129, 0.45); }
.btn-fancy-secondary { background: #f3f4f6; color: #6b7280; }
.btn-fancy-secondary:hover { background: #e5e7eb; }

/* Confetti */
.confetti-container { position: fixed; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none; overflow: hidden; z-index: 9999; }
.confetti { position: absolute; width: 10px; height: 10px; background: #f0f; animation: confetti-fall linear forwards; }
@keyframes confetti-fall {
    to { transform: translateY(100vh) rotate(360deg); opacity: 0; }
}

@media (max-width: 768px) {
    .form-actions-modern { flex-direction: column; }
    .congrats-body { padding: 1.75rem; }
}
</style>

<div class="confetti-container" id="confettiContainer"></div>

<div class="congrats-wrapper">
    <div class="congrats-card">
        <div class="success-icon">
            <div class="checkmark">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
                </svg>
            </div>
            <h1 class="success-title">Congratulations!</h1>
            <p class="success-subtitle">Your agreements have been signed successfully</p>
        </div>

        <div class="congrats-body">
            <div style="text-align: center; padding: 1.5rem 1rem 2rem; margin-bottom: 1.5rem;">
                <p style="font-size: 20px; font-weight: 600; color: #10b981; margin: 0 0 1rem;">
                    Welcome to <?php echo htmlspecialchars($company_name); ?>!
                </p>
                <p style="font-size: 16px; color: #6b7280; margin: 0; line-height: 1.6;">
                    Thank you for signing up! We're excited to help you on your credit repair journey.<br>
                    Check your email for your secure client portal login information.
                </p>
            </div>

            <div class="download-section <?php echo $enrollment['package_status'] ?? ''; ?>" id="packageSection">
                <?php if ($enrollment['package_status'] === 'processing'): ?>
                    <div class="spinner"></div>
                    <h3>Generating Your Complete Contract Package...</h3>
                    <div class="download-message">
                        We're creating your complete signed package with the XactoSign certificate.<br>
                        This usually takes 10-30 seconds. Please wait...<br>
                        <small style="color: #9ca3af; margin-top: 0.5rem; display: block;">Package ID: <?php echo htmlspecialchars($enrollment['xactosign_package_id']); ?></small>
                    </div>
                <?php elseif ($enrollment['package_status'] === 'completed'): ?>
                    <div class="package-icon">📄</div>
                    <h3>Your Contract Package is Ready!</h3>
                    <div class="download-message">
                        Your complete contract package has been generated with all signatures and the XactoSign certificate.<br>
                        <strong>Package ID:</strong> <?php echo htmlspecialchars($enrollment['xactosign_package_id']); ?><br>
                        <strong>Details:</strong> <?php echo $enrollment['package_total_pages']; ?> pages | <?php echo number_format($enrollment['package_file_size'] / 1024, 1); ?> KB
                    </div>
                    <a href="<?php echo BASE_URL; ?>/enroll/download_package.php" class="btn-download" target="_blank">
                        <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                        </svg>
                        View Complete Package
                    </a>
                <?php elseif ($enrollment['package_status'] === 'failed'): ?>
                    <div class="package-icon">⚠️</div>
                    <h3>Package Generation Failed</h3>
                    <div class="download-message">
                        There was an error generating your contract package. Please contact support.<br>
                        <?php if (!empty($enrollment['package_error'])): ?>
                            <small style="color: #dc2626; margin-top: 0.5rem; display: block;">Error: <?php echo htmlspecialchars($enrollment['package_error']); ?></small>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="spinner"></div>
                    <h3>Initializing Package Generation...</h3>
                    <div class="download-message">Please wait a moment...</div>
                <?php endif; ?>
            </div>

            <div class="form-actions-modern">
                <button onclick="window.location.href='<?php echo BASE_URL; ?>/enroll/complete.php'" class="btn-fancy btn-fancy-secondary">
                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                    </svg>
                    We'll Contact You Shortly
                </button>
                <a href="https://secureclientaccess.com" class="btn-fancy btn-fancy-primary">
                    Access My Portal Today
                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                    </svg>
                </a>
            </div>
        </div>
    </div>
</div>

<script>
// Confetti animation
function createConfetti() {
    const container = document.getElementById('confettiContainer');
    const colors = ['#f0f', '#0ff', '#ff0', '#f00', '#0f0', '#00f', '#ffa500', '#ff1493', '#00ff00', '#1e90ff'];
    const confettiCount = 150;

    for (let i = 0; i < confettiCount; i++) {
        const confetti = document.createElement('div');
        confetti.className = 'confetti';
        confetti.style.left = Math.random() * 100 + '%';
        confetti.style.backgroundColor = colors[Math.floor(Math.random() * colors.length)];
        confetti.style.animationDuration = (Math.random() * 3 + 2) + 's';
        confetti.style.animationDelay = (Math.random() * 0.5) + 's';
        confetti.style.opacity = Math.random();

        container.appendChild(confetti);
    }

    // Remove confetti after animation
    setTimeout(() => {
        container.innerHTML = '';
    }, 5000);
}

<?php if ($enrollment['package_status'] === 'completed'): ?>
// Trigger confetti when package is ready
window.addEventListener('load', function() {
    createConfetti();

    // Convert to Client when confetti triggers
    <?php if (!empty($enrollment['crc_record_id'])): ?>
    fetch('test_client_conversion.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'enrollment_id=<?php echo $enrollment['id']; ?>'
    })
    .then(response => response.json())
    .then(data => {
        console.log('Client conversion:', data);
    })
    .catch(error => {
        console.error('Client conversion error:', error);
    });
    <?php endif; ?>
});
<?php elseif ($enrollment['package_status'] === 'processing' || empty($enrollment['package_status'])): ?>
// Auto-refresh page every 3 seconds while package is processing
setTimeout(function() {
    location.reload();
}, 3000);
<?php endif; ?>
</script>

<?php include __DIR__ . '/../src/footer.php'; ?>
