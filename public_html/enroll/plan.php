<?php
/**
 * Enrollment Step 4: Plan Selection
 * Choose between Individual or Couple plan
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

// Get active plans
$stmt = $pdo->query("SELECT * FROM plans WHERE is_active = 1 ORDER BY display_order");
$plans = $stmt->fetchAll();

// Handle form submission
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $plan_id = intval($_POST['plan_id'] ?? 0);

    if (empty($plan_id)) {
        $errors[] = 'Please select a plan';
    }

    // Validate plan exists
    $stmt = $pdo->prepare("SELECT * FROM plans WHERE id = ? AND is_active = 1");
    $stmt->execute([$plan_id]);
    $selected_plan = $stmt->fetch();

    if (!$selected_plan) {
        $errors[] = 'Invalid plan selected';
    }

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("
                UPDATE enrollment_users
                SET plan_id = ?, current_step = 4, last_activity = CURRENT_TIMESTAMP
                WHERE session_id = ?
            ");
            $stmt->execute([$plan_id, $_SESSION['enrollment_session_id']]);

            // Update CRC with plan info and memo
            if (!empty($enrollment['crc_record_id'])) {
                $plan_name = $selected_plan['plan_name'];
                $initial_fee = number_format($selected_plan['initial_work_fee'], 2);
                $monthly_fee = number_format($selected_plan['monthly_fee'], 2);

                $memo = "--- PLAN SELECTED ---\nPlan: {$plan_name}\n";
                $memo .= "Initial Work Fee: \${$initial_fee}\n";
                $memo .= "Monthly Fee: \${$monthly_fee}\n\n";
                $memo .= "--- STATUS: Pending Contracting";

                crc_append_enrollment_memo($enrollment['crc_record_id'], $enrollment['id'], $memo);
            }

            // Check if couple plan - go to spouse page, otherwise contracts
            if ($selected_plan['plan_type'] === 'couple') {
                header('Location: ' . BASE_URL . '/enroll/spouse.php');
            } else {
                header('Location: ' . BASE_URL . '/enroll/contracts.php');
            }
            exit;

        } catch (PDOException $e) {
            $errors[] = 'An error occurred. Please try again.';
            if (DEBUG_MODE) $errors[] = $e->getMessage();
        }
    }
}

$page_title = 'Choose Your Plan';
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
    max-width: 900px;
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

.plans-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.plan-card {
    position: relative;
    border: 3px solid #e5e7eb;
    border-radius: 20px;
    padding: 2rem;
    cursor: pointer;
    transition: all 0.3s;
    background: white;
}

.plan-card:hover {
    border-color: var(--color-primary);
    transform: translateY(-4px);
    box-shadow: 0 12px 30px rgba(156, 96, 70, 0.2);
}

.plan-card.selected {
    border-color: var(--color-primary);
    background: linear-gradient(135deg, #fef7f5 0%, #fff 100%);
    box-shadow: 0 8px 24px rgba(156, 96, 70, 0.25);
}

.plan-radio {
    position: absolute;
    top: 1.5rem;
    right: 1.5rem;
    width: 24px;
    height: 24px;
    cursor: pointer;
}

.plan-name {
    font-size: 24px;
    font-weight: 700;
    color: var(--color-primary);
    margin-bottom: 1rem;
}

.plan-price {
    margin-bottom: 1.5rem;
}

.price-initial {
    font-size: 32px;
    font-weight: 700;
    color: #374151;
    display: block;
}

.price-label {
    font-size: 14px;
    color: #6b7280;
}

.price-monthly {
    font-size: 20px;
    font-weight: 600;
    color: #6b7280;
    margin-top: 0.5rem;
}

.plan-description {
    color: #6b7280;
    font-size: 15px;
    line-height: 1.6;
    margin-bottom: 1.5rem;
}

.plan-features {
    list-style: none;
    padding: 0;
    margin: 0;
}

.plan-features li {
    padding: 0.625rem 0;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    color: #374151;
    font-size: 14px;
}

.plan-features svg {
    width: 20px;
    height: 20px;
    color: var(--color-success);
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

.btn-fancy-primary:hover:not(:disabled) {
    transform: translateY(-2px);
    box-shadow: 0 6px 24px rgba(156, 96, 70, 0.45);
}

.btn-fancy-primary:disabled {
    opacity: 0.5;
    cursor: not-allowed;
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

    .plans-grid {
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

    .plan-card {
        padding: 1.5rem;
    }
}
</style>

<div class="enroll-wrapper">
    <div class="enroll-card">
        <div class="card-hero">
            <h1>Choose Your Plan</h1>
            <p>Select the credit repair plan that's right for you</p>
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

            <form method="POST" action="" id="planForm">
                <div class="plans-grid">
                    <?php foreach ($plans as $plan): ?>
                        <div class="plan-card" data-plan-id="<?php echo $plan['id']; ?>">
                            <input type="radio" name="plan_id" value="<?php echo $plan['id']; ?>"
                                   id="plan_<?php echo $plan['id']; ?>" class="plan-radio"
                                   <?php echo ($enrollment['plan_id'] == $plan['id']) ? 'checked' : ''; ?>>

                            <div class="plan-name"><?php echo htmlspecialchars($plan['plan_name']); ?></div>

                            <div class="plan-price">
                                <span class="price-initial">$<?php echo number_format($plan['initial_work_fee'], 2); ?></span>
                                <span class="price-label">Initial Work Fee</span>
                                <div class="price-monthly">
                                    $<?php echo number_format($plan['monthly_fee'], 2); ?>/month
                                </div>
                            </div>

                            <?php if ($plan['description']): ?>
                                <div class="plan-description">
                                    <?php echo htmlspecialchars($plan['description']); ?>
                                </div>
                            <?php endif; ?>

                            <?php
                            $features = json_decode($plan['features'], true);
                            if ($features && is_array($features)):
                            ?>
                                <ul class="plan-features">
                                    <?php foreach ($features as $feature): ?>
                                        <li>
                                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                            </svg>
                                            <?php echo htmlspecialchars($feature); ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="form-actions-modern">
                    <a href="<?php echo BASE_URL; ?>/enroll/address.php" class="btn-fancy btn-fancy-secondary">
                        <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                        </svg>
                        Back
                    </a>
                    <button type="submit" class="btn-fancy btn-fancy-primary" id="continueBtn" disabled>
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
// Handle plan card selection
document.querySelectorAll('.plan-card').forEach(card => {
    card.addEventListener('click', function() {
        // Remove selected class from all cards
        document.querySelectorAll('.plan-card').forEach(c => c.classList.remove('selected'));

        // Add selected class to clicked card
        this.classList.add('selected');

        // Check the radio button
        const radio = this.querySelector('.plan-radio');
        radio.checked = true;

        // Enable continue button
        document.getElementById('continueBtn').disabled = false;
    });
});

// Handle radio button clicks
document.querySelectorAll('.plan-radio').forEach(radio => {
    radio.addEventListener('click', function(e) {
        e.stopPropagation();
    });

    // Check if already selected on load
    if (radio.checked) {
        radio.closest('.plan-card').classList.add('selected');
        document.getElementById('continueBtn').disabled = false;
    }
});
</script>

<?php include __DIR__ . '/../src/footer.php'; ?>
