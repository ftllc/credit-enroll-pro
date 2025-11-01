<?php
/**
 * Affiliate Management Page
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

$success = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_affiliate'])) {
        $code = strtoupper(sanitize_input($_POST['affiliate_code'] ?? ''));
        $name = sanitize_input($_POST['affiliate_name'] ?? '');
        $email = sanitize_input($_POST['contact_email'] ?? '');
        $phone = sanitize_input($_POST['contact_phone'] ?? '');
        $notes = sanitize_input($_POST['notes'] ?? '');

        if (empty($code) || empty($name)) {
            $error = 'Affiliate code and name are required.';
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO affiliates (affiliate_code, affiliate_name, contact_email, contact_phone, notes)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([$code, $name, $email, $phone, $notes]);
                $success = 'Affiliate added successfully!';
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $error = 'Affiliate code already exists.';
                } else {
                    $error = 'Failed to add affiliate: ' . $e->getMessage();
                }
            }
        }
    } elseif (isset($_POST['update_affiliate'])) {
        $id = intval($_POST['affiliate_id'] ?? 0);
        $name = sanitize_input($_POST['affiliate_name'] ?? '');
        $email = sanitize_input($_POST['contact_email'] ?? '');
        $phone = sanitize_input($_POST['contact_phone'] ?? '');
        $notes = sanitize_input($_POST['notes'] ?? '');
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        try {
            $stmt = $pdo->prepare("
                UPDATE affiliates
                SET affiliate_name = ?, contact_email = ?, contact_phone = ?, notes = ?, is_active = ?
                WHERE id = ?
            ");
            $stmt->execute([$name, $email, $phone, $notes, $is_active, $id]);
            $success = 'Affiliate updated successfully!';
        } catch (PDOException $e) {
            $error = 'Failed to update affiliate: ' . $e->getMessage();
        }
    }
}

// Fetch all affiliates with referral counts
$stmt = $pdo->query("
    SELECT a.*, COUNT(eu.id) as actual_referrals
    FROM affiliates a
    LEFT JOIN enrollment_users eu ON a.affiliate_code = eu.affiliate_code
    GROUP BY a.id
    ORDER BY a.created_at DESC
");
$affiliates = $stmt->fetchAll();

$page_title = 'Affiliate Management';
include __DIR__ . '/../src/header.php';
?>

<div class="admin-container">
    <div class="admin-header">
        <h1>Affiliate Management</h1>
        <div class="admin-user-info">
            <span>Welcome, <?php echo htmlspecialchars($staff['full_name']); ?></span>
            <a href="logout.php" class="btn btn-secondary">Logout</a>
        </div>
    </div>

    <div class="admin-nav">
        <a href="panel.php">Dashboard</a>
        <a href="settings.php">Settings</a>
        <a href="staff.php">Staff</a>
        <a href="aff_details.php" class="active">Affiliates</a>
    </div>

    <?php if (!empty($success)): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <!-- Add New Affiliate -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Add New Affiliate</h2>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="add_affiliate" value="1">

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: var(--spacing-md);">
                <div class="form-group">
                    <label for="affiliate_code" class="form-label required">Affiliate Code</label>
                    <input type="text" id="affiliate_code" name="affiliate_code" class="form-control"
                           style="text-transform: uppercase;" required>
                    <div class="form-help">Unique code for tracking referrals</div>
                </div>

                <div class="form-group">
                    <label for="affiliate_name" class="form-label required">Affiliate Name</label>
                    <input type="text" id="affiliate_name" name="affiliate_name" class="form-control" required>
                </div>

                <div class="form-group">
                    <label for="contact_email" class="form-label">Contact Email</label>
                    <input type="email" id="contact_email" name="contact_email" class="form-control">
                </div>

                <div class="form-group">
                    <label for="contact_phone" class="form-label">Contact Phone</label>
                    <input type="tel" id="contact_phone" name="contact_phone" class="form-control">
                </div>
            </div>

            <div class="form-group">
                <label for="notes" class="form-label">Notes</label>
                <textarea id="notes" name="notes" class="form-control" rows="2"></textarea>
            </div>

            <button type="submit" class="btn btn-primary">Add Affiliate</button>
        </form>
    </div>

    <!-- Existing Affiliates -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Affiliates</h2>
        </div>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Contact Email</th>
                        <th>Contact Phone</th>
                        <th>Referrals</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($affiliates) > 0): ?>
                        <?php foreach ($affiliates as $affiliate): ?>
                            <tr>
                                <td><strong><code><?php echo htmlspecialchars($affiliate['affiliate_code']); ?></code></strong></td>
                                <td><?php echo htmlspecialchars($affiliate['affiliate_name']); ?></td>
                                <td><?php echo htmlspecialchars($affiliate['contact_email'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($affiliate['contact_phone'] ? format_phone($affiliate['contact_phone']) : 'N/A'); ?></td>
                                <td><?php echo number_format($affiliate['actual_referrals']); ?></td>
                                <td>
                                    <span class="badge badge-<?php echo $affiliate['is_active'] ? 'success' : 'danger'; ?>">
                                        <?php echo $affiliate['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </td>
                                <td><?php echo date('M j, Y', strtotime($affiliate['created_at'])); ?></td>
                                <td>
                                    <button onclick="editAffiliate(<?php echo htmlspecialchars(json_encode($affiliate)); ?>)"
                                            class="btn btn-primary btn-sm">Edit</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 2rem; color: #666;">
                                No affiliates yet. Add your first affiliate above.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Edit Affiliate Modal -->
<div id="editModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 9999; padding: var(--spacing-lg);">
    <div style="max-width: 600px; margin: 50px auto; background: white; border-radius: var(--border-radius); padding: var(--spacing-lg);">
        <h2 style="margin-bottom: var(--spacing-md); color: var(--color-primary);">Edit Affiliate</h2>
        <form method="POST" action="">
            <input type="hidden" name="update_affiliate" value="1">
            <input type="hidden" name="affiliate_id" id="edit_affiliate_id">

            <div class="form-group">
                <label class="form-label">Affiliate Code</label>
                <input type="text" id="edit_affiliate_code" class="form-control" disabled>
                <div class="form-help">Code cannot be changed</div>
            </div>

            <div class="form-group">
                <label for="edit_affiliate_name" class="form-label required">Affiliate Name</label>
                <input type="text" id="edit_affiliate_name" name="affiliate_name" class="form-control" required>
            </div>

            <div class="form-group">
                <label for="edit_contact_email" class="form-label">Contact Email</label>
                <input type="email" id="edit_contact_email" name="contact_email" class="form-control">
            </div>

            <div class="form-group">
                <label for="edit_contact_phone" class="form-label">Contact Phone</label>
                <input type="tel" id="edit_contact_phone" name="contact_phone" class="form-control">
            </div>

            <div class="form-group">
                <label for="edit_notes" class="form-label">Notes</label>
                <textarea id="edit_notes" name="notes" class="form-control" rows="3"></textarea>
            </div>

            <div class="form-group">
                <label class="form-label">
                    <input type="checkbox" name="is_active" id="edit_is_active">
                    Active
                </label>
            </div>

            <div style="display: flex; gap: var(--spacing-sm); justify-content: flex-end; margin-top: var(--spacing-lg);">
                <button type="button" onclick="closeEditModal()" class="btn btn-secondary">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
    function editAffiliate(affiliate) {
        document.getElementById('edit_affiliate_id').value = affiliate.id;
        document.getElementById('edit_affiliate_code').value = affiliate.affiliate_code;
        document.getElementById('edit_affiliate_name').value = affiliate.affiliate_name;
        document.getElementById('edit_contact_email').value = affiliate.contact_email || '';
        document.getElementById('edit_contact_phone').value = affiliate.contact_phone || '';
        document.getElementById('edit_notes').value = affiliate.notes || '';
        document.getElementById('edit_is_active').checked = affiliate.is_active == 1;
        document.getElementById('editModal').style.display = 'block';
    }

    function closeEditModal() {
        document.getElementById('editModal').style.display = 'none';
    }

    // Close modal when clicking outside
    document.getElementById('editModal')?.addEventListener('click', function(e) {
        if (e.target === this) {
            closeEditModal();
        }
    });

    // Auto-uppercase affiliate code
    document.getElementById('affiliate_code')?.addEventListener('input', function(e) {
        this.value = this.value.toUpperCase();
    });
</script>

<?php include __DIR__ . '/../src/footer.php'; ?>
