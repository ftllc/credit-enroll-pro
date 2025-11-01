<?php
/**
 * Staff Management Page
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

if (!$staff || !$staff['is_active'] || $staff['role'] !== 'admin') {
    header('Location: panel.php');
    exit;
}

$success = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_staff'])) {
        // Add new staff member
        $username = sanitize_input($_POST['username'] ?? '');
        $email = sanitize_input($_POST['email'] ?? '');
        $full_name = sanitize_input($_POST['full_name'] ?? '');
        $phone = sanitize_input($_POST['phone'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = sanitize_input($_POST['role'] ?? 'staff');
        $receive_notifications = isset($_POST['receive_notifications']) ? 1 : 0;
        $notify_enrollment_started = isset($_POST['notify_enrollment_started']) ? 1 : 0;
        $notify_contracts_signed = isset($_POST['notify_contracts_signed']) ? 1 : 0;
        $notify_enrollment_complete = isset($_POST['notify_enrollment_complete']) ? 1 : 0;
        $notify_ids_uploaded = isset($_POST['notify_ids_uploaded']) ? 1 : 0;
        $notify_spouse_contracted = isset($_POST['notify_spouse_contracted']) ? 1 : 0;
        $notify_spouse_ids_uploaded = isset($_POST['notify_spouse_ids_uploaded']) ? 1 : 0;

        if (empty($username) || empty($email) || empty($full_name) || empty($password)) {
            $error = 'Please fill in all required fields.';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } else {
            try {
                $password_hash = password_hash($password, PASSWORD_ARGON2ID);
                $stmt = $pdo->prepare("
                    INSERT INTO staff (username, email, password_hash, full_name, phone, role, receive_step_notifications,
                                     notify_enrollment_started, notify_contracts_signed, notify_enrollment_complete, notify_ids_uploaded,
                                     notify_spouse_contracted, notify_spouse_ids_uploaded, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
                ");
                $stmt->execute([$username, $email, $password_hash, $full_name, $phone, $role, $receive_notifications,
                              $notify_enrollment_started, $notify_contracts_signed, $notify_enrollment_complete, $notify_ids_uploaded,
                              $notify_spouse_contracted, $notify_spouse_ids_uploaded]);
                $success = 'Staff member added successfully!';
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $error = 'Username or email already exists.';
                } else {
                    $error = 'Failed to add staff member: ' . $e->getMessage();
                }
            }
        }
    } elseif (isset($_POST['update_staff'])) {
        // Update existing staff member
        $staff_id = intval($_POST['staff_id'] ?? 0);
        $full_name = sanitize_input($_POST['full_name'] ?? '');
        $email = sanitize_input($_POST['email'] ?? '');
        $phone = sanitize_input($_POST['phone'] ?? '');
        $role = sanitize_input($_POST['role'] ?? 'staff');
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $receive_notifications = isset($_POST['receive_notifications']) ? 1 : 0;
        $notify_enrollment_started = isset($_POST['notify_enrollment_started']) ? 1 : 0;
        $notify_contracts_signed = isset($_POST['notify_contracts_signed']) ? 1 : 0;
        $notify_enrollment_complete = isset($_POST['notify_enrollment_complete']) ? 1 : 0;
        $notify_ids_uploaded = isset($_POST['notify_ids_uploaded']) ? 1 : 0;
        $notify_spouse_contracted = isset($_POST['notify_spouse_contracted']) ? 1 : 0;
        $notify_spouse_ids_uploaded = isset($_POST['notify_spouse_ids_uploaded']) ? 1 : 0;

        try {
            $stmt = $pdo->prepare("
                UPDATE staff
                SET full_name = ?, email = ?, phone = ?, role = ?, is_active = ?, receive_step_notifications = ?,
                    notify_enrollment_started = ?, notify_contracts_signed = ?, notify_enrollment_complete = ?, notify_ids_uploaded = ?,
                    notify_spouse_contracted = ?, notify_spouse_ids_uploaded = ?
                WHERE id = ?
            ");
            $stmt->execute([$full_name, $email, $phone, $role, $is_active, $receive_notifications,
                          $notify_enrollment_started, $notify_contracts_signed, $notify_enrollment_complete, $notify_ids_uploaded,
                          $notify_spouse_contracted, $notify_spouse_ids_uploaded, $staff_id]);
            $success = 'Staff member updated successfully!';
        } catch (PDOException $e) {
            $error = 'Failed to update staff member: ' . $e->getMessage();
        }
    } elseif (isset($_POST['reset_password'])) {
        // Reset password
        $staff_id = intval($_POST['staff_id'] ?? 0);
        $new_password = $_POST['new_password'] ?? '';

        if (strlen($new_password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } else {
            try {
                $password_hash = password_hash($new_password, PASSWORD_ARGON2ID);
                $stmt = $pdo->prepare("UPDATE staff SET password_hash = ? WHERE id = ?");
                $stmt->execute([$password_hash, $staff_id]);
                $success = 'Password reset successfully!';
            } catch (PDOException $e) {
                $error = 'Failed to reset password: ' . $e->getMessage();
            }
        }
    }
}

// Fetch all staff
$stmt = $pdo->query("SELECT * FROM staff ORDER BY created_at DESC");
$all_staff = $stmt->fetchAll();

$page_title = 'Staff Management';
include __DIR__ . '/../src/header.php';
?>

<div class="admin-container">
    <div class="admin-header">
        <h1>Staff Management</h1>
        <div class="admin-user-info">
            <span>Welcome, <?php echo htmlspecialchars($staff['full_name']); ?></span>
            <a href="logout.php" class="btn btn-secondary">Logout</a>
        </div>
    </div>

    <div class="admin-nav">
        <a href="panel.php">Dashboard</a>
        <a href="settings.php">Settings</a>
        <a href="staff.php" class="active">Staff</a>
        <a href="aff_details.php">Affiliates</a>
    </div>

    <?php if (!empty($success)): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <!-- Add New Staff -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Add New Staff Member</h2>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="add_staff" value="1">

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: var(--spacing-md);">
                <div class="form-group">
                    <label for="username" class="form-label required">Username</label>
                    <input type="text" id="username" name="username" class="form-control" required>
                </div>

                <div class="form-group">
                    <label for="email" class="form-label required">Email</label>
                    <input type="email" id="email" name="email" class="form-control" required>
                </div>

                <div class="form-group">
                    <label for="full_name" class="form-label required">Full Name</label>
                    <input type="text" id="full_name" name="full_name" class="form-control" required>
                </div>

                <div class="form-group">
                    <label for="phone" class="form-label">Phone Number</label>
                    <input type="tel" id="phone" name="phone" class="form-control">
                </div>

                <div class="form-group">
                    <label for="password" class="form-label required">Password</label>
                    <input type="password" id="password" name="password" class="form-control" minlength="8" required>
                </div>

                <div class="form-group">
                    <label for="role" class="form-label required">Role</label>
                    <select id="role" name="role" class="form-control" required>
                        <option value="staff">Staff</option>
                        <option value="manager">Manager</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">
                    <input type="checkbox" name="receive_notifications">
                    Receive step timeout notifications
                </label>
            </div>

            <div class="form-group">
                <label class="form-label" style="font-weight: 600; margin-bottom: 0.5rem; display: block;">Enrollment Event Notifications</label>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 0.5rem; margin-left: 1rem;">
                    <label class="form-label" style="font-weight: normal;">
                        <input type="checkbox" name="notify_enrollment_started">
                        New Enrollment Started
                    </label>
                    <label class="form-label" style="font-weight: normal;">
                        <input type="checkbox" name="notify_contracts_signed">
                        Contracts Signed
                    </label>
                    <label class="form-label" style="font-weight: normal;">
                        <input type="checkbox" name="notify_enrollment_complete">
                        Enrollment Complete
                    </label>
                    <label class="form-label" style="font-weight: normal;">
                        <input type="checkbox" name="notify_ids_uploaded">
                        IDs Uploaded
                    </label>
                    <label class="form-label" style="font-weight: normal;">
                        <input type="checkbox" name="notify_spouse_contracted">
                        Spouse Contracted
                    </label>
                    <label class="form-label" style="font-weight: normal;">
                        <input type="checkbox" name="notify_spouse_ids_uploaded">
                        Spouse IDs Uploaded
                    </label>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">Add Staff Member</button>
        </form>
    </div>

    <!-- Existing Staff -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Staff Members</h2>
        </div>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Notifications</th>
                        <th>2FA</th>
                        <th>Last Login</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($all_staff as $member): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($member['username']); ?></strong></td>
                            <td><?php echo htmlspecialchars($member['full_name']); ?></td>
                            <td><?php echo htmlspecialchars($member['email']); ?></td>
                            <td><?php echo htmlspecialchars($member['phone'] ? format_phone($member['phone']) : 'N/A'); ?></td>
                            <td>
                                <span class="badge badge-<?php echo $member['role'] === 'admin' ? 'danger' : ($member['role'] === 'manager' ? 'warning' : 'info'); ?>">
                                    <?php echo ucfirst($member['role']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge badge-<?php echo $member['is_active'] ? 'success' : 'danger'; ?>">
                                    <?php echo $member['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </td>
                            <td><?php echo $member['receive_step_notifications'] ? '✓' : '✗'; ?></td>
                            <td>
                                <?php
                                $enabled_2fa = [];
                                if ($member['totp_enabled']) $enabled_2fa[] = 'TOTP';
                                if ($member['sms_2fa_enabled']) $enabled_2fa[] = 'SMS';
                                if ($member['email_2fa_enabled']) $enabled_2fa[] = 'Email';
                                echo count($enabled_2fa) > 0 ? implode(', ', $enabled_2fa) : 'None';
                                ?>
                            </td>
                            <td><?php echo $member['last_login'] ? date('M j, Y g:i A', strtotime($member['last_login'])) : 'Never'; ?></td>
                            <td>
                                <button onclick="editStaff(<?php echo htmlspecialchars(json_encode($member)); ?>)" class="btn btn-primary btn-sm">Edit</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Edit Staff Modal -->
<div id="editModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 9999; padding: var(--spacing-lg);">
    <div style="max-width: 600px; margin: 50px auto; background: white; border-radius: var(--border-radius); padding: var(--spacing-lg); max-height: 90vh; overflow-y: auto;">
        <h2 style="margin-bottom: var(--spacing-md); color: var(--color-primary);">Edit Staff Member</h2>
        <form method="POST" action="" id="editForm">
            <input type="hidden" name="update_staff" value="1">
            <input type="hidden" name="staff_id" id="edit_staff_id">

            <div class="form-group">
                <label for="edit_full_name" class="form-label required">Full Name</label>
                <input type="text" id="edit_full_name" name="full_name" class="form-control" required>
            </div>

            <div class="form-group">
                <label for="edit_email" class="form-label required">Email</label>
                <input type="email" id="edit_email" name="email" class="form-control" required>
            </div>

            <div class="form-group">
                <label for="edit_phone" class="form-label">Phone</label>
                <input type="tel" id="edit_phone" name="phone" class="form-control">
            </div>

            <div class="form-group">
                <label for="edit_role" class="form-label required">Role</label>
                <select id="edit_role" name="role" class="form-control" required>
                    <option value="staff">Staff</option>
                    <option value="manager">Manager</option>
                    <option value="admin">Admin</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">
                    <input type="checkbox" name="is_active" id="edit_is_active">
                    Active
                </label>
            </div>

            <div class="form-group">
                <label class="form-label">
                    <input type="checkbox" name="receive_notifications" id="edit_receive_notifications">
                    Receive step timeout notifications
                </label>
            </div>

            <div class="form-group">
                <label class="form-label" style="font-weight: 600; margin-bottom: 0.5rem; display: block;">Enrollment Event Notifications</label>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; margin-left: 1rem;">
                    <label class="form-label" style="font-weight: normal;">
                        <input type="checkbox" name="notify_enrollment_started" id="edit_notify_enrollment_started">
                        New Enrollment Started
                    </label>
                    <label class="form-label" style="font-weight: normal;">
                        <input type="checkbox" name="notify_contracts_signed" id="edit_notify_contracts_signed">
                        Contracts Signed
                    </label>
                    <label class="form-label" style="font-weight: normal;">
                        <input type="checkbox" name="notify_enrollment_complete" id="edit_notify_enrollment_complete">
                        Enrollment Complete
                    </label>
                    <label class="form-label" style="font-weight: normal;">
                        <input type="checkbox" name="notify_ids_uploaded" id="edit_notify_ids_uploaded">
                        IDs Uploaded
                    </label>
                    <label class="form-label" style="font-weight: normal;">
                        <input type="checkbox" name="notify_spouse_contracted" id="edit_notify_spouse_contracted">
                        Spouse Contracted
                    </label>
                    <label class="form-label" style="font-weight: normal;">
                        <input type="checkbox" name="notify_spouse_ids_uploaded" id="edit_notify_spouse_ids_uploaded">
                        Spouse IDs Uploaded
                    </label>
                </div>
            </div>

            <div style="display: flex; gap: var(--spacing-sm); justify-content: flex-end; margin-top: var(--spacing-lg);">
                <button type="button" onclick="closeEditModal()" class="btn btn-secondary">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>

        <hr style="margin: var(--spacing-lg) 0;">

        <h3 style="margin-bottom: var(--spacing-md);">Reset Password</h3>
        <form method="POST" action="" id="resetPasswordForm">
            <input type="hidden" name="reset_password" value="1">
            <input type="hidden" name="staff_id" id="reset_staff_id">

            <div class="form-group">
                <label for="new_password" class="form-label required">New Password</label>
                <input type="password" id="new_password" name="new_password" class="form-control" minlength="8" required>
            </div>

            <button type="submit" class="btn btn-warning">Reset Password</button>
        </form>
    </div>
</div>

<style>
    .badge-info {
        background: #d1ecf1;
        color: #0c5460;
    }
</style>

<script>
    function editStaff(member) {
        document.getElementById('edit_staff_id').value = member.id;
        document.getElementById('reset_staff_id').value = member.id;
        document.getElementById('edit_full_name').value = member.full_name;
        document.getElementById('edit_email').value = member.email;
        document.getElementById('edit_phone').value = member.phone || '';
        document.getElementById('edit_role').value = member.role;
        document.getElementById('edit_is_active').checked = member.is_active == 1;
        document.getElementById('edit_receive_notifications').checked = member.receive_step_notifications == 1;
        document.getElementById('edit_notify_enrollment_started').checked = member.notify_enrollment_started == 1;
        document.getElementById('edit_notify_contracts_signed').checked = member.notify_contracts_signed == 1;
        document.getElementById('edit_notify_enrollment_complete').checked = member.notify_enrollment_complete == 1;
        document.getElementById('edit_notify_ids_uploaded').checked = member.notify_ids_uploaded == 1;
        document.getElementById('edit_notify_spouse_contracted').checked = member.notify_spouse_contracted == 1;
        document.getElementById('edit_notify_spouse_ids_uploaded').checked = member.notify_spouse_ids_uploaded == 1;
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
</script>

<?php include __DIR__ . '/../src/footer.php'; ?>
