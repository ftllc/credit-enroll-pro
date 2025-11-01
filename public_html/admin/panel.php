<?php
/**
 * Admin Panel - Dashboard
 */

define('SNS_ENROLLMENT', true);
require_once __DIR__ . '/../src/config.php';

// Start session
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
    session_destroy();
    header('Location: login.php');
    exit;
}

// Get dashboard stats
$stats = [];

// Total enrollments
$stmt = $pdo->query("SELECT COUNT(*) FROM enrollment_users");
$stats['total_enrollments'] = $stmt->fetchColumn();

// In progress (all statuses before Enrollment Complete)
$stmt = $pdo->query("SELECT COUNT(*) FROM enrollment_users WHERE package_status IS NULL OR package_status != 'completed'");
$stats['in_progress'] = $stmt->fetchColumn();

// Completed (Enrollment Complete and Ready for Action - when package is completed)
$stmt = $pdo->query("SELECT COUNT(*) FROM enrollment_users WHERE package_status = 'completed'");
$stats['completed'] = $stmt->fetchColumn();

// Abandoned (no activity in 72 hours)
$stmt = $pdo->query("SELECT COUNT(*) FROM enrollment_users WHERE status = 'in_progress' AND last_activity < DATE_SUB(NOW(), INTERVAL 72 HOUR)");
$stats['abandoned'] = $stmt->fetchColumn();

// Get total question count for status determination
$stmt = $pdo->query("SELECT COUNT(*) FROM enrollment_questions WHERE is_active = 1");
$total_questions = $stmt->fetchColumn();

// Pagination and search
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 10;
$offset = ($page - 1) * $per_page;
$search = trim($_GET['search'] ?? '');

// Build WHERE clause for search
$where_clause = '';
$params = [];
if (!empty($search)) {
    $where_clause = "WHERE (eu.last_name LIKE ? OR eu.first_name LIKE ? OR eu.email LIKE ? OR eu.phone LIKE ?)";
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

// Get total count for pagination
$count_sql = "SELECT COUNT(*) FROM enrollment_users eu $where_clause";
$stmt = $pdo->prepare($count_sql);
$stmt->execute($params);
$total_enrollments = $stmt->fetchColumn();
$total_pages = ceil($total_enrollments / $per_page);

// Recent enrollments with enhanced data for detailed status
$sql = "
    SELECT eu.*, p.plan_name, p.plan_type,
           (SELECT COUNT(*) FROM enrollment_question_responses WHERE enrollment_id = eu.id) as quiz_count,
           (SELECT COUNT(*) FROM contracts WHERE enrollment_id = eu.id AND signed = 1) as contracts_count,
           (SELECT COUNT(*) FROM id_docs WHERE enrollment_id = eu.id) as id_docs_count
    FROM enrollment_users eu
    LEFT JOIN plans p ON eu.plan_id = p.id
    $where_clause
    ORDER BY eu.created_at DESC
    LIMIT $per_page OFFSET $offset
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$recent_enrollments = $stmt->fetchAll();

// Function to determine detailed enrollment status
function getDetailedStatus($enrollment, $total_questions) {
    $quiz_count = $enrollment['quiz_count'];
    $contracts_count = $enrollment['contracts_count'];
    $id_docs_count = $enrollment['id_docs_count'];

    if ($enrollment['current_step'] <= 1) {
        return ['status' => 'New', 'class' => 'info', 'icon' => '🆕'];
    }
    if ($quiz_count > 0 && $quiz_count < $total_questions) {
        return ['status' => 'Answers Pending', 'class' => 'warning', 'icon' => '📝'];
    }
    if ($quiz_count == $total_questions && empty($enrollment['plan_id'])) {
        return ['status' => 'Quiz Complete', 'class' => 'info', 'icon' => '✅'];
    }
    if (!empty($enrollment['plan_id']) && $contracts_count == 0) {
        return ['status' => 'Plan Selected', 'class' => 'info', 'icon' => '📋'];
    }
    if ($contracts_count > 0 && $enrollment['package_status'] != 'completed') {
        return ['status' => 'Contracted', 'class' => 'warning', 'icon' => '📄'];
    }
    if ($enrollment['package_status'] == 'completed' && $id_docs_count == 0) {
        return ['status' => 'Enrollment Complete', 'class' => 'success', 'icon' => '🎉'];
    }
    if ($enrollment['package_status'] == 'completed' && $id_docs_count > 0) {
        return ['status' => 'Ready for Action', 'class' => 'success', 'icon' => '🚀'];
    }
    return ['status' => ucfirst($enrollment['status']), 'class' => 'info', 'icon' => '📊'];
}

// Enrollments needing attention (stuck on a step for 5+ minutes)
$stmt = $pdo->query("
    SELECT DISTINCT eu.*, es.step_name, es.time_spent_seconds, es.last_activity
    FROM enrollment_users eu
    INNER JOIN enrollment_steps es ON eu.id = es.enrollment_id
    WHERE eu.status = 'in_progress'
    AND es.completed_at IS NULL
    AND TIMESTAMPDIFF(MINUTE, es.last_activity, NOW()) >= 5
    ORDER BY es.last_activity ASC
    LIMIT 10
");
$stuck_enrollments = $stmt->fetchAll();

$page_title = 'Admin Dashboard';
include __DIR__ . '/../src/header.php';
?>

<div class="admin-container">
    <div class="admin-header">
        <h1>Dashboard</h1>
        <div class="admin-user-info">
            <span>Welcome, <?php echo htmlspecialchars($staff['full_name']); ?></span>
            <a href="preferences.php" class="btn btn-secondary">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="vertical-align: middle; margin-right: 0.25rem;">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
                User Preferences
            </a>
            <a href="logout.php" class="btn btn-secondary">Logout</a>
        </div>
    </div>

    <div class="admin-nav">
        <a href="panel.php" class="active">Dashboard</a>
        <a href="settings.php">Settings</a>
        <a href="staff.php">Staff</a>
        <?php if ($staff['role'] === 'admin'): ?>
            <a href="aff_details.php">Affiliates</a>
        <?php endif; ?>
    </div>

    <!-- Stats Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon" style="background: #28a745;">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                    <polyline points="22 4 12 14.01 9 11.01"></polyline>
                </svg>
            </div>
            <div class="stat-info">
                <div class="stat-value"><?php echo number_format($stats['completed']); ?></div>
                <div class="stat-label">Completed</div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon" style="background: #ffc107;">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2">
                    <circle cx="12" cy="12" r="10"></circle>
                    <polyline points="12 6 12 12 16 14"></polyline>
                </svg>
            </div>
            <div class="stat-info">
                <div class="stat-value"><?php echo number_format($stats['in_progress']); ?></div>
                <div class="stat-label">In Progress</div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon" style="background: #dc3545;">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="15" y1="9" x2="9" y2="15"></line>
                    <line x1="9" y1="9" x2="15" y2="15"></line>
                </svg>
            </div>
            <div class="stat-info">
                <div class="stat-value"><?php echo number_format($stats['abandoned']); ?></div>
                <div class="stat-label">Abandoned</div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon" style="background: #17a2b8;">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                    <circle cx="9" cy="7" r="4"></circle>
                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                    <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                </svg>
            </div>
            <div class="stat-info">
                <div class="stat-value"><?php echo number_format($stats['total_enrollments']); ?></div>
                <div class="stat-label">Total Enrollments</div>
            </div>
        </div>
    </div>

    <?php if (count($stuck_enrollments) > 0): ?>
    <!-- Enrollments Needing Attention -->
    <div class="card" style="border-left: 4px solid #dc3545;">
        <div class="card-header">
            <h2 class="card-title" style="color: #dc3545;">⚠ Enrollments Needing Attention</h2>
        </div>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Session ID</th>
                        <th>Name</th>
                        <th>Current Step</th>
                        <th>Time on Step</th>
                        <th>Last Activity</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stuck_enrollments as $enrollment): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($enrollment['session_id']); ?></strong></td>
                            <td><?php echo htmlspecialchars($enrollment['first_name'] . ' ' . $enrollment['last_name']); ?></td>
                            <td><?php echo htmlspecialchars($enrollment['step_name']); ?></td>
                            <td><?php echo floor($enrollment['time_spent_seconds'] / 60); ?> min</td>
                            <td><?php echo date('M j, g:i A', strtotime($enrollment['last_activity'])); ?></td>
                            <td>
                                <a href="enroll_details.php?id=<?php echo $enrollment['id']; ?>" class="btn btn-primary btn-sm">View</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Recent Enrollments -->
    <div class="card">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
            <h2 class="card-title">Recent Enrollments</h2>
            <form method="GET" action="" style="display: flex; gap: 0.5rem; margin: 0;">
                <input type="text" name="search" placeholder="Search by name, email, or phone..."
                       value="<?php echo htmlspecialchars($search); ?>"
                       style="padding: 0.5rem 1rem; border: 1px solid #ddd; border-radius: 4px; min-width: 300px;">
                <button type="submit" class="btn btn-primary" style="white-space: nowrap;">
                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="vertical-align: middle;">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                    Search
                </button>
                <?php if (!empty($search)): ?>
                <a href="panel.php" class="btn btn-secondary">Clear</a>
                <?php endif; ?>
            </form>
        </div>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Session ID</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Plan</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($recent_enrollments) > 0): ?>
                        <?php foreach ($recent_enrollments as $enrollment): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($enrollment['session_id']); ?></strong></td>
                                <td><?php echo htmlspecialchars($enrollment['first_name'] . ' ' . $enrollment['last_name']); ?></td>
                                <td><?php echo htmlspecialchars($enrollment['email'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($enrollment['phone'] ? format_phone($enrollment['phone']) : 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($enrollment['plan_name'] ?? 'Not selected'); ?></td>
                                <td>
                                    <?php
                                        $status_info = getDetailedStatus($enrollment, $total_questions);
                                    ?>
                                    <span class="badge badge-<?php echo $status_info['class']; ?>">
                                        <?php echo $status_info['icon'] . ' ' . $status_info['status']; ?>
                                    </span>
                                </td>
                                <td><?php echo date('M j, Y', strtotime($enrollment['created_at'])); ?></td>
                                <td>
                                    <a href="enroll_details.php?id=<?php echo $enrollment['id']; ?>" class="btn btn-primary btn-sm">View</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 2rem; color: #666;">
                                <?php if (!empty($search)): ?>
                                    No enrollments found matching "<?php echo htmlspecialchars($search); ?>"
                                <?php else: ?>
                                    No enrollments yet. Share your enrollment link to get started!
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($total_pages > 1): ?>
        <div class="card-footer" style="display: flex; justify-content: space-between; align-items: center; padding: 1rem 1.5rem; border-top: 1px solid #e5e7eb;">
            <div style="color: #6b7280; font-size: 14px;">
                Showing <?php echo number_format($offset + 1); ?>-<?php echo number_format(min($offset + $per_page, $total_enrollments)); ?> of <?php echo number_format($total_enrollments); ?> enrollments
            </div>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?page=<?php echo $page - 1; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" class="pagination-btn">
                        ← Previous
                    </a>
                <?php endif; ?>

                <?php
                $start_page = max(1, $page - 2);
                $end_page = min($total_pages, $page + 2);

                for ($i = $start_page; $i <= $end_page; $i++):
                ?>
                    <a href="?page=<?php echo $i; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>"
                       class="pagination-btn <?php echo $i == $page ? 'active' : ''; ?>">
                        <?php echo $i; ?>
                    </a>
                <?php endfor; ?>

                <?php if ($page < $total_pages): ?>
                    <a href="?page=<?php echo $page + 1; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" class="pagination-btn">
                        Next →
                    </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
    .admin-container {
        max-width: 1400px;
        margin: 0 auto;
    }

    .admin-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: var(--spacing-lg);
    }

    .admin-header h1 {
        color: var(--color-primary);
        margin: 0;
    }

    .admin-user-info {
        display: flex;
        align-items: center;
        gap: var(--spacing-md);
    }

    .admin-nav {
        display: flex;
        gap: var(--spacing-sm);
        margin-bottom: var(--spacing-lg);
        border-bottom: 2px solid var(--color-light-2);
        padding-bottom: 0;
    }

    .admin-nav a {
        padding: var(--spacing-sm) var(--spacing-md);
        text-decoration: none;
        color: #666;
        border-bottom: 3px solid transparent;
        margin-bottom: -2px;
        transition: all var(--transition-speed);
    }

    .admin-nav a:hover {
        color: var(--color-primary);
    }

    .admin-nav a.active {
        color: var(--color-primary);
        border-bottom-color: var(--color-primary);
        font-weight: 600;
    }

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: var(--spacing-lg);
        margin-bottom: var(--spacing-xl);
    }

    .stat-card {
        background: #fff;
        border-radius: var(--border-radius);
        box-shadow: var(--box-shadow);
        padding: var(--spacing-lg);
        display: flex;
        align-items: center;
        gap: var(--spacing-md);
    }

    .stat-icon {
        width: 64px;
        height: 64px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }

    .stat-info {
        flex: 1;
    }

    .stat-value {
        font-size: 32px;
        font-weight: 700;
        color: var(--color-primary);
        line-height: 1;
    }

    .stat-label {
        font-size: var(--font-size-small);
        color: #666;
        margin-top: var(--spacing-xs);
    }

    .table-responsive {
        overflow-x: auto;
    }

    .data-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: var(--spacing-md);
    }

    .data-table th,
    .data-table td {
        padding: var(--spacing-sm) var(--spacing-md);
        text-align: left;
        border-bottom: 1px solid var(--color-light-2);
    }

    .data-table th {
        background: var(--color-light-1);
        font-weight: 600;
        color: #333;
        font-size: var(--font-size-small);
        text-transform: uppercase;
    }

    .data-table tbody tr:hover {
        background: var(--color-light-1);
    }

    .badge {
        display: inline-block;
        padding: 0.25rem 0.75rem;
        border-radius: 12px;
        font-size: var(--font-size-small);
        font-weight: 500;
    }

    .badge-success {
        background: #d4edda;
        color: #155724;
    }

    .badge-warning {
        background: #fff3cd;
        color: #856404;
    }

    .badge-danger {
        background: #f8d7da;
        color: #721c24;
    }

    .badge-info {
        background: #d1ecf1;
        color: #0c5460;
    }

    .btn-sm {
        padding: 0.5rem 1rem;
        font-size: var(--font-size-small);
    }

    @media (max-width: 768px) {
        .admin-header {
            flex-direction: column;
            align-items: flex-start;
            gap: var(--spacing-md);
        }

        .admin-nav {
            overflow-x: auto;
            white-space: nowrap;
        }

        .stats-grid {
            grid-template-columns: 1fr;
        }
    }

    /* Pagination Styles */
    .pagination {
        display: flex;
        gap: 0.5rem;
        align-items: center;
    }

    .pagination-btn {
        padding: 0.5rem 1rem;
        background: white;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        color: #374151;
        text-decoration: none;
        font-size: 14px;
        font-weight: 500;
        transition: all 0.2s;
        cursor: pointer;
    }

    .pagination-btn:hover {
        background: #f9fafb;
        border-color: var(--color-primary);
        color: var(--color-primary);
    }

    .pagination-btn.active {
        background: var(--color-primary);
        border-color: var(--color-primary);
        color: white;
    }

    .pagination-btn.active:hover {
        background: #854d37;
    }
</style>

<?php include __DIR__ . '/../src/footer.php'; ?>
