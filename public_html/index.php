<?php
/**
 * Credit Enroll Pro - Enrollment System Landing Page
 */

define('SNS_ENROLLMENT', true);
require_once __DIR__ . '/src/config.php';

// Clean URL from Facebook tracking parameters
if (isset($_SERVER['REQUEST_URI']) && (strpos($_SERVER['REQUEST_URI'], 'fbclid=') !== false || strpos($_SERVER['REQUEST_URI'], 'gclid=') !== false)) {
    $clean_url = clean_url($_SERVER['REQUEST_URI']);
    header("Location: $clean_url", true, 301);
    exit;
}

// Check if enrollments are enabled and get company settings
$stmt = $pdo->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('enrollments_enabled', 'admin_email', 'company_name')");
$stmt->execute();
$settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$enrollments_enabled = $settings['enrollments_enabled'] ?? 'false';
$admin_email = $settings['admin_email'] ?? 'admin@example.com';
$company_name = $settings['company_name'] ?? COMPANY_NAME;

$page_title = 'Credit Repair Enrollment';
$page_description = 'Begin your journey to better credit with ' . $company_name;
$include_recaptcha = true;

include __DIR__ . '/src/header.php';
?>

<div class="landing-page">
    <div class="hero-section">
        <div class="hero-content">
            <h1>Start Your Credit Repair Journey</h1>
            <p class="hero-subtitle">Professional credit repair services to help you achieve your financial goals</p>

            <?php if ($enrollments_enabled === 'true' || $enrollments_enabled === '1'): ?>
                <div class="cta-buttons">
                    <a href="<?php echo BASE_URL; ?>/enroll/" class="btn btn-primary btn-lg">Start New Enrollment</a>
                    <a href="<?php echo BASE_URL; ?>/continue/login.php" class="btn btn-outline btn-lg">Continue Enrollment</a>
                </div>
            <?php else: ?>
                <div class="alert alert-warning" style="max-width: 600px; margin: 0 auto;">
                    <strong>Enrollments Currently Unavailable</strong>
                    <p style="margin: 1rem 0;">We are not accepting new enrollments through our online system at this time.</p>
                    <p style="margin: 1rem 0;">To inquire about enrollment, please contact us at:</p>
                    <p style="margin: 1rem 0;">
                        <a href="mailto:<?php echo htmlspecialchars($admin_email); ?>" style="color: var(--color-primary); font-weight: 600; font-size: 1.1em; text-decoration: none; border-bottom: 2px solid var(--color-primary);">
                            <?php echo htmlspecialchars($admin_email); ?>
                        </a>
                    </p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="features-section">
        <h2>Why Choose <?php echo htmlspecialchars($company_name); ?>?</h2>
        <div class="features-grid">
            <div class="feature-card">
                <div class="feature-icon">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M9 11l3 3L22 4"></path>
                        <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path>
                    </svg>
                </div>
                <h3>Professional Service</h3>
                <p>Expert credit repair specialists dedicated to improving your credit score</p>
            </div>

            <div class="feature-card">
                <div class="feature-icon">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                        <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                    </svg>
                </div>
                <h3>Secure & Confidential</h3>
                <p>Your personal information is encrypted and protected at all times</p>
            </div>

            <div class="feature-card">
                <div class="feature-icon">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M12 2v20M2 12h20"></path>
                    </svg>
                </div>
                <h3>Easy Process</h3>
                <p>Simple step-by-step enrollment that can be completed at your own pace</p>
            </div>

            <div class="feature-card">
                <div class="feature-icon">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                        <polyline points="7 10 12 15 17 10"></polyline>
                        <line x1="12" y1="15" x2="12" y2="3"></line>
                    </svg>
                </div>
                <h3>Save & Continue Later</h3>
                <p>Start your enrollment and complete it whenever it's convenient for you</p>
            </div>
        </div>
    </div>

    <div class="how-it-works">
        <h2>How It Works</h2>
        <div class="steps-container">
            <div class="step">
                <div class="step-number">1</div>
                <h3>Start Enrollment</h3>
                <p>Begin by providing your basic information and selecting your plan</p>
            </div>
            <div class="step-arrow">→</div>
            <div class="step">
                <div class="step-number">2</div>
                <h3>Review & Sign</h3>
                <p>Review your service agreement and sign electronically</p>
            </div>
            <div class="step-arrow">→</div>
            <div class="step">
                <div class="step-number">3</div>
                <h3>Complete Setup</h3>
                <p>Upload required documents and finalize your enrollment</p>
            </div>
            <div class="step-arrow">→</div>
            <div class="step">
                <div class="step-number">4</div>
                <h3>Get Started</h3>
                <p>We'll begin working on your credit repair immediately</p>
            </div>
        </div>
    </div>

    <div class="cta-section">
        <h2>Ready to Get Started?</h2>
        <p>Join hundreds of satisfied clients who have improved their credit scores</p>
        <?php if ($enrollments_enabled === 'true' || $enrollments_enabled === '1'): ?>
            <a href="<?php echo BASE_URL; ?>/enroll/" class="btn btn-primary btn-lg">Begin Enrollment Now</a>
        <?php endif; ?>
    </div>
</div>

<style>
    .landing-page {
        max-width: 1200px;
        margin: 0 auto;
    }

    .hero-section {
        text-align: center;
        padding: var(--spacing-xl) 0;
        background: linear-gradient(135deg, var(--color-light-2) 0%, var(--color-light-1) 100%);
        border-radius: var(--border-radius-lg);
        margin-bottom: var(--spacing-xl);
    }

    .hero-content {
        padding: var(--spacing-lg);
    }

    .hero-section h1 {
        font-size: var(--font-size-h1);
        color: var(--color-primary);
        margin-bottom: var(--spacing-sm);
        font-weight: 700;
    }

    .hero-subtitle {
        font-size: var(--font-size-large);
        color: #666;
        margin-bottom: var(--spacing-lg);
        max-width: 600px;
        margin-left: auto;
        margin-right: auto;
    }

    .cta-buttons {
        display: flex;
        gap: var(--spacing-md);
        justify-content: center;
        flex-wrap: wrap;
    }

    .btn-lg {
        padding: 1rem 2rem;
        font-size: var(--font-size-large);
    }

    .features-section {
        margin-bottom: var(--spacing-xl);
    }

    .features-section h2,
    .how-it-works h2,
    .cta-section h2 {
        text-align: center;
        font-size: var(--font-size-h2);
        color: var(--color-primary);
        margin-bottom: var(--spacing-lg);
    }

    .features-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: var(--spacing-lg);
    }

    .feature-card {
        background: #fff;
        padding: var(--spacing-lg);
        border-radius: var(--border-radius);
        box-shadow: var(--box-shadow);
        text-align: center;
        transition: transform var(--transition-speed);
    }

    .feature-card:hover {
        transform: translateY(-5px);
        box-shadow: var(--box-shadow-lg);
    }

    .feature-icon {
        color: var(--color-primary);
        margin-bottom: var(--spacing-md);
    }

    .feature-card h3 {
        color: var(--color-primary);
        margin-bottom: var(--spacing-sm);
        font-size: var(--font-size-large);
    }

    .feature-card p {
        color: #666;
        line-height: 1.6;
    }

    .how-it-works {
        margin-bottom: var(--spacing-xl);
        background: #fff;
        padding: var(--spacing-xl);
        border-radius: var(--border-radius-lg);
        box-shadow: var(--box-shadow);
    }

    .steps-container {
        display: flex;
        align-items: center;
        justify-content: center;
        flex-wrap: wrap;
        gap: var(--spacing-md);
    }

    .step {
        flex: 1;
        min-width: 200px;
        text-align: center;
    }

    .step-number {
        width: 60px;
        height: 60px;
        background: var(--color-primary);
        color: #fff;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
        font-weight: bold;
        margin: 0 auto var(--spacing-sm);
    }

    .step h3 {
        color: var(--color-primary);
        margin-bottom: var(--spacing-xs);
        font-size: var(--font-size-large);
    }

    .step p {
        color: #666;
        font-size: var(--font-size-small);
    }

    .step-arrow {
        font-size: 32px;
        color: var(--color-secondary);
        font-weight: bold;
    }

    .cta-section {
        text-align: center;
        background: var(--color-primary);
        color: #fff;
        padding: var(--spacing-xl);
        border-radius: var(--border-radius-lg);
        margin-bottom: var(--spacing-xl);
    }

    .cta-section h2 {
        color: #fff;
    }

    .cta-section p {
        font-size: var(--font-size-large);
        margin-bottom: var(--spacing-lg);
    }

    .cta-section .btn-primary {
        background: #fff;
        color: var(--color-primary);
    }

    .cta-section .btn-primary:hover {
        background: var(--color-light-1);
    }

    @media (max-width: 768px) {
        .hero-section h1 {
            font-size: var(--font-size-h2);
        }

        .hero-subtitle {
            font-size: var(--font-size-base);
        }

        .cta-buttons {
            flex-direction: column;
            align-items: stretch;
        }

        .steps-container {
            flex-direction: column;
        }

        .step-arrow {
            transform: rotate(90deg);
            margin: var(--spacing-sm) 0;
        }

        .features-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<?php include __DIR__ . '/src/footer.php'; ?>
