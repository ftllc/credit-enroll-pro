<?php
/**
 * Enrollment Step 3: Custom Questions
 * Displays admin-configured enrollment questions
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

// Get active questions
$stmt = $pdo->query("SELECT * FROM enrollment_questions WHERE is_active = 1 ORDER BY display_order");
$questions = $stmt->fetchAll();

// If no questions, skip to next step
if (empty($questions)) {
    header('Location: ' . BASE_URL . '/enroll/address.php');
    exit;
}

// Get existing answers
$stmt = $pdo->prepare("SELECT question_id, response_text FROM enrollment_question_responses WHERE enrollment_id = ?");
$stmt->execute([$enrollment['id']]);
$existing_answers = [];
while ($row = $stmt->fetch()) {
    $existing_answers[$row['question_id']] = $row['response_text'];
}

// Determine which question to show (one at a time)
$current_question_index = intval($_GET['q'] ?? 0);
if ($current_question_index < 0) $current_question_index = 0;
if ($current_question_index >= count($questions)) {
    // All questions answered, move to next step
    header('Location: ' . BASE_URL . '/enroll/address.php');
    exit;
}

$current_question = $questions[$current_question_index];
$total_questions = count($questions);

// Handle form submission
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $answer = trim($_POST['question_' . $current_question['id']] ?? '');

    if ($current_question['is_required'] && empty($answer)) {
        $errors[] = 'This question is required.';
    }

    if (empty($errors)) {
        try {
            // Delete existing answer for this question
            $stmt = $pdo->prepare("DELETE FROM enrollment_question_responses WHERE enrollment_id = ? AND question_id = ?");
            $stmt->execute([$enrollment['id'], $current_question['id']]);

            // Insert new answer
            if (!empty($answer)) {
                $stmt = $pdo->prepare("
                    INSERT INTO enrollment_question_responses (enrollment_id, question_id, response_text)
                    VALUES (?, ?, ?)
                ");
                $stmt->execute([$enrollment['id'], $current_question['id'], $answer]);
            }

            // Update progress on last question
            if ($current_question_index === $total_questions - 1) {
                $stmt = $pdo->prepare("
                    UPDATE enrollment_users
                    SET current_step = 3, last_activity = CURRENT_TIMESTAMP
                    WHERE session_id = ?
                ");
                $stmt->execute([$_SESSION['enrollment_session_id']]);

                // Update CRC with survey responses
                if (!empty($enrollment['crc_record_id'])) {
                    // Get all answers for this enrollment
                    $stmt = $pdo->prepare("
                        SELECT eq.question_text, eqr.response_text
                        FROM enrollment_question_responses eqr
                        JOIN enrollment_questions eq ON eqr.question_id = eq.id
                        WHERE eqr.enrollment_id = ?
                        ORDER BY eq.display_order
                    ");
                    $stmt->execute([$enrollment['id']]);
                    $all_answers = $stmt->fetchAll();

                    // Build memo text with survey answers
                    $memo = "--- SURVEY RESPONSES ---";
                    foreach ($all_answers as $qa) {
                        $memo .= "\nQ: " . $qa['question_text'];
                        $memo .= "\nA: " . $qa['response_text'] . "\n";
                    }

                    crc_append_enrollment_memo($enrollment['crc_record_id'], $enrollment['id'], $memo);
                }
            }

            // Move to next question or address page
            $next_index = $current_question_index + 1;
            if ($next_index >= $total_questions) {
                header('Location: ' . BASE_URL . '/enroll/address.php');
            } else {
                header('Location: ' . BASE_URL . '/enroll/questions.php?q=' . $next_index);
            }
            exit;

        } catch (PDOException $e) {
            $errors[] = 'An error occurred. Please try again.';
            if (DEBUG_MODE) $errors[] = $e->getMessage();
        }
    }
}

$page_title = 'A Few Questions';
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

.question-item {
    margin-bottom: 2rem;
    padding: 1.5rem;
    background: #fafbfc;
    border-radius: 14px;
    border: 2px solid #e5e7eb;
}

.question-label {
    font-size: 16px;
    font-weight: 600;
    color: #374151;
    margin-bottom: 1rem;
    display: block;
}

.question-label.required::after {
    content: ' *';
    color: var(--color-danger);
}

.question-input input[type="text"],
.question-input textarea {
    width: 100%;
    padding: 1rem;
    border: 2px solid #e5e7eb;
    border-radius: 10px;
    font-size: 16px;
    font-family: var(--font-family);
    transition: all 0.3s;
    background: white;
}

.question-input input:focus,
.question-input textarea:focus {
    outline: none;
    border-color: var(--color-primary);
    box-shadow: 0 0 0 4px rgba(156, 96, 70, 0.1);
}

.question-input textarea {
    min-height: 120px;
    resize: vertical;
}

.radio-group {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.radio-option {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 1rem;
    background: white;
    border: 2px solid #e5e7eb;
    border-radius: 10px;
    cursor: pointer;
    transition: all 0.3s;
}

.radio-option:hover {
    border-color: var(--color-primary);
    background: #fef7f5;
}

.radio-option input[type="radio"] {
    width: 20px;
    height: 20px;
    cursor: pointer;
}

.radio-option input[type="radio"]:checked + label {
    color: var(--color-primary);
    font-weight: 600;
}

.radio-option label {
    flex: 1;
    cursor: pointer;
    margin: 0;
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

    .question-item {
        padding: 1rem;
    }
}
</style>

<div class="enroll-wrapper">
    <div class="enroll-card">
        <div class="card-hero">
            <h1>Question <?php echo ($current_question_index + 1); ?> of <?php echo $total_questions; ?></h1>
            <p style="margin-top:1rem;opacity:0.9;">
                <div style="background:rgba(255,255,255,0.2);height:6px;border-radius:3px;overflow:hidden;">
                    <div style="background:white;height:100%;width:<?php echo (($current_question_index + 1) / $total_questions) * 100; ?>%;transition:width 0.3s;"></div>
                </div>
            </p>
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

            <form method="POST" action="" id="questionForm">
                <div class="question-item">
                    <label class="question-label <?php echo $current_question['is_required'] ? 'required' : ''; ?>">
                        <?php echo htmlspecialchars($current_question['question_text']); ?>
                    </label>

                    <div class="question-input">
                        <?php if ($current_question['question_type'] === 'yes_no'): ?>
                            <div class="radio-group">
                                <div class="radio-option" data-auto-submit>
                                    <input type="radio" name="question_<?php echo $current_question['id']; ?>"
                                           id="q<?php echo $current_question['id']; ?>_yes" value="Yes"
                                           <?php echo ($existing_answers[$current_question['id']] ?? '') === 'Yes' ? 'checked' : ''; ?>
                                           <?php echo $current_question['is_required'] ? 'required' : ''; ?>>
                                    <label for="q<?php echo $current_question['id']; ?>_yes">Yes</label>
                                </div>
                                <div class="radio-option" data-auto-submit>
                                    <input type="radio" name="question_<?php echo $current_question['id']; ?>"
                                           id="q<?php echo $current_question['id']; ?>_no" value="No"
                                           <?php echo ($existing_answers[$current_question['id']] ?? '') === 'No' ? 'checked' : ''; ?>
                                           <?php echo $current_question['is_required'] ? 'required' : ''; ?>>
                                    <label for="q<?php echo $current_question['id']; ?>_no">No</label>
                                </div>
                            </div>

                        <?php elseif ($current_question['question_type'] === 'multiple_choice'): ?>
                            <?php $options = json_decode($current_question['options'], true) ?? []; ?>
                            <div class="radio-group">
                                <?php foreach ($options as $index => $option): ?>
                                    <div class="radio-option" data-auto-submit>
                                        <input type="radio" name="question_<?php echo $current_question['id']; ?>"
                                               id="q<?php echo $current_question['id']; ?>_<?php echo $index; ?>"
                                               value="<?php echo htmlspecialchars($option); ?>"
                                               <?php echo ($existing_answers[$current_question['id']] ?? '') === $option ? 'checked' : ''; ?>
                                               <?php echo $current_question['is_required'] ? 'required' : ''; ?>>
                                        <label for="q<?php echo $current_question['id']; ?>_<?php echo $index; ?>">
                                            <?php echo htmlspecialchars($option); ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                        <?php elseif ($current_question['question_type'] === 'short_answer'): ?>
                            <input type="text" name="question_<?php echo $current_question['id']; ?>"
                                   value="<?php echo htmlspecialchars($existing_answers[$current_question['id']] ?? ''); ?>"
                                   <?php echo $current_question['is_required'] ? 'required' : ''; ?>>

                        <?php elseif ($current_question['question_type'] === 'long_answer'): ?>
                            <textarea name="question_<?php echo $current_question['id']; ?>"
                                      <?php echo $current_question['is_required'] ? 'required' : ''; ?>><?php echo htmlspecialchars($existing_answers[$current_question['id']] ?? ''); ?></textarea>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-actions-modern">
                    <?php if ($current_question_index > 0): ?>
                        <a href="<?php echo BASE_URL; ?>/enroll/questions.php?q=<?php echo $current_question_index - 1; ?>" class="btn-fancy btn-fancy-secondary">
                            <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                            </svg>
                            Back
                        </a>
                    <?php else: ?>
                        <a href="<?php echo BASE_URL; ?>/enroll/" class="btn-fancy btn-fancy-secondary">
                            <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                            </svg>
                            Back
                        </a>
                    <?php endif; ?>
                    <button type="submit" class="btn-fancy btn-fancy-primary">
                        <?php echo $current_question_index === $total_questions - 1 ? 'Finish' : 'Continue'; ?>
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
// Make entire radio option div clickable with auto-submit
document.addEventListener('DOMContentLoaded', function() {
    const radioOptions = document.querySelectorAll('.radio-option');
    const form = document.getElementById('questionForm');
    let autoSubmitTimeout = null;

    radioOptions.forEach(option => {
        option.addEventListener('click', function(e) {
            // Don't trigger if already clicking the input or label directly
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'LABEL') {
                return;
            }

            // Find the radio input inside this option
            const radio = this.querySelector('input[type="radio"]');
            if (radio) {
                radio.checked = true;

                // Update visual state for all options in this group
                const groupName = radio.name;
                document.querySelectorAll(`input[name="${groupName}"]`).forEach(r => {
                    r.closest('.radio-option').style.borderColor = '#e5e7eb';
                    r.closest('.radio-option').style.background = 'white';
                });

                // Highlight selected option
                this.style.borderColor = 'var(--color-primary)';
                this.style.background = '#fef7f5';

                // Auto-submit for radio/multiple choice questions
                if (this.hasAttribute('data-auto-submit')) {
                    form.submit();
                }
            }
        });
    });

    // Initialize visual state for pre-selected options
    document.querySelectorAll('input[type="radio"]:checked').forEach(radio => {
        const option = radio.closest('.radio-option');
        if (option) {
            option.style.borderColor = 'var(--color-primary)';
            option.style.background = '#fef7f5';
        }
    });

    // Update visual state and auto-submit when radio is changed via direct click
    document.querySelectorAll('input[type="radio"]').forEach(radio => {
        radio.addEventListener('change', function() {
            const groupName = this.name;
            document.querySelectorAll(`input[name="${groupName}"]`).forEach(r => {
                r.closest('.radio-option').style.borderColor = '#e5e7eb';
                r.closest('.radio-option').style.background = 'white';
            });

            const option = this.closest('.radio-option');
            if (option) {
                option.style.borderColor = 'var(--color-primary)';
                option.style.background = '#fef7f5';

                // Auto-submit for radio/multiple choice questions
                if (option.hasAttribute('data-auto-submit')) {
                    form.submit();
                }
            }
        });
    });
});
</script>

<?php include __DIR__ . '/../src/footer.php'; ?>
