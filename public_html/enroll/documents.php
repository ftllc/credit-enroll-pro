<?php
/**
 * Enrollment Step 7: Document Upload
 * Upload ID and other required documents
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

// Create uploads directory if not exists
$upload_dir = ROOT_PATH . '/uploads/' . $enrollment['session_id'];
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

// Handle file upload
$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['upload_file'])) {
        $doc_type = sanitize_input($_POST['doc_type'] ?? '');

        if (empty($doc_type)) {
            $errors[] = 'Please select document type';
        }

        if (isset($_FILES['document']) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['document'];
            $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'application/pdf'];
            $max_size = 10 * 1024 * 1024; // 10MB

            if (!in_array($file['type'], $allowed_types)) {
                $errors[] = 'Invalid file type. Only JPG, PNG, and PDF files are allowed.';
            }

            if ($file['size'] > $max_size) {
                $errors[] = 'File size exceeds 10MB limit.';
            }

            if (empty($errors)) {
                $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
                $filename = $doc_type . '_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
                $filepath = $upload_dir . '/' . $filename;

                if (move_uploaded_file($file['tmp_name'], $filepath)) {
                    try {
                        $stmt = $pdo->prepare("
                            INSERT INTO id_docs (enrollment_id, doc_type, file_path, file_size, uploaded_at)
                            VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)
                        ");
                        $stmt->execute([$enrollment['id'], $doc_type, $filepath, $file['size']]);

                        $success = 'Document uploaded successfully!';
                    } catch (PDOException $e) {
                        $errors[] = 'Failed to save document information.';
                        if (DEBUG_MODE) $errors[] = $e->getMessage();
                    }
                } else {
                    $errors[] = 'Failed to upload file. Please try again.';
                }
            }
        } else {
            $errors[] = 'Please select a file to upload.';
        }
    } elseif (isset($_POST['complete_enrollment'])) {
        // Check if at least one document uploaded
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM id_docs WHERE enrollment_id = ?");
        $stmt->execute([$enrollment['id']]);
        $doc_count = $stmt->fetchColumn();

        if ($doc_count < 1) {
            $errors[] = 'Please upload at least one form of identification before completing enrollment.';
        } else {
            try {
                $stmt = $pdo->prepare("
                    UPDATE enrollment_users
                    SET status = 'completed', completed_at = CURRENT_TIMESTAMP,
                        current_step = 7, last_activity = CURRENT_TIMESTAMP
                    WHERE session_id = ?
                ");
                $stmt->execute([$_SESSION['enrollment_session_id']]);

                // Redirect to success page (CRC client conversion happens on congrats.php)
                header('Location: ' . BASE_URL . '/enroll/complete.php');
                exit;

            } catch (PDOException $e) {
                $errors[] = 'An error occurred. Please try again.';
                if (DEBUG_MODE) $errors[] = $e->getMessage();
            }
        }
    }
}

// Get uploaded documents
$stmt = $pdo->prepare("SELECT * FROM id_docs WHERE enrollment_id = ? ORDER BY uploaded_at DESC");
$stmt->execute([$enrollment['id']]);
$documents = $stmt->fetchAll();

$page_title = 'Upload Documents';
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
    max-width: 800px;
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

.alert-success {
    background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
    border-left: 5px solid var(--color-success);
}

.alert-fancy strong {
    display: block;
    color: var(--color-danger);
    font-size: 16px;
    margin-bottom: 0.75rem;
}

.alert-success strong {
    color: var(--color-success);
}

.upload-section {
    background: #fafbfc;
    border-radius: 16px;
    padding: 2rem;
    margin-bottom: 2rem;
    border: 2px solid #e5e7eb;
}

.upload-section h3 {
    font-size: 18px;
    font-weight: 700;
    color: var(--color-primary);
    margin: 0 0 1.5rem;
}

.form-group-modern {
    margin-bottom: 1.5rem;
}

.form-group-modern label {
    display: block;
    font-size: 14px;
    font-weight: 600;
    color: #374151;
    margin-bottom: 0.625rem;
}

.form-group-modern select,
.file-input-wrapper input[type="file"] {
    width: 100%;
    padding: 1rem;
    border: 2px solid #e5e7eb;
    border-radius: 12px;
    font-size: 16px;
    font-family: var(--font-family);
    transition: all 0.3s;
    background: white;
}

.form-group-modern select:focus {
    outline: none;
    border-color: var(--color-primary);
    box-shadow: 0 0 0 4px rgba(156, 96, 70, 0.1);
}

.file-input-wrapper {
    position: relative;
}

.file-input-custom {
    display: block;
    width: 100%;
    padding: 2rem;
    border: 3px dashed #e5e7eb;
    border-radius: 14px;
    text-align: center;
    background: white;
    cursor: pointer;
    transition: all 0.3s;
}

.file-input-custom:hover {
    border-color: var(--color-primary);
    background: #fef7f5;
}

.file-input-custom svg {
    width: 48px;
    height: 48px;
    color: var(--color-primary);
    margin-bottom: 1rem;
}

.file-input-custom p {
    margin: 0;
    color: #6b7280;
    font-size: 15px;
}

.file-input-custom strong {
    color: var(--color-primary);
}

.file-input-wrapper input[type="file"] {
    display: none;
}

.documents-list {
    margin-top: 2rem;
}

.document-item {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1.25rem;
    background: white;
    border: 2px solid #e5e7eb;
    border-radius: 12px;
    margin-bottom: 1rem;
}

.document-icon {
    width: 48px;
    height: 48px;
    background: linear-gradient(135deg, var(--color-primary) 0%, #854d37 100%);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.document-icon svg {
    width: 24px;
    height: 24px;
    color: white;
}

.document-info {
    flex: 1;
}

.document-name {
    font-weight: 600;
    color: #374151;
    font-size: 15px;
    margin-bottom: 0.25rem;
}

.document-meta {
    font-size: 13px;
    color: #6b7280;
}

.document-status {
    padding: 0.5rem 1rem;
    background: #d4edda;
    color: #155724;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
}

.info-box {
    background: linear-gradient(135deg, #fff9e6 0%, #fff3cd 100%);
    border: 2px solid #ffc107;
    border-radius: 14px;
    padding: 1.25rem;
    margin-bottom: 2rem;
    display: flex;
    gap: 0.75rem;
}

.info-box svg {
    width: 24px;
    height: 24px;
    color: #856404;
    flex-shrink: 0;
}

.info-box p {
    margin: 0;
    font-size: 14px;
    color: #856404;
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
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white;
    box-shadow: 0 4px 14px rgba(16, 185, 129, 0.35);
}

.btn-fancy-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 24px rgba(16, 185, 129, 0.45);
}

.btn-fancy-secondary {
    background: #f3f4f6;
    color: #6b7280;
}

.btn-fancy-secondary:hover {
    background: #e5e7eb;
}

.btn-fancy-upload {
    background: linear-gradient(135deg, var(--color-primary) 0%, #854d37 100%);
    color: white;
    box-shadow: 0 4px 14px rgba(156, 96, 70, 0.35);
}

.btn-fancy-upload:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 24px rgba(156, 96, 70, 0.45);
}

@media (max-width: 768px) {
    .main-container {
        padding: 1rem;
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

    .upload-section {
        padding: 1.5rem;
    }

    .form-actions-modern {
        flex-direction: column-reverse;
    }
}
</style>

<div class="enroll-wrapper">
    <div class="enroll-card">
        <div class="card-hero">
            <h1>Upload Documents</h1>
            <p>Final step - upload your identification</p>
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

            <?php if (!empty($success)): ?>
                <div class="alert-fancy alert-success">
                    <strong><?php echo htmlspecialchars($success); ?></strong>
                </div>
            <?php endif; ?>

            <div class="info-box">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <p><strong>Required:</strong> Please upload a clear photo of your government-issued ID (driver's license, passport, or state ID). Accepted formats: JPG, PNG, PDF (max 10MB)</p>
            </div>

            <div class="upload-section">
                <h3>Upload New Document</h3>

                <form method="POST" action="" enctype="multipart/form-data">
                    <input type="hidden" name="upload_file" value="1">

                    <div class="form-group-modern">
                        <label for="doc_type">Document Type *</label>
                        <select name="doc_type" id="doc_type" required>
                            <option value="">Select type...</option>
                            <option value="drivers_license">Driver's License</option>
                            <option value="passport">Passport</option>
                            <option value="state_id">State ID</option>
                            <option value="other">Other ID</option>
                        </select>
                    </div>

                    <div class="form-group-modern">
                        <label>Upload File *</label>
                        <div class="file-input-wrapper">
                            <label for="document" class="file-input-custom">
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                                </svg>
                                <p><strong>Choose a file</strong> or drag and drop<br>JPG, PNG, PDF up to 10MB</p>
                                <p id="file-name" style="color: var(--color-primary); margin-top: 0.5rem; font-weight: 600;"></p>
                            </label>
                            <input type="file" name="document" id="document" accept="image/jpeg,image/jpg,image/png,application/pdf" required>
                        </div>
                    </div>

                    <button type="submit" class="btn-fancy btn-fancy-upload" style="width: 100%;">
                        <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                        </svg>
                        Upload Document
                    </button>
                </form>
            </div>

            <?php if (!empty($documents)): ?>
                <div class="documents-list">
                    <h3 style="font-size: 18px; font-weight: 700; color: var(--color-primary); margin-bottom: 1rem;">Uploaded Documents</h3>

                    <?php foreach ($documents as $doc): ?>
                        <div class="document-item">
                            <div class="document-icon">
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                </svg>
                            </div>
                            <div class="document-info">
                                <div class="document-name"><?php echo ucwords(str_replace('_', ' ', $doc['doc_type'])); ?></div>
                                <div class="document-meta">
                                    Uploaded <?php echo date('M j, Y g:i A', strtotime($doc['uploaded_at'])); ?> •
                                    <?php echo round($doc['file_size'] / 1024 / 1024, 2); ?> MB
                                </div>
                            </div>
                            <div class="document-status">✓ Uploaded</div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="form-actions-modern">
                    <a href="<?php echo BASE_URL; ?>/enroll/personal.php" class="btn-fancy btn-fancy-secondary">
                        <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                        </svg>
                        Back
                    </a>
                    <button type="submit" name="complete_enrollment" class="btn-fancy btn-fancy-primary">
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

<script>
// File input preview
document.getElementById('document').addEventListener('change', function(e) {
    const fileName = e.target.files[0]?.name || '';
    const fileNameDisplay = document.getElementById('file-name');
    if (fileName) {
        fileNameDisplay.textContent = 'Selected: ' + fileName;
    }
});
</script>

<?php include __DIR__ . '/../src/footer.php'; ?>
