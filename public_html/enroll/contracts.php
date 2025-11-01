<?php
/**
 * Step 6: Contract Acceptance (Federal CROA + Client Agreement)
 *
 * This page displays PDF previews of the CROA and State Agreement contracts
 * with customer information auto-filled and "SIGNATURE PENDING" watermarks.
 *
 * Features:
 * - Automatically selects state-specific contracts based on customer's state
 * - Falls back to default contract package if no state-specific package exists
 * - Pre-fills all customer information from enrollment_users table
 * - Displays "SIGNATURE PENDING" watermarks where signatures will go
 * - Generates PDFs on-the-fly via generate_contract_preview.php
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

$plan = null;
if ($enrollment['plan_id']) {
    $stmt = $pdo->prepare("SELECT * FROM plans WHERE id = ?");
    $stmt->execute([$enrollment['plan_id']]);
    $plan = $stmt->fetch();
}

$errors = [];
$debug_info = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $croa_signature = trim($_POST['croa_signature_data'] ?? '');
    $agreement_signature = trim($_POST['agreement_signature_data'] ?? '');
    $poa_signature = trim($_POST['poa_signature_data'] ?? '');
    $agree_croa = isset($_POST['agree_croa']);
    $agree_agreement = isset($_POST['agree_agreement']);
    $agree_poa = isset($_POST['agree_poa']);

    // Debug info
    if (DEBUG_MODE) {
        $debug_info = "POST data received:\n";
        $debug_info .= "CROA checked: " . ($agree_croa ? 'Yes' : 'No') . "\n";
        $debug_info .= "Agreement checked: " . ($agree_agreement ? 'Yes' : 'No') . "\n";
        $debug_info .= "POA checked: " . ($agree_poa ? 'Yes' : 'No') . "\n";
        $debug_info .= "CROA signature length: " . strlen($croa_signature) . "\n";
        $debug_info .= "Agreement signature length: " . strlen($agreement_signature) . "\n";
        $debug_info .= "POA signature length: " . strlen($poa_signature) . "\n";
    }

    if (!$agree_croa) $errors[] = 'You must accept the Federal CROA Disclosure';
    if (empty($croa_signature)) $errors[] = 'Please sign the Federal CROA Disclosure';
    if (!$agree_agreement) $errors[] = 'You must accept the Client Agreement';
    if (empty($agreement_signature)) $errors[] = 'Please sign the Client Agreement';
    if (!$agree_poa) $errors[] = 'You must accept the Power of Attorney';
    if (empty($poa_signature)) $errors[] = 'Please sign the Power of Attorney';

    if (empty($errors)) {
        try {
            $client_ip = get_client_ip();
            $client_user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown Browser';
            $signer_name = $enrollment['first_name'] . ' ' . $enrollment['last_name'];

            // Get the contract package for this enrollment
            $package = null;
            if (!empty($enrollment['state'])) {
                $stmt = $pdo->prepare("
                    SELECT scp.*
                    FROM state_contract_packages scp
                    INNER JOIN state_contract_mappings scm ON scp.id = scm.package_id
                    WHERE scm.state_code = ?
                    LIMIT 1
                ");
                $stmt->execute([$enrollment['state']]);
                $package = $stmt->fetch();
            }

            // If no state-specific package, use default
            if (!$package) {
                $stmt = $pdo->prepare("SELECT * FROM state_contract_packages WHERE is_default = 1 LIMIT 1");
                $stmt->execute();
                $package = $stmt->fetch();
            }

            if (!$package) {
                throw new Exception("No contract package found");
            }

            // Generate XactoSign package ID
            $cert_id = strtoupper(substr(md5(time() . $enrollment['id'] . rand()), 0, 12));
            if (!empty($package['xactosign_client_id'])) {
                $xactosign_package_id = 'XACT-' . $package['xactosign_client_id'] . '-' . $cert_id;
            } else {
                $xactosign_package_id = 'XACT-' . $cert_id;
            }

            // Insert CROA signature
            $stmt = $pdo->prepare("
                INSERT INTO contracts (enrollment_id, contract_type, signature_data, ip_address, signed, signed_at, signed_by)
                VALUES (?, 'croa', ?, ?, 1, CURRENT_TIMESTAMP, ?)
            ");
            $stmt->execute([
                $enrollment['id'],
                $croa_signature,
                $client_ip,
                $signer_name
            ]);

            // Insert Client Agreement signature
            $stmt = $pdo->prepare("
                INSERT INTO contracts (enrollment_id, contract_type, signature_data, ip_address, signed, signed_at, signed_by)
                VALUES (?, 'client_agreement', ?, ?, 1, CURRENT_TIMESTAMP, ?)
            ");
            $stmt->execute([
                $enrollment['id'],
                $agreement_signature,
                $client_ip,
                $signer_name
            ]);

            // Insert Power of Attorney signature
            $stmt = $pdo->prepare("
                INSERT INTO contracts (enrollment_id, contract_type, signature_data, ip_address, signed, signed_at, signed_by)
                VALUES (?, 'power_of_attorney', ?, ?, 1, CURRENT_TIMESTAMP, ?)
            ");
            $stmt->execute([
                $enrollment['id'],
                $poa_signature,
                $client_ip,
                $signer_name
            ]);

            // Update enrollment with package info (but don't start processing yet)
            $stmt = $pdo->prepare("
                UPDATE enrollment_users
                SET current_step = 6,
                    last_activity = CURRENT_TIMESTAMP,
                    package_id = ?,
                    xactosign_package_id = ?
                WHERE session_id = ?
            ");
            $stmt->execute([$package['id'], $xactosign_package_id, $_SESSION['enrollment_session_id']]);

            // Update CRC memo - contracts signed
            if (!empty($enrollment['crc_record_id'])) {
                $memo = "--- CONTRACTS SIGNED ---\n";
                $memo .= "Signed at: " . date('Y-m-d H:i:s') . "\n";
                $memo .= "IP Address: {$client_ip}\n\n";
                $memo .= "--- STATUS: Pending Confirmation";

                crc_append_enrollment_memo($enrollment['crc_record_id'], $enrollment['id'], $memo);
            }

            // Send Contracts Signed (Staff Notification)
            require_once __DIR__ . '/../src/notification_helper.php';

            // Get plan name
            $plan_name = 'Credit Repair Service';
            if (!empty($enrollment['plan_id'])) {
                $stmt = $pdo->prepare("SELECT plan_name FROM plans WHERE id = ?");
                $stmt->execute([$enrollment['plan_id']]);
                $plan_result = $stmt->fetch();
                if ($plan_result) {
                    $plan_name = $plan_result['plan_name'];
                }
            }

            $notification_data = [
                'client_name' => $signer_name,
                'client_first_name' => $enrollment['first_name'],
                'client_last_name' => $enrollment['last_name'],
                'client_email' => $enrollment['email'],
                'client_phone' => format_phone($enrollment['phone']),
                'plan_name' => $plan_name,
                'enrollment_url' => BASE_URL . '/admin/enrollments.php?id=' . $enrollment['id']
            ];

            $staff_result = notify_staff('contracts_signed_staff', 'notify_contracts_signed', $notification_data);
            log_activity("Contracts Signed (Staff) notification sent to {$staff_result['staff_notified']} staff members", 'INFO');

            // Success - redirect to final review (package will be generated after review confirmation)
            header('Location: ' . BASE_URL . '/enroll/review.php');
            exit;

        } catch (PDOException $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
            if (DEBUG_MODE) {
                $debug_info .= "\nPDO Error: " . $e->getMessage() . "\n";
                $debug_info .= "Error Code: " . $e->getCode() . "\n";
            }
        }
    }
}

$page_title = 'Sign Agreements';
include __DIR__ . '/../src/header.php';
?>
<style>
body { background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%); }
.main-container { padding: 2rem 1rem; }
.enroll-wrapper { max-width: 900px; margin: 0 auto; animation: slideUp 0.5s ease-out; }
@keyframes slideUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
.enroll-card { background: white; border-radius: 24px; box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15); overflow: hidden; }
.card-hero { background: linear-gradient(135deg, var(--color-primary) 0%, #854d37 100%); color: white; padding: 3rem 2.5rem; text-align: center; position: relative; }
.card-hero::after { content: ''; position: absolute; bottom: 0; left: 0; right: 0; height: 30px; background: white; border-radius: 24px 24px 0 0; }
.card-hero h1 { font-size: 32px; font-weight: 700; margin: 0 0 0.75rem; color: white; }
.card-hero p { margin: 0; font-size: 16px; opacity: 0.95; }
.card-body-modern { padding: 2.5rem; }
.alert-fancy { background: linear-gradient(135deg, #fee 0%, #fdd 100%); border-left: 5px solid var(--color-danger); padding: 1.25rem; border-radius: 12px; margin-bottom: 2rem; }
.alert-fancy strong { display: block; color: var(--color-danger); font-size: 16px; margin-bottom: 0.75rem; }
.alert-fancy ul { margin: 0; padding-left: 1.5rem; }
.alert-fancy pre { background: #f8f9fa; padding: 1rem; border-radius: 8px; font-size: 12px; overflow-x: auto; }
.agreement-section { background: #fafbfc; border-radius: 16px; padding: 2rem; margin-bottom: 2rem; border: 2px solid #e5e7eb; }
.agreement-section h3 { font-size: 20px; font-weight: 700; color: var(--color-primary); margin: 0 0 1rem; }
.agreement-box { background: white; border: 2px solid #e5e7eb; border-radius: 12px; padding: 1.5rem; max-height: 300px; overflow-y: auto; margin-bottom: 1.5rem; font-size: 14px; line-height: 1.8; color: #4b5563; }
.agreement-box h4 { color: var(--color-primary); margin: 1.5rem 0 0.75rem; font-size: 16px; }
.agreement-box h4:first-child { margin-top: 0; }
.pdf-preview-box { background: white; border: 2px solid #e5e7eb; border-radius: 12px; padding: 0.5rem; margin-bottom: 1.5rem; }
.pdf-preview-box iframe { display: block; width: 100%; min-height: 600px; border-radius: 8px; }
.checkbox-fancy { display: flex; align-items: flex-start; gap: 0.75rem; padding: 1.25rem; background: white; border-radius: 12px; border: 2px solid #e5e7eb; cursor: pointer; transition: all 0.3s; margin-bottom: 1.5rem; }
.checkbox-fancy:hover { border-color: var(--color-primary); background: #fef7f5; }
.checkbox-fancy.checked { border-color: var(--color-success); background: #f0fdf4; }
.checkbox-fancy input[type="checkbox"] { width: 20px; height: 20px; cursor: pointer; margin-top: 2px; }
.checkbox-fancy label { flex: 1; cursor: pointer; margin: 0; font-size: 15px; color: #374151; line-height: 1.6; }
.signature-wrapper { margin-bottom: 1.5rem; }
.signature-label { font-size: 15px; font-weight: 600; color: #374151; margin-bottom: 0.75rem; display: block; }
.signature-canvas-wrapper { background: white; border: 3px dashed #e5e7eb; border-radius: 14px; padding: 1rem; transition: all 0.3s; }
.signature-canvas-wrapper.has-signature { border-color: var(--color-success); border-style: solid; background: #f0fdf4; }
.signature-canvas { border: 1px solid #e5e7eb; border-radius: 8px; cursor: crosshair; touch-action: none; display: block; width: 100%; background: white; }
.signature-actions { display: flex; gap: 0.75rem; justify-content: flex-end; margin-top: 0.75rem; }
.btn-clear { padding: 0.5rem 1rem; background: #f3f4f6; color: #6b7280; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.3s; }
.btn-clear:hover { background: #e5e7eb; }
.form-actions-modern { display: flex; gap: 1rem; margin-top: 2.5rem; padding-top: 2rem; border-top: 2px solid #f3f4f6; }
.btn-fancy { flex: 1; padding: 1.125rem 2rem; border: none; border-radius: 14px; font-size: 16px; font-weight: 600; cursor: pointer; transition: all 0.3s; display: flex; align-items: center; justify-content: center; gap: 0.625rem; text-decoration: none; }
.btn-fancy-primary { background: linear-gradient(135deg, var(--color-primary) 0%, #854d37 100%); color: white; box-shadow: 0 4px 14px rgba(156, 96, 70, 0.35); }
.btn-fancy-primary:hover { transform: translateY(-2px); box-shadow: 0 6px 24px rgba(156, 96, 70, 0.45); }
.btn-fancy-secondary { background: #f3f4f6; color: #6b7280; }
.btn-fancy-secondary:hover { background: #e5e7eb; }
</style>

<div class="enroll-wrapper">
    <div class="enroll-card">
        <div class="card-hero">
            <h1>Sign Agreements</h1>
            <p>Please read and sign both required documents</p>
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
                    <?php if (!empty($debug_info)): ?>
                        <pre><?php echo htmlspecialchars($debug_info); ?></pre>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="" id="contractForm">
                <!-- FEDERAL CROA DISCLOSURE -->
                <div class="agreement-section">
                    <h3>1. Federal CROA Disclosure</h3>
                    <div class="pdf-preview-box">
                        <iframe src="<?php echo BASE_URL; ?>/enroll/generate_contract_preview.php?type=croa#view=FitH&toolbar=0&navpanes=0" width="100%" height="600px" style="border: 2px solid #e5e7eb; border-radius: 12px;"></iframe>
                    </div>

                    <div class="checkbox-fancy" id="croaCheckbox">
                        <input type="checkbox" name="agree_croa" id="agree_croa" value="1" required>
                        <label for="agree_croa"><strong>I have read and understand</strong> my rights under the Credit Repair Organizations Act</label>
                    </div>

                    <div class="signature-wrapper">
                        <label class="signature-label">Sign Below to Acknowledge *</label>
                        <div class="signature-canvas-wrapper" id="croaCanvasWrapper">
                            <canvas id="croaCanvas" class="signature-canvas" width="800" height="200"></canvas>
                            <input type="hidden" name="croa_signature_data" id="croaSignatureData" required>
                        </div>
                        <div class="signature-actions">
                            <button type="button" class="btn-clear" onclick="clearSignature('croa')">Clear Signature</button>
                        </div>
                    </div>
                </div>

                <!-- CLIENT AGREEMENT -->
                <div class="agreement-section">
                    <h3>2. Client Service Agreement & Notice of Cancellation</h3>
                    <div class="pdf-preview-box">
                        <iframe src="<?php echo BASE_URL; ?>/enroll/generate_contract_preview.php?type=agreement#view=FitH&toolbar=0&navpanes=0" width="100%" height="600px" style="border: 2px solid #e5e7eb; border-radius: 12px;"></iframe>
                    </div>

                    <div class="checkbox-fancy" id="agreementCheckbox">
                        <input type="checkbox" name="agree_agreement" id="agree_agreement" value="1" required>
                        <label for="agree_agreement"><strong>I agree</strong> to the terms and conditions of this service agreement</label>
                    </div>

                    <div class="signature-wrapper">
                        <label class="signature-label">Sign Below to Accept Agreement *</label>
                        <div class="signature-canvas-wrapper" id="agreementCanvasWrapper">
                            <canvas id="agreementCanvas" class="signature-canvas" width="800" height="200"></canvas>
                            <input type="hidden" name="agreement_signature_data" id="agreementSignatureData" required>
                        </div>
                        <div class="signature-actions">
                            <button type="button" class="btn-clear" onclick="clearSignature('agreement')">Clear Signature</button>
                        </div>
                    </div>
                </div>

                <!-- POWER OF ATTORNEY -->
                <div class="agreement-section">
                    <h3>3. Power of Attorney</h3>
                    <div class="pdf-preview-box">
                        <iframe src="<?php echo BASE_URL; ?>/enroll/generate_contract_preview.php?type=poa#view=FitH&toolbar=0&navpanes=0" width="100%" height="600px" style="border: 2px solid #e5e7eb; border-radius: 12px;"></iframe>
                    </div>

                    <div class="checkbox-fancy" id="poaCheckbox">
                        <input type="checkbox" name="agree_poa" id="agree_poa" value="1" required>
                        <label for="agree_poa"><strong>I agree</strong> to grant Power of Attorney as specified</label>
                    </div>

                    <div class="signature-wrapper">
                        <label class="signature-label">Sign Below to Grant Power of Attorney *</label>
                        <div class="signature-canvas-wrapper" id="poaCanvasWrapper">
                            <canvas id="poaCanvas" class="signature-canvas" width="800" height="200"></canvas>
                            <input type="hidden" name="poa_signature_data" id="poaSignatureData" required>
                        </div>
                        <div class="signature-actions">
                            <button type="button" class="btn-clear" onclick="clearSignature('poa')">Clear Signature</button>
                        </div>
                    </div>
                </div>

                <div class="form-actions-modern">
                    <a href="<?php echo BASE_URL; ?>/enroll/<?php echo $enrollment['has_spouse'] ? 'spouse' : 'plan'; ?>.php" class="btn-fancy btn-fancy-secondary">
                        <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                        </svg>
                        Back
                    </a>
                    <button type="submit" class="btn-fancy btn-fancy-primary" id="submitBtn">
                        Continue
                        <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const canvases = {
    croa: { canvas: document.getElementById('croaCanvas'), wrapper: document.getElementById('croaCanvasWrapper') },
    agreement: { canvas: document.getElementById('agreementCanvas'), wrapper: document.getElementById('agreementCanvasWrapper') },
    poa: { canvas: document.getElementById('poaCanvas'), wrapper: document.getElementById('poaCanvasWrapper') }
};

Object.keys(canvases).forEach(key => {
    const { canvas, wrapper } = canvases[key];
    const ctx = canvas.getContext('2d');
    let drawing = false;
    let hasDrawn = false;

    const setCanvasSize = () => {
        const rect = canvas.getBoundingClientRect();
        canvas.width = rect.width * 2;
        canvas.height = rect.height * 2;
        canvas.style.width = rect.width + 'px';
        canvas.style.height = rect.height + 'px';
        ctx.scale(2, 2);
        ctx.strokeStyle = '#000';
        ctx.lineWidth = 2;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
    };

    setCanvasSize();

    const getPos = (e) => {
        const rect = canvas.getBoundingClientRect();
        const clientX = e.touches ? e.touches[0].clientX : e.clientX;
        const clientY = e.touches ? e.touches[0].clientY : e.clientY;
        return { x: clientX - rect.left, y: clientY - rect.top };
    };

    const startDrawing = (e) => {
        e.preventDefault();
        drawing = true;
        const pos = getPos(e);
        ctx.beginPath();
        ctx.moveTo(pos.x, pos.y);
    };

    const draw = (e) => {
        if (!drawing) return;
        e.preventDefault();
        const pos = getPos(e);
        ctx.lineTo(pos.x, pos.y);
        ctx.stroke();
        hasDrawn = true;
    };

    const stopDrawing = () => {
        if (!drawing) return;
        drawing = false;
        ctx.closePath();
        if (hasDrawn) {
            const dataURL = canvas.toDataURL('image/png');
            document.getElementById(key + 'SignatureData').value = dataURL;
            wrapper.classList.add('has-signature');
            console.log(key + ' signature saved, length: ' + dataURL.length);
        }
    };

    canvas.addEventListener('mousedown', startDrawing);
    canvas.addEventListener('mousemove', draw);
    canvas.addEventListener('mouseup', stopDrawing);
    canvas.addEventListener('mouseout', stopDrawing);
    canvas.addEventListener('touchstart', startDrawing);
    canvas.addEventListener('touchmove', draw);
    canvas.addEventListener('touchend', stopDrawing);
});

function clearSignature(type) {
    const { canvas, wrapper } = canvases[type];
    const ctx = canvas.getContext('2d');
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    document.getElementById(type + 'SignatureData').value = '';
    wrapper.classList.remove('has-signature');
    console.log(type + ' signature cleared');
}

// Checkbox visual feedback
document.getElementById('agree_croa').addEventListener('change', function() {
    document.getElementById('croaCheckbox').classList.toggle('checked', this.checked);
});

document.getElementById('agree_agreement').addEventListener('change', function() {
    document.getElementById('agreementCheckbox').classList.toggle('checked', this.checked);
});

document.getElementById('agree_poa').addEventListener('change', function() {
    document.getElementById('poaCheckbox').classList.toggle('checked', this.checked);
});

// Form validation
document.getElementById('contractForm').addEventListener('submit', function(e) {
    const croaChecked = document.getElementById('agree_croa').checked;
    const agreementChecked = document.getElementById('agree_agreement').checked;
    const poaChecked = document.getElementById('agree_poa').checked;
    const croaData = document.getElementById('croaSignatureData').value;
    const agreementData = document.getElementById('agreementSignatureData').value;
    const poaData = document.getElementById('poaSignatureData').value;

    console.log('Form submission:', { croaChecked, agreementChecked, poaChecked, croaDataLength: croaData.length, agreementDataLength: agreementData.length, poaDataLength: poaData.length });

    let errors = [];
    if (!croaChecked) errors.push('✗ Please check the Federal CROA Disclosure checkbox');
    if (!croaData) errors.push('✗ Please sign the Federal CROA Disclosure');
    if (!agreementChecked) errors.push('✗ Please check the Client Agreement checkbox');
    if (!agreementData) errors.push('✗ Please sign the Client Agreement');
    if (!poaChecked) errors.push('✗ Please check the Power of Attorney checkbox');
    if (!poaData) errors.push('✗ Please sign the Power of Attorney');

    if (errors.length > 0) {
        e.preventDefault();
        alert('Please complete the following:\n\n' + errors.join('\n'));
        return false;
    }

    // Disable submit button to prevent double submission
    document.getElementById('submitBtn').disabled = true;
    document.getElementById('submitBtn').innerHTML = '<span class="spinner" style="border: 2px solid rgba(255,255,255,0.3); border-top-color: white; border-radius: 50%; width: 20px; height: 20px; animation: spin 0.8s linear infinite; display: inline-block;"></span> Saving...';
});
</script>
<style>
@keyframes spin { to { transform: rotate(360deg); } }
</style>

<?php include __DIR__ . '/../src/footer.php'; ?>
