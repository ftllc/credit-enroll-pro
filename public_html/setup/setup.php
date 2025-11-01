<?php
/**
 * Credit Enroll Pro - Initial Setup Script
 *
 * Run this script once to:
 * - Create database tables
 * - Create initial admin user
 * - Generate encryption key
 * - Initialize settings
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if already setup
if (file_exists(__DIR__ . '/.setup_complete')) {
    die('Setup has already been completed. Delete /setup/.setup_complete to run setup again.');
}

$step = $_GET['step'] ?? 1;
$errors = [];
$success = [];

// Database connection details - Get from POST form or environment
$db_host = $_POST['db_host'] ?? getenv('DB_HOST') ?: '';
$db_name = $_POST['db_name'] ?? getenv('DB_NAME') ?: '';
$db_user = $_POST['db_user'] ?? getenv('DB_USER') ?: '';
$db_pass = $_POST['db_pass'] ?? getenv('DB_PASS') ?: '';

// Step 0: Get database credentials (if not provided yet)
if ($step == 1 && (empty($db_host) || empty($db_name) || empty($db_user) || empty($db_pass))) {
    // Show form to collect database credentials
    $step = 0;
}

// Step 1: Test database connection
if ($step >= 1) {
    try {
        $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        $success[] = "✓ Database connection successful";

        // Save credentials to config.php if connection successful
        if ($step == 1) {
            $config_file = __DIR__ . '/../src/config.php';
            if (file_exists($config_file)) {
                $config_content = file_get_contents($config_file);

                // Replace database credentials
                $config_content = preg_replace(
                    "/define\('DB_HOST',\s*'[^']*'\);/",
                    "define('DB_HOST', '" . addslashes($db_host) . "');",
                    $config_content
                );
                $config_content = preg_replace(
                    "/define\('DB_NAME',\s*'[^']*'\);/",
                    "define('DB_NAME', '" . addslashes($db_name) . "');",
                    $config_content
                );
                $config_content = preg_replace(
                    "/define\('DB_USER',\s*'[^']*'\);/",
                    "define('DB_USER', '" . addslashes($db_user) . "');",
                    $config_content
                );
                $config_content = preg_replace(
                    "/define\('DB_PASS',\s*'[^']*'\);/",
                    "define('DB_PASS', '" . addslashes($db_pass) . "');",
                    $config_content
                );

                file_put_contents($config_file, $config_content);
                $success[] = "✓ Database credentials saved to config.php";
            }
        }
    } catch (PDOException $e) {
        $errors[] = "✗ Database connection failed: " . $e->getMessage();
        $step = 0; // Go back to credentials form
    }
}

// Step 2: Create tables
if ($step >= 2 && empty($errors)) {
    try {
        $sql = file_get_contents(__DIR__ . '/database_schema.sql');

        // Split SQL into individual statements
        $statements = array_filter(array_map('trim', explode(';', $sql)));

        foreach ($statements as $statement) {
            if (!empty($statement)) {
                $pdo->exec($statement);
            }
        }

        $success[] = "✓ Database tables created successfully";
    } catch (PDOException $e) {
        $errors[] = "✗ Failed to create tables: " . $e->getMessage();
        $step = 2;
    }
}

// Step 3: Generate encryption key
if ($step >= 3 && empty($errors)) {
    $config_file = __DIR__ . '/../src/config.php';

    if (file_exists($config_file)) {
        $config_content = file_get_contents($config_file);

        // Check if encryption key needs to be generated
        if (strpos($config_content, "base64_encode(random_bytes(32))") !== false) {
            $encryption_key = base64_encode(random_bytes(32));
            $config_content = str_replace(
                "define('ENCRYPTION_KEY', base64_encode(random_bytes(32)));",
                "define('ENCRYPTION_KEY', '$encryption_key');",
                $config_content
            );

            if (file_put_contents($config_file, $config_content)) {
                $success[] = "✓ Encryption key generated and saved";
            } else {
                $errors[] = "✗ Failed to save encryption key to config.php";
            }
        } else {
            $success[] = "✓ Encryption key already configured";
        }
    } else {
        $errors[] = "✗ config.php not found";
    }
}

// Step 4: Create admin user
if ($step >= 4 && empty($errors)) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_admin'])) {
        $admin_username = trim($_POST['admin_username'] ?? '');
        $admin_email = trim($_POST['admin_email'] ?? '');
        $admin_password = $_POST['admin_password'] ?? '';
        $admin_confirm = $_POST['admin_confirm'] ?? '';
        $admin_name = trim($_POST['admin_name'] ?? '');

        // Validate inputs
        if (empty($admin_username)) {
            $errors[] = "Username is required";
        }
        if (empty($admin_email) || !filter_var($admin_email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Valid email is required";
        }
        if (empty($admin_password) || strlen($admin_password) < 8) {
            $errors[] = "Password must be at least 8 characters";
        }
        if ($admin_password !== $admin_confirm) {
            $errors[] = "Passwords do not match";
        }
        if (empty($admin_name)) {
            $errors[] = "Full name is required";
        }

        if (empty($errors)) {
            try {
                // Check if admin already exists
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM staff WHERE role = 'admin'");
                $stmt->execute();
                $admin_count = $stmt->fetchColumn();

                if ($admin_count > 0) {
                    $errors[] = "An admin user already exists";
                } else {
                    // Create admin user
                    $password_hash = password_hash($admin_password, PASSWORD_ARGON2ID);

                    $stmt = $pdo->prepare("
                        INSERT INTO staff (username, email, password_hash, full_name, role, is_active)
                        VALUES (?, ?, ?, ?, 'admin', 1)
                    ");
                    $stmt->execute([$admin_username, $admin_email, $password_hash, $admin_name]);

                    $success[] = "✓ Admin user created successfully";
                    $step = 5; // Move to completion step
                }
            } catch (PDOException $e) {
                $errors[] = "✗ Failed to create admin user: " . $e->getMessage();
            }
        }
    }
}

// Step 5: Complete setup
if ($step >= 5 && empty($errors)) {
    // Create .setup_complete file
    file_put_contents(__DIR__ . '/.setup_complete', date('Y-m-d H:i:s'));

    // Create logs directory if it doesn't exist
    $logs_dir = __DIR__ . '/../../logs';
    if (!is_dir($logs_dir)) {
        mkdir($logs_dir, 0755, true);
    }

    // Create .htaccess to protect logs
    $htaccess_content = "Order deny,allow\nDeny from all";
    file_put_contents($logs_dir . '/.htaccess', $htaccess_content);

    $success[] = "✓ Setup completed successfully!";
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup - Credit Enroll Pro</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            background: linear-gradient(135deg, #dbc9bf 0%, #dcd8d4 100%);
            padding: 2rem;
            min-height: 100vh;
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
            padding: 2rem;
        }

        h1 {
            color: #9c6046;
            margin-bottom: 1rem;
            font-size: 28px;
        }

        h2 {
            color: #9c6046;
            margin: 1.5rem 0 1rem;
            font-size: 20px;
        }

        .message {
            padding: 1rem;
            margin: 1rem 0;
            border-radius: 8px;
        }

        .success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }

        .error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }

        .success-list, .error-list {
            list-style: none;
            margin: 0;
        }

        .success-list li, .error-list li {
            padding: 0.25rem 0;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #333;
        }

        input[type="text"],
        input[type="email"],
        input[type="password"] {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #dbc9bf;
            border-radius: 4px;
            font-size: 16px;
        }

        input:focus {
            outline: none;
            border-color: #9c6046;
        }

        .btn {
            display: inline-block;
            padding: 0.75rem 1.5rem;
            background: #9c6046;
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 16px;
            cursor: pointer;
            text-decoration: none;
            transition: background 0.3s;
        }

        .btn:hover {
            background: #854d37;
        }

        .btn-secondary {
            background: #b7bbac;
            color: #333;
        }

        .btn-secondary:hover {
            background: #a2a698;
        }

        .progress {
            margin: 2rem 0;
        }

        .progress-bar {
            display: flex;
            justify-content: space-between;
            margin-bottom: 1rem;
        }

        .progress-step {
            flex: 1;
            text-align: center;
            padding: 0.5rem;
            background: #f0f0f0;
            margin: 0 0.25rem;
            border-radius: 4px;
            font-size: 14px;
        }

        .progress-step.active {
            background: #9c6046;
            color: white;
        }

        .progress-step.complete {
            background: #28a745;
            color: white;
        }

        .help-text {
            font-size: 14px;
            color: #666;
            margin-top: 0.25rem;
        }

        .completion-box {
            background: #d4edda;
            border: 2px solid #28a745;
            border-radius: 8px;
            padding: 2rem;
            text-align: center;
            margin: 2rem 0;
        }

        .completion-box h2 {
            color: #28a745;
            margin-top: 0;
        }

        .completion-box .btn {
            margin-top: 1rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Credit Enroll Pro</h1>
        <h2>Initial Setup</h2>

        <div class="progress">
            <div class="progress-bar">
                <div class="progress-step <?php echo $step >= 1 ? 'complete' : ($step == 0 ? 'active' : ''); ?>">1. Database</div>
                <div class="progress-step <?php echo $step >= 2 ? 'complete' : ($step == 1 ? 'active' : ''); ?>">2. Tables</div>
                <div class="progress-step <?php echo $step >= 3 ? 'complete' : ($step == 2 ? 'active' : ''); ?>">3. Encryption</div>
                <div class="progress-step <?php echo $step >= 4 ? 'complete' : ($step == 3 ? 'active' : ''); ?>">4. Admin User</div>
                <div class="progress-step <?php echo $step >= 5 ? 'complete' : ($step == 4 ? 'active' : ''); ?>">5. Complete</div>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="message error">
                <ul class="error-list">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="message success">
                <ul class="success-list">
                    <?php foreach ($success as $msg): ?>
                        <li><?php echo htmlspecialchars($msg); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($step == 0): ?>
            <h2>Database Configuration</h2>
            <p>Please enter your database connection details. These will be saved to your config.php file.</p>
            <form method="POST" action="?step=1">
                <div class="form-group">
                    <label for="db_host">Database Host *</label>
                    <input type="text" id="db_host" name="db_host" required
                           value="<?php echo htmlspecialchars($_POST['db_host'] ?? 'localhost'); ?>">
                    <div class="help-text">Usually "localhost" or your database server address</div>
                </div>

                <div class="form-group">
                    <label for="db_name">Database Name *</label>
                    <input type="text" id="db_name" name="db_name" required
                           value="<?php echo htmlspecialchars($_POST['db_name'] ?? ''); ?>">
                    <div class="help-text">The name of your MySQL database</div>
                </div>

                <div class="form-group">
                    <label for="db_user">Database Username *</label>
                    <input type="text" id="db_user" name="db_user" required
                           value="<?php echo htmlspecialchars($_POST['db_user'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="db_pass">Database Password *</label>
                    <input type="password" id="db_pass" name="db_pass" required>
                </div>

                <button type="submit" class="btn">Test Connection & Continue</button>
            </form>
        <?php endif; ?>

        <?php if ($step >= 1 && $step < 4 && empty($errors)): ?>
            <form method="POST" action="">
                <input type="hidden" name="step" value="<?php echo $step + 1; ?>">
                <input type="hidden" name="db_host" value="<?php echo htmlspecialchars($db_host); ?>">
                <input type="hidden" name="db_name" value="<?php echo htmlspecialchars($db_name); ?>">
                <input type="hidden" name="db_user" value="<?php echo htmlspecialchars($db_user); ?>">
                <input type="hidden" name="db_pass" value="<?php echo htmlspecialchars($db_pass); ?>">
                <button type="submit" class="btn">Continue to Next Step</button>
            </form>
        <?php endif; ?>

        <?php if ($step == 4 && empty($errors)): ?>
            <h2>Create Admin User</h2>
            <form method="POST" action="">
                <input type="hidden" name="create_admin" value="1">
                <input type="hidden" name="db_host" value="<?php echo htmlspecialchars($db_host); ?>">
                <input type="hidden" name="db_name" value="<?php echo htmlspecialchars($db_name); ?>">
                <input type="hidden" name="db_user" value="<?php echo htmlspecialchars($db_user); ?>">
                <input type="hidden" name="db_pass" value="<?php echo htmlspecialchars($db_pass); ?>">

                <div class="form-group">
                    <label for="admin_username">Username *</label>
                    <input type="text" id="admin_username" name="admin_username" required
                           value="<?php echo htmlspecialchars($_POST['admin_username'] ?? ''); ?>">
                    <div class="help-text">Choose a unique username for logging in</div>
                </div>

                <div class="form-group">
                    <label for="admin_email">Email *</label>
                    <input type="email" id="admin_email" name="admin_email" required
                           value="<?php echo htmlspecialchars($_POST['admin_email'] ?? ''); ?>">
                    <div class="help-text">This will be used for 2FA and notifications</div>
                </div>

                <div class="form-group">
                    <label for="admin_name">Full Name *</label>
                    <input type="text" id="admin_name" name="admin_name" required
                           value="<?php echo htmlspecialchars($_POST['admin_name'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="admin_password">Password *</label>
                    <input type="password" id="admin_password" name="admin_password" required minlength="8">
                    <div class="help-text">Minimum 8 characters</div>
                </div>

                <div class="form-group">
                    <label for="admin_confirm">Confirm Password *</label>
                    <input type="password" id="admin_confirm" name="admin_confirm" required minlength="8">
                </div>

                <button type="submit" class="btn">Create Admin User</button>
            </form>
        <?php endif; ?>

        <?php if ($step >= 5): ?>
            <div class="completion-box">
                <h2>Setup Complete!</h2>
                <p>Your Credit Enroll Pro system has been successfully set up.</p>
                <p style="margin-top: 1rem;">
                    <strong>Important:</strong> For security reasons, please delete or restrict access to the /setup directory.
                </p>
                <a href="../admin/login.php" class="btn">Go to Admin Login</a>
                <a href="../" class="btn btn-secondary">View Enrollment Page</a>
            </div>

            <h2>Next Steps</h2>
            <ol>
                <li>Secure or delete the <code>/setup</code> directory</li>
                <li>Configure your API keys in the admin panel (VoIP.ms, MailerSend, etc.)</li>
                <li>Customize your enrollment questions</li>
                <li>Upload the agreement PDFs to <code>/src/agreements/</code></li>
                <li>Upload your logos to <code>/src/img/</code></li>
                <li>Test the enrollment flow</li>
                <li>Create affiliate codes</li>
            </ol>
        <?php endif; ?>
    </div>
</body>
</html>
