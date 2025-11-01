<?php
/**
 * Enrollment Details Page
 */

define('SNS_ENROLLMENT', true);
require_once __DIR__ . '/../src/config.php';

session_start();

// Check if logged in
if (!isset($_SESSION['staff_id']) || !isset($_SESSION['staff_logged_in'])) {
    header('Location: login.php');
    exit;
}

// Get staff info
$stmt = $pdo->prepare("SELECT * FROM staff WHERE id = ?");
$stmt->execute([$_SESSION['staff_id']]);
$staff = $stmt->fetch();

if (!$staff || !$staff['is_active']) {
    header('Location: panel.php');
    exit;
}

$enrollment_id = intval($_GET['id'] ?? 0);

if (!$enrollment_id) {
    header('Location: panel.php');
    exit;
}

// Get enrollment details
$stmt = $pdo->prepare("
    SELECT eu.*, p.plan_name, p.plan_type, p.initial_work_fee, p.monthly_fee
    FROM enrollment_users eu
    LEFT JOIN plans p ON eu.plan_id = p.id
    WHERE eu.id = ?
");
$stmt->execute([$enrollment_id]);
$enrollment = $stmt->fetch();

if (!$enrollment) {
    header('Location: panel.php');
    exit;
}

// Get enrollment steps
$stmt = $pdo->prepare("
    SELECT * FROM enrollment_steps
    WHERE enrollment_id = ?
    ORDER BY step_number ASC
");
$stmt->execute([$enrollment_id]);
$steps = $stmt->fetchAll();

// Get notes
$stmt = $pdo->prepare("
    SELECT n.*, s.full_name as staff_name
    FROM notes n
    LEFT JOIN staff s ON n.staff_id = s.id
    WHERE n.enrollment_id = ?
    ORDER BY n.created_at DESC
");
$stmt->execute([$enrollment_id]);
$notes = $stmt->fetchAll();

// Get contracts
$stmt = $pdo->prepare("SELECT * FROM contracts WHERE enrollment_id = ? ORDER BY created_at DESC");
$stmt->execute([$enrollment_id]);
$contracts = $stmt->fetchAll();

// Get ID docs
$stmt = $pdo->prepare("SELECT * FROM id_docs WHERE enrollment_id = ? ORDER BY uploaded_at DESC");
$stmt->execute([$enrollment_id]);
$id_docs = $stmt->fetchAll();

// Get quiz answers
$stmt = $pdo->prepare("
    SELECT eqr.*, eq.question_text, eq.question_type, eq.options
    FROM enrollment_question_responses eqr
    JOIN enrollment_questions eq ON eqr.question_id = eq.id
    WHERE eqr.enrollment_id = ?
    ORDER BY eqr.answered_at ASC
");
$stmt->execute([$enrollment_id]);
$quiz_answers = $stmt->fetchAll();

// Get total questions count
$stmt = $pdo->prepare("SELECT COUNT(*) FROM enrollment_questions WHERE is_active = 1");
$stmt->execute();
$total_questions = $stmt->fetchColumn();

// Calculate detailed status
function getDetailedStatus($enrollment, $quiz_count, $total_questions, $contracts, $id_docs_count) {
    // New - just entered first page
    if ($enrollment['current_step'] <= 1) {
        return ['status' => 'New', 'class' => 'info', 'icon' => '🆕'];
    }

    // Answers Pending - answered some but not all questions
    if ($quiz_count > 0 && $quiz_count < $total_questions) {
        return ['status' => 'Answers Pending', 'class' => 'warning', 'icon' => '📝'];
    }

    // Quiz Complete - answered all questions but no plan selected
    if ($quiz_count == $total_questions && empty($enrollment['plan_id'])) {
        return ['status' => 'Quiz Complete', 'class' => 'info', 'icon' => '✅'];
    }

    // Plan Selected - selected plan but haven't signed contracts
    if (!empty($enrollment['plan_id']) && count($contracts) == 0) {
        return ['status' => 'Plan Selected', 'class' => 'info', 'icon' => '📋'];
    }

    // Contracted - signed contracts but package not complete
    if (count($contracts) > 0 && $enrollment['package_status'] != 'completed') {
        return ['status' => 'Contracted', 'class' => 'warning', 'icon' => '📄'];
    }

    // Enrollment Complete - package generated but no ID docs
    if ($enrollment['package_status'] == 'completed' && $id_docs_count == 0) {
        return ['status' => 'Enrollment Complete', 'class' => 'success', 'icon' => '🎉'];
    }

    // Ready for Action - everything complete including ID docs
    if ($enrollment['package_status'] == 'completed' && $id_docs_count > 0) {
        return ['status' => 'Ready for Action', 'class' => 'success', 'icon' => '🚀'];
    }

    // Fallback to original status
    return ['status' => ucfirst($enrollment['status']), 'class' => 'info', 'icon' => '📊'];
}

$detailed_status = getDetailedStatus($enrollment, count($quiz_answers), $total_questions, $contracts, count($id_docs));

// Handle delete enrollment
$success = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_enrollment'])) {
    if ($staff['role'] === 'admin') {
        try {
            $pdo->beginTransaction();

            // Delete related records (foreign keys)
            $stmt = $pdo->prepare("DELETE FROM enrollment_steps WHERE enrollment_id = ?");
            $stmt->execute([$enrollment_id]);

            $stmt = $pdo->prepare("DELETE FROM notes WHERE enrollment_id = ?");
            $stmt->execute([$enrollment_id]);

            $stmt = $pdo->prepare("DELETE FROM contracts WHERE enrollment_id = ?");
            $stmt->execute([$enrollment_id]);

            $stmt = $pdo->prepare("DELETE FROM id_docs WHERE enrollment_id = ?");
            $stmt->execute([$enrollment_id]);

            $stmt = $pdo->prepare("DELETE FROM enrollment_question_responses WHERE enrollment_id = ?");
            $stmt->execute([$enrollment_id]);

            // Delete spouse enrollments if any
            $stmt = $pdo->prepare("DELETE FROM spouse_enrollments WHERE primary_enrollment_id = ?");
            $stmt->execute([$enrollment_id]);

            // Delete the main enrollment record
            $stmt = $pdo->prepare("DELETE FROM enrollment_users WHERE id = ?");
            $stmt->execute([$enrollment_id]);

            $pdo->commit();

            // Redirect to panel
            header('Location: panel.php?deleted=1');
            exit;
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = 'Failed to delete enrollment: ' . $e->getMessage();
        }
    } else {
        $error = 'Only administrators can delete enrollments.';
    }
}

// Handle add note
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_note'])) {
    $note_text = trim($_POST['note_text'] ?? '');
    $note_type = $_POST['note_type'] ?? 'general';

    if (!empty($note_text)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO notes (enrollment_id, staff_id, note_type, note_text) VALUES (?, ?, ?, ?)");
            $stmt->execute([$enrollment_id, $staff['id'], $note_type, $note_text]);
            $success = 'Note added successfully!';

            // Refresh notes
            $stmt = $pdo->prepare("
                SELECT n.*, s.full_name as staff_name
                FROM notes n
                LEFT JOIN staff s ON n.staff_id = s.id
                WHERE n.enrollment_id = ?
                ORDER BY n.created_at DESC
            ");
            $stmt->execute([$enrollment_id]);
            $notes = $stmt->fetchAll();
        } catch (PDOException $e) {
            $error = 'Failed to add note: ' . $e->getMessage();
        }
    }
}

$page_title = 'Enrollment Details';
include __DIR__ . '/../src/header.php';
?>

<div class="admin-container">
    <div class="admin-header">
        <h1>Enrollment Details</h1>
        <div class="admin-user-info">
            <a href="panel.php" class="btn btn-secondary">← Back to Dashboard</a>
            <?php if ($staff['role'] === 'admin'): ?>
                <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this enrollment? This action cannot be undone!');">
                    <input type="hidden" name="delete_enrollment" value="1">
                    <button type="submit" class="btn btn-danger" style="margin-left: 0.5rem;">🗑️ Delete Enrollment</button>
                </form>
            <?php endif; ?>
            <a href="logout.php" class="btn btn-secondary">Logout</a>
        </div>
    </div>

    <?php if (!empty($success)): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: var(--spacing-lg); margin-bottom: var(--spacing-lg);">
        <!-- Main Info -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Client Information</h2>
            </div>
            <div style="padding: var(--spacing-lg);">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--spacing-md);">
                    <div>
                        <strong>Session ID:</strong><br>
                        <span style="font-size: 24px; color: var(--color-primary);"><?php echo htmlspecialchars($enrollment['session_id']); ?></span>
                    </div>
                    <div>
                        <strong>Status:</strong><br>
                        <span class="badge badge-<?php echo $detailed_status['class']; ?>" style="font-size: 16px;">
                            <?php echo $detailed_status['icon']; ?> <?php echo $detailed_status['status']; ?>
                        </span>
                    </div>
                    <div>
                        <strong>Name:</strong><br>
                        <?php echo htmlspecialchars($enrollment['first_name'] . ' ' . $enrollment['last_name']); ?>
                    </div>
                    <div>
                        <strong>Email:</strong><br>
                        <?php echo htmlspecialchars($enrollment['email'] ?? 'Not provided'); ?>
                    </div>
                    <div>
                        <strong>Phone:</strong><br>
                        <?php echo htmlspecialchars($enrollment['phone'] ? format_phone($enrollment['phone']) : 'Not provided'); ?>
                    </div>
                    <div>
                        <strong>Plan:</strong><br>
                        <?php echo htmlspecialchars($enrollment['plan_name'] ?? 'Not selected'); ?>
                    </div>
                </div>

                <?php if ($enrollment['has_spouse']): ?>
                    <hr style="margin: var(--spacing-md) 0;">
                    <h3 style="color: var(--color-primary); margin-bottom: var(--spacing-sm);">Spouse Information</h3>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--spacing-md);">
                        <div>
                            <strong>Name:</strong><br>
                            <?php echo htmlspecialchars($enrollment['spouse_first_name'] . ' ' . $enrollment['spouse_last_name']); ?>
                        </div>
                        <div>
                            <strong>Email:</strong><br>
                            <?php echo htmlspecialchars($enrollment['spouse_email'] ?? 'Not provided'); ?>
                        </div>
                        <div>
                            <strong>Phone:</strong><br>
                            <?php echo htmlspecialchars($enrollment['spouse_phone'] ? format_phone($enrollment['spouse_phone']) : 'Not provided'); ?>
                        </div>
                        <div>
                            <strong>Enrolled:</strong><br>
                            <?php echo $enrollment['spouse_enrolled'] ? 'Yes' : 'No'; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($enrollment['address_line1']): ?>
                    <hr style="margin: var(--spacing-md) 0;">
                    <h3 style="color: var(--color-primary); margin-bottom: var(--spacing-sm);">Address</h3>
                    <div>
                        <?php echo htmlspecialchars($enrollment['address_line1']); ?><br>
                        <?php if ($enrollment['address_line2']): ?>
                            <?php echo htmlspecialchars($enrollment['address_line2']); ?><br>
                        <?php endif; ?>
                        <?php echo htmlspecialchars($enrollment['city'] . ', ' . $enrollment['state'] . ' ' . $enrollment['zip_code']); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Timeline/Tracking -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">📅 Timeline</h2>
            </div>
            <div style="padding: var(--spacing-lg); font-size: 14px;">
                <!-- Timeline Events -->
                <div style="position: relative; padding-left: 24px;">
                    <!-- Vertical line -->
                    <div style="position: absolute; left: 8px; top: 0; bottom: 0; width: 2px; background: #e5e7eb;"></div>

                    <!-- Created -->
                    <div style="position: relative; margin-bottom: 1rem;">
                        <div style="position: absolute; left: -20px; width: 10px; height: 10px; background: var(--color-info); border: 2px solid white; border-radius: 50%; box-shadow: 0 1px 3px rgba(0,0,0,0.1);"></div>
                        <div style="font-size: 11px; color: #666; margin-bottom: 2px;">
                            <?php echo date('M j, g:i A', strtotime($enrollment['created_at'])); ?>
                        </div>
                        <div style="font-weight: 600; font-size: 13px;">🆕 Started Enrollment</div>
                    </div>

                    <!-- First Quiz Answer -->
                    <?php if (count($quiz_answers) > 0): ?>
                        <div style="position: relative; margin-bottom: 1rem;">
                            <div style="position: absolute; left: -20px; width: 10px; height: 10px; background: var(--color-warning); border: 2px solid white; border-radius: 50%; box-shadow: 0 1px 3px rgba(0,0,0,0.1);"></div>
                            <div style="font-size: 11px; color: #666; margin-bottom: 2px;">
                                <?php echo date('M j, g:i A', strtotime($quiz_answers[0]['answered_at'])); ?>
                            </div>
                            <div style="font-weight: 600; font-size: 13px;">📝 Started Quiz</div>
                            <div style="font-size: 12px; color: #666;"><?php echo count($quiz_answers); ?>/<?php echo $total_questions; ?> answered</div>
                        </div>
                    <?php endif; ?>

                    <!-- Quiz Complete (if all answered) -->
                    <?php if (count($quiz_answers) == $total_questions && count($quiz_answers) > 0): ?>
                        <div style="position: relative; margin-bottom: 1rem;">
                            <div style="position: absolute; left: -20px; width: 10px; height: 10px; background: var(--color-success); border: 2px solid white; border-radius: 50%; box-shadow: 0 1px 3px rgba(0,0,0,0.1);"></div>
                            <div style="font-size: 11px; color: #666; margin-bottom: 2px;">
                                <?php echo date('M j, g:i A', strtotime($quiz_answers[count($quiz_answers)-1]['answered_at'])); ?>
                            </div>
                            <div style="font-weight: 600; font-size: 13px;">✅ Completed Quiz</div>
                        </div>
                    <?php endif; ?>

                    <!-- Plan Selected -->
                    <?php if (!empty($enrollment['plan_id'])): ?>
                        <div style="position: relative; margin-bottom: 1rem;">
                            <div style="position: absolute; left: -20px; width: 10px; height: 10px; background: var(--color-info); border: 2px solid white; border-radius: 50%; box-shadow: 0 1px 3px rgba(0,0,0,0.1);"></div>
                            <div style="font-size: 11px; color: #666; margin-bottom: 2px;">—</div>
                            <div style="font-weight: 600; font-size: 13px;">📋 Selected Plan</div>
                            <div style="font-size: 12px; color: #666;"><?php echo htmlspecialchars($enrollment['plan_name']); ?></div>
                        </div>
                    <?php endif; ?>

                    <!-- Contracts Signed -->
                    <?php foreach ($contracts as $contract): ?>
                        <?php if ($contract['signed']): ?>
                            <div style="position: relative; margin-bottom: 1rem;">
                                <div style="position: absolute; left: -20px; width: 10px; height: 10px; background: var(--color-success); border: 2px solid white; border-radius: 50%; box-shadow: 0 1px 3px rgba(0,0,0,0.1);"></div>
                                <div style="font-size: 11px; color: #666; margin-bottom: 2px;">
                                    <?php echo date('M j, g:i A', strtotime($contract['signed_at'])); ?>
                                </div>
                                <div style="font-weight: 600; font-size: 13px;">✍️ Signed <?php echo ucfirst(str_replace('_', ' ', $contract['contract_type'])); ?></div>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>

                    <!-- Package Generated -->
                    <?php if ($enrollment['package_status'] == 'completed' && $enrollment['package_completed_at']): ?>
                        <div style="position: relative; margin-bottom: 1rem;">
                            <div style="position: absolute; left: -20px; width: 10px; height: 10px; background: var(--color-success); border: 2px solid white; border-radius: 50%; box-shadow: 0 1px 3px rgba(0,0,0,0.1);"></div>
                            <div style="font-size: 11px; color: #666; margin-bottom: 2px;">
                                <?php echo date('M j, g:i A', strtotime($enrollment['package_completed_at'])); ?>
                            </div>
                            <div style="font-weight: 600; font-size: 13px;">🎉 Package Generated</div>
                        </div>
                    <?php endif; ?>

                    <!-- ID Documents Uploaded -->
                    <?php foreach ($id_docs as $doc): ?>
                        <div style="position: relative; margin-bottom: 1rem;">
                            <div style="position: absolute; left: -20px; width: 10px; height: 10px; background: var(--color-success); border: 2px solid white; border-radius: 50%; box-shadow: 0 1px 3px rgba(0,0,0,0.1);"></div>
                            <div style="font-size: 11px; color: #666; margin-bottom: 2px;">
                                <?php echo date('M j, g:i A', strtotime($doc['uploaded_at'])); ?>
                            </div>
                            <div style="font-weight: 600; font-size: 13px;">📎 Uploaded <?php echo ucfirst(str_replace('_', ' ', $doc['document_type'])); ?></div>
                        </div>
                    <?php endforeach; ?>

                    <!-- Current Status -->
                    <div style="position: relative;">
                        <div style="position: absolute; left: -20px; width: 10px; height: 10px; background: var(--color-primary); border: 2px solid white; border-radius: 50%; box-shadow: 0 1px 3px rgba(0,0,0,0.1);"></div>
                        <div style="font-size: 11px; color: #666; margin-bottom: 2px;">Now</div>
                        <div style="font-weight: 600; font-size: 13px;"><?php echo $detailed_status['icon']; ?> <?php echo $detailed_status['status']; ?></div>
                    </div>
                </div>

                <hr style="margin: 1rem 0;">

                <div style="font-size: 13px;">
                    <div style="margin-bottom: 0.75rem;">
                        <strong>IP:</strong> <?php echo htmlspecialchars($enrollment['ip_address']); ?>
                    </div>
                    <div style="margin-bottom: 0.75rem;">
                        <strong>Device:</strong> <?php echo htmlspecialchars($enrollment['device_type'] ?? 'Unknown'); ?>
                    </div>
                    <?php if ($enrollment['referring_domain']): ?>
                        <div style="margin-bottom: 0.75rem;">
                            <strong>Referrer:</strong> <?php echo htmlspecialchars($enrollment['referring_domain']); ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($enrollment['affiliate_code']): ?>
                        <div>
                            <strong>Affiliate:</strong> <code><?php echo htmlspecialchars($enrollment['affiliate_code']); ?></code>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Enrollment Steps -->
    <?php if (count($steps) > 0): ?>
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Enrollment Progress</h2>
            </div>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Step</th>
                            <th>Step Name</th>
                            <th>Started</th>
                            <th>Completed</th>
                            <th>Time Spent</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($steps as $step): ?>
                            <tr>
                                <td><?php echo $step['step_number']; ?></td>
                                <td><?php echo htmlspecialchars($step['step_name']); ?></td>
                                <td><?php echo date('M j, g:i A', strtotime($step['started_at'])); ?></td>
                                <td><?php echo $step['completed_at'] ? date('M j, g:i A', strtotime($step['completed_at'])) : 'In progress'; ?></td>
                                <td><?php echo floor($step['time_spent_seconds'] / 60); ?> min</td>
                                <td>
                                    <?php if ($step['completed_at']): ?>
                                        <span class="badge badge-success">✓ Complete</span>
                                    <?php else: ?>
                                        <span class="badge badge-warning">In Progress</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <!-- Quiz Answers -->
    <?php if (count($quiz_answers) > 0): ?>
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Quiz Answers (<?php echo count($quiz_answers); ?> of <?php echo $total_questions; ?>)</h2>
            </div>
            <div style="padding: var(--spacing-lg);">
                <?php foreach ($quiz_answers as $answer): ?>
                    <div style="padding: var(--spacing-md); background: var(--color-light-1); border-radius: var(--border-radius); margin-bottom: var(--spacing-md); border-left: 4px solid var(--color-primary);">
                        <div style="font-weight: 600; color: var(--color-primary); margin-bottom: var(--spacing-xs);">
                            <?php echo htmlspecialchars($answer['question_text']); ?>
                        </div>
                        <div style="font-size: 18px; font-weight: 500; margin-bottom: var(--spacing-xs);">
                            <?php echo htmlspecialchars($answer['response_text']); ?>
                        </div>
                        <div style="font-size: var(--font-size-small); color: #666;">
                            Answered: <?php echo date('M j, Y g:i A', strtotime($answer['answered_at'])); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Contracts -->
    <?php if (count($contracts) > 0): ?>
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Contracts & Agreements</h2>
            </div>
            <div style="padding: var(--spacing-lg);">
                <?php foreach ($contracts as $contract): ?>
                    <div style="padding: var(--spacing-lg); background: <?php echo $contract['signed'] ? '#f0fdf4' : '#fef3c7'; ?>; border-radius: var(--border-radius); margin-bottom: var(--spacing-md); border: 2px solid <?php echo $contract['signed'] ? 'var(--color-success)' : 'var(--color-warning)'; ?>;">
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--spacing-md); margin-bottom: var(--spacing-md);">
                            <div>
                                <strong style="color: var(--color-primary);"><?php echo ucfirst(str_replace('_', ' ', $contract['contract_type'])); ?></strong>
                                <div style="margin-top: var(--spacing-xs);">
                                    <?php if ($contract['signed']): ?>
                                        <span class="badge badge-success">✓ Signed</span>
                                    <?php else: ?>
                                        <span class="badge badge-warning">Pending</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div>
                                <div><strong>Signed By:</strong> <?php echo htmlspecialchars($contract['signed_by'] ?? 'N/A'); ?></div>
                                <div><strong>Date:</strong> <?php echo $contract['signed_at'] ? date('M j, Y g:i A', strtotime($contract['signed_at'])) : 'N/A'; ?></div>
                                <div><strong>IP:</strong> <?php echo htmlspecialchars($contract['ip_address'] ?? 'N/A'); ?></div>
                            </div>
                        </div>
                        <?php if ($contract['signed'] && $contract['signature_data']): ?>
                            <div style="background: white; padding: var(--spacing-sm); border-radius: var(--border-radius); border: 1px solid #e5e7eb;">
                                <strong style="font-size: var(--font-size-small); color: #666;">Digital Signature:</strong><br>
                                <img src="<?php echo htmlspecialchars($contract['signature_data']); ?>" alt="Signature" style="max-width: 300px; height: auto; margin-top: var(--spacing-xs); border: 1px solid #e5e7eb; border-radius: 4px; background: white;">
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Contract Package -->
    <?php if ($enrollment['package_status'] == 'completed' && $enrollment['complete_package_pdf']): ?>
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">📦 Complete Enrollment Package</h2>
            </div>
            <div style="padding: var(--spacing-lg);">
                <div style="background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%); padding: var(--spacing-lg); border-radius: var(--border-radius); border: 2px solid var(--color-success);">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--spacing-md); margin-bottom: var(--spacing-md);">
                        <div>
                            <strong style="color: #155724;">XactoSign Package ID:</strong><br>
                            <code style="background: white; padding: 4px 8px; border-radius: 4px; font-size: 14px;"><?php echo htmlspecialchars($enrollment['xactosign_package_id']); ?></code>
                        </div>
                        <div>
                            <strong style="color: #155724;">Generated:</strong><br>
                            <?php echo date('M j, Y g:i A', strtotime($enrollment['package_completed_at'])); ?>
                        </div>
                        <div>
                            <strong style="color: #155724;">File Size:</strong><br>
                            <?php echo number_format($enrollment['package_file_size'] / 1024, 2); ?> KB
                        </div>
                        <div>
                            <strong style="color: #155724;">Total Pages:</strong><br>
                            <?php echo $enrollment['package_total_pages']; ?> pages
                        </div>
                    </div>
                    <form method="GET" action="download_package.php" style="margin-top: var(--spacing-md);">
                        <input type="hidden" name="id" value="<?php echo $enrollment_id; ?>">
                        <button type="submit" class="btn btn-primary" style="display: flex; align-items: center; gap: 0.5rem;">
                            <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                            Download Complete Package PDF
                        </button>
                    </form>
                </div>
            </div>
        </div>
    <?php elseif ($enrollment['package_status'] == 'processing'): ?>
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">📦 Contract Package</h2>
            </div>
            <div style="padding: var(--spacing-lg);">
                <div class="alert alert-warning">
                    <strong>Processing...</strong> The enrollment package is currently being generated.
                </div>
            </div>
        </div>
    <?php elseif ($enrollment['package_status'] == 'failed'): ?>
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">📦 Contract Package</h2>
            </div>
            <div style="padding: var(--spacing-lg);">
                <div class="alert alert-danger">
                    <strong>Error:</strong> <?php echo htmlspecialchars($enrollment['package_error'] ?? 'Package generation failed'); ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- ID Documents -->
    <?php if (count($id_docs) > 0): ?>
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">ID Documents</h2>
            </div>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Document Type</th>
                            <th>File Name</th>
                            <th>Size</th>
                            <th>Uploaded</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($id_docs as $doc): ?>
                            <tr>
                                <td><?php echo ucfirst(str_replace('_', ' ', $doc['document_type'])); ?></td>
                                <td><?php echo htmlspecialchars($doc['document_name']); ?></td>
                                <td><?php echo number_format($doc['file_size'] / 1024, 2); ?> KB</td>
                                <td><?php echo date('M j, Y g:i A', strtotime($doc['uploaded_at'])); ?></td>
                                <td>
                                    <button class="btn btn-primary btn-sm" disabled>Download</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <!-- Notes -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Notes</h2>
        </div>
        <div style="padding: var(--spacing-lg);">
            <form method="POST" action="">
                <input type="hidden" name="add_note" value="1">
                <div class="form-group">
                    <label for="note_type" class="form-label">Note Type</label>
                    <select id="note_type" name="note_type" class="form-control">
                        <option value="general">General</option>
                        <option value="admin">Admin</option>
                        <option value="system">System</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="note_text" class="form-label required">Note</label>
                    <textarea id="note_text" name="note_text" class="form-control" rows="3" required></textarea>
                </div>
                <button type="submit" class="btn btn-primary">Add Note</button>
            </form>

            <?php if (count($notes) > 0): ?>
                <hr style="margin: var(--spacing-lg) 0;">
                <div style="max-height: 400px; overflow-y: auto;">
                    <?php foreach ($notes as $note): ?>
                        <div style="padding: var(--spacing-md); background: var(--color-light-1); border-radius: var(--border-radius); margin-bottom: var(--spacing-sm);">
                            <div style="display: flex; justify-content: space-between; margin-bottom: var(--spacing-xs);">
                                <strong><?php echo htmlspecialchars($note['staff_name'] ?? 'System'); ?></strong>
                                <span style="font-size: var(--font-size-small); color: #666;">
                                    <?php echo date('M j, Y g:i A', strtotime($note['created_at'])); ?>
                                </span>
                            </div>
                            <div style="font-size: var(--font-size-small); color: #666; margin-bottom: var(--spacing-xs);">
                                Type: <?php echo ucfirst($note['note_type']); ?>
                            </div>
                            <div><?php echo nl2br(htmlspecialchars($note['note_text'])); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p style="margin-top: var(--spacing-md); color: #666; text-align: center;">No notes yet.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../src/footer.php'; ?>
