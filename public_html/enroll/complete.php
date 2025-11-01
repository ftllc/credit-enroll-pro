<?php
/**
 * Enrollment Complete - Success Page
 */

define('SNS_ENROLLMENT', true);
require_once __DIR__ . '/../src/config.php';

session_start();

// Check if user completed enrollment
if (!isset($_SESSION['enrollment_session_id'])) {
    header('Location: ' . BASE_URL . '/enroll/');
    exit;
}

// Get enrollment data
$stmt = $pdo->prepare("SELECT * FROM enrollment_users WHERE session_id = ? AND status = 'completed'");
$stmt->execute([$_SESSION['enrollment_session_id']]);
$enrollment = $stmt->fetch();

if (!$enrollment) {
    header('Location: ' . BASE_URL . '/enroll/');
    exit;
}

$page_title = 'Enrollment Complete!';
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

.success-wrapper {
    max-width: 700px;
    width: 100%;
    animation: slideUp 0.5s ease-out;
}

@keyframes slideUp {
    from { opacity: 0; transform: translateY(30px); }
    to { opacity: 1; transform: translateY(0); }
}

.success-card {
    background: white;
    border-radius: 24px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
    overflow: hidden;
    text-align: center;
}

.success-icon {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    padding: 3rem 2rem;
    position: relative;
}

.success-icon::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 30px;
    background: white;
    border-radius: 24px 24px 0 0;
}

.checkmark {
    width: 100px;
    height: 100px;
    background: white;
    border-radius: 50%;
    margin: 0 auto 1.5rem;
    display: flex;
    align-items: center;
    justify-content: center;
    animation: scaleIn 0.5s ease-out;
}

@keyframes scaleIn {
    0% { transform: scale(0); }
    50% { transform: scale(1.1); }
    100% { transform: scale(1); }
}

.checkmark svg {
    width: 60px;
    height: 60px;
    color: #10b981;
}

.success-title {
    font-size: 32px;
    font-weight: 700;
    color: white;
    margin: 0 0 0.75rem;
}

.success-subtitle {
    font-size: 16px;
    color: white;
    opacity: 0.95;
    margin: 0;
}

.success-body {
    padding: 2.5rem;
}

.session-id-box {
    background: linear-gradient(135deg, #fafbfc 0%, #f0f1f3 100%);
    border: 2px solid var(--color-primary);
    border-radius: 16px;
    padding: 1.5rem;
    margin-bottom: 2rem;
}

.session-id-label {
    font-size: 14px;
    font-weight: 600;
    color: #6b7280;
    margin-bottom: 0.5rem;
}

.session-id-value {
    font-size: 28px;
    font-weight: 700;
    color: var(--color-primary);
    letter-spacing: 2px;
    font-family: 'Courier New', monospace;
}

.next-steps {
    text-align: left;
    background: #fafbfc;
    border-radius: 14px;
    padding: 2rem;
    margin-bottom: 2rem;
}

.next-steps h3 {
    font-size: 20px;
    font-weight: 700;
    color: var(--color-primary);
    margin: 0 0 1.5rem;
}

.steps-list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.steps-list li {
    padding: 1rem;
    background: white;
    border-radius: 10px;
    margin-bottom: 0.75rem;
    display: flex;
    align-items: center;
    gap: 1rem;
}

.step-number {
    width: 36px;
    height: 36px;
    background: linear-gradient(135deg, var(--color-primary) 0%, #854d37 100%);
    color: white;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    flex-shrink: 0;
}

.step-text {
    flex: 1;
    font-size: 15px;
    color: #374151;
    line-height: 1.5;
}

.action-buttons {
    display: flex;
    gap: 1rem;
    flex-direction: column;
}

.btn-fancy {
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
    .success-body {
        padding: 1.75rem;
    }

    .session-id-value {
        font-size: 22px;
    }

    .next-steps {
        padding: 1.5rem;
    }
}
</style>

<div class="success-wrapper">
    <div class="success-card">
        <div class="success-icon">
            <div class="checkmark">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
                </svg>
            </div>
            <h1 class="success-title">Congratulations!</h1>
            <p class="success-subtitle">Your enrollment is complete</p>
        </div>

        <div class="success-body">
            <div class="session-id-box">
                <div class="session-id-label">Your Session ID</div>
                <div class="session-id-value"><?php echo htmlspecialchars($enrollment['session_id']); ?></div>
            </div>

            <div class="next-steps">
                <h3>What Happens Next?</h3>
                <ol class="steps-list">
                    <li>
                        <div class="step-number">1</div>
                        <div class="step-text">
                            Our team will review your enrollment and documents within 24 hours
                        </div>
                    </li>
                    <li>
                        <div class="step-number">2</div>
                        <div class="step-text">
                            You'll receive an email with your welcome packet and next steps
                        </div>
                    </li>
                    <li>
                        <div class="step-number">3</div>
                        <div class="step-text">
                            We'll begin working on your credit repair immediately
                        </div>
                    </li>
                </ol>
            </div>

            <div class="action-buttons">
                <a href="<?php echo BASE_URL; ?>" class="btn-fancy btn-fancy-primary">
                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                    </svg>
                    Return to Home
                </a>
            </div>
        </div>
    </div>
</div>

<?php
// Clear session
session_destroy();
include __DIR__ . '/../src/footer.php';
?>
