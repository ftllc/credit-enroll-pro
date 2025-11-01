<?php
/**
 * Credit Enroll Pro - Header Template
 */

if (!defined('SNS_ENROLLMENT')) {
    die('Direct access not permitted');
}

// Fetch company name from settings database
$stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'company_name'");
$stmt->execute();
$company_name = $stmt->fetchColumn() ?: COMPANY_NAME; // Fallback to constant if not set

$page_title = $page_title ?? 'Credit Repair Enrollment';
$page_description = $page_description ?? 'Enroll in professional credit repair services with ' . $company_name;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?php echo htmlspecialchars($page_description); ?>">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo htmlspecialchars($page_title . ' | ' . $company_name); ?></title>

    <!-- Favicon -->
    <link rel="icon" type="image/png" href="<?php echo WATERMARK_LOGO_URL; ?>">

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Arial:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Google Maps API -->
    <?php if (GOOGLE_MAPS_ENABLED && isset($include_google_maps) && $include_google_maps): ?>
    <script src="https://maps.googleapis.com/maps/api/js?key=<?php echo GOOGLE_MAPS_API_KEY; ?>&libraries=places"></script>
    <?php endif; ?>

    <!-- reCAPTCHA v3 -->
    <?php if (RECAPTCHA_ENABLED && isset($include_recaptcha) && $include_recaptcha): ?>
    <script src="https://www.google.com/recaptcha/enterprise.js?render=<?php echo RECAPTCHA_SITE_KEY; ?>"></script>
    <?php endif; ?>

    <!-- XactoAuth SSO Top Bar CSS -->
    <?php if (isset($_SESSION['staff_logged_in']) && $_SESSION['staff_logged_in'] === true && isset($_SESSION['xactoauth_access_token'])): ?>
    <link rel="stylesheet" href="https://auth.transxacto.net/sdk/xactoauth-topbar.css">
    <?php endif; ?>

    <!-- Google Analytics -->
    <?php if (GA_ENABLED): ?>
    <script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo GA_TRACKING_ID; ?>"></script>
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('js', new Date());
        gtag('config', '<?php echo GA_TRACKING_ID; ?>');
    </script>
    <?php endif; ?>

    <style>
        :root {
            /* Brand Colors - Loaded from database settings */
            --color-primary: <?php echo COLOR_PRIMARY; ?>;
            --color-secondary: <?php echo COLOR_SECONDARY; ?>;
            --color-accent: <?php echo COLOR_ACCENT; ?>;
            --color-light-1: <?php echo COLOR_LIGHT_1; ?>;
            --color-light-2: <?php echo COLOR_LIGHT_2; ?>;
            --color-light-3: #c2b5aa;
            --color-neutral: #afaa9e;
            --color-header-text: <?php echo COLOR_HEADER_TEXT; ?>;

            /* Functional Colors */
            --color-success: #28a745;
            --color-danger: #dc3545;
            --color-warning: #ffc107;
            --color-info: #17a2b8;

            /* Typography */
            --font-family: Arial, Helvetica, sans-serif;
            --font-size-base: 16px;
            --font-size-small: 14px;
            --font-size-large: 18px;
            --font-size-h1: 32px;
            --font-size-h2: 28px;
            --font-size-h3: 24px;

            /* Spacing */
            --spacing-xs: 0.5rem;
            --spacing-sm: 1rem;
            --spacing-md: 1.5rem;
            --spacing-lg: 2rem;
            --spacing-xl: 3rem;

            /* Borders & Shadows */
            --border-radius: 8px;
            --border-radius-sm: 4px;
            --border-radius-lg: 12px;
            --box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            --box-shadow-lg: 0 4px 16px rgba(0, 0, 0, 0.15);

            /* Transitions */
            --transition-speed: 0.3s;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: var(--font-family);
            font-size: var(--font-size-base);
            line-height: 1.6;
            color: #333;
            background-color: var(--color-light-1);
            overflow-x: hidden;
        }

        /* Header Styles */
        .site-header {
            background-color: #fff;
            box-shadow: var(--box-shadow);
            padding: var(--spacing-sm) 0;
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .header-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 var(--spacing-md);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: var(--spacing-sm);
            text-decoration: none;
            color: var(--color-header-text);
        }

        .logo img {
            height: 50px;
            width: auto;
        }

        .logo-text {
            font-size: var(--font-size-h3);
            font-weight: 600;
        }

        /* Main Content Container */
        .main-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: var(--spacing-lg) var(--spacing-md);
            min-height: calc(100vh - 200px);
        }

        /* Card Styles */
        .card {
            background: #fff;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            border: 2px solid var(--color-light-1);
            padding: var(--spacing-lg);
            margin-bottom: var(--spacing-md);
            transition: all var(--transition-speed);
        }

        .card:hover {
            border-color: var(--color-primary);
            box-shadow: var(--box-shadow-lg);
        }

        .card-header {
            border-bottom: 2px solid var(--color-light-1);
            padding-bottom: var(--spacing-sm);
            margin-bottom: var(--spacing-md);
            background-color: var(--color-light-1);
            margin: calc(var(--spacing-lg) * -1) calc(var(--spacing-lg) * -1) var(--spacing-md);
            padding: var(--spacing-md) var(--spacing-lg);
            border-radius: var(--border-radius) var(--border-radius) 0 0;
        }

        .card-title {
            font-size: var(--font-size-h2);
            color: var(--color-primary);
            font-weight: 600;
        }

        /* Form Styles */
        .form-group {
            margin-bottom: var(--spacing-md);
        }

        .form-label {
            display: block;
            margin-bottom: var(--spacing-xs);
            font-weight: 500;
            color: #333;
        }

        .form-label.required::after {
            content: " *";
            color: var(--color-danger);
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #ddd;
            border-radius: var(--border-radius-sm);
            font-size: var(--font-size-base);
            font-family: var(--font-family);
            transition: all var(--transition-speed);
            background-color: #fff;
            box-shadow: inset 0 1px 3px rgba(0, 0, 0, 0.05);
        }

        .form-control:hover {
            border-color: #bbb;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--color-primary);
            box-shadow: 0 0 0 3px rgba(152, 38, 46, 0.1), inset 0 1px 3px rgba(0, 0, 0, 0.05);
        }

        .form-control.error {
            border-color: var(--color-danger);
        }

        .form-error {
            color: var(--color-danger);
            font-size: var(--font-size-small);
            margin-top: var(--spacing-xs);
            display: none;
        }

        .form-error.show {
            display: block;
        }

        .form-help {
            font-size: var(--font-size-small);
            color: #666;
            margin-top: var(--spacing-xs);
        }

        /* Button Styles */
        .btn {
            display: inline-block;
            padding: 0.75rem 1.5rem;
            font-size: var(--font-size-base);
            font-weight: 500;
            text-align: center;
            text-decoration: none;
            border: none;
            border-radius: var(--border-radius-sm);
            cursor: pointer;
            transition: all var(--transition-speed);
            font-family: var(--font-family);
        }

        .btn-primary {
            background-color: var(--color-primary);
            color: #fff;
        }

        .btn-primary:hover {
            background-color: #854d37;
            transform: translateY(-2px);
            box-shadow: var(--box-shadow-lg);
        }

        .btn-secondary {
            background-color: var(--color-secondary);
            color: #333;
        }

        .btn-secondary:hover {
            background-color: var(--color-accent);
        }

        .btn-outline {
            background-color: transparent;
            border: 2px solid var(--color-primary);
            color: var(--color-primary);
        }

        .btn-outline:hover {
            background-color: var(--color-primary);
            color: #fff;
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .btn-block {
            display: block;
            width: 100%;
        }

        /* Alert Styles */
        .alert {
            padding: var(--spacing-sm);
            border-radius: var(--border-radius-sm);
            margin-bottom: var(--spacing-md);
        }

        .alert-success {
            background-color: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }

        .alert-danger {
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }

        .alert-warning {
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            color: #856404;
        }

        .alert-info {
            background-color: #d1ecf1;
            border: 1px solid #bee5eb;
            color: #0c5460;
        }

        /* Loading Spinner */
        .spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .header-container {
                padding: 0 var(--spacing-sm);
            }

            .logo-text {
                font-size: var(--font-size-large);
            }

            .main-container {
                padding: var(--spacing-md) var(--spacing-sm);
            }

            .card {
                padding: var(--spacing-md);
            }

            .card-title {
                font-size: var(--font-size-h3);
            }
        }

        @media (max-width: 480px) {
            .logo img {
                height: 40px;
            }

            .logo-text {
                display: none;
            }
        }

        /* Admin-specific styles */
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
            font-weight: 500;
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
    </style>
</head>
<body>
    <?php
    // Display XactoAuth SSO navigation bar if user is authenticated
    if (isset($_SESSION['staff_logged_in']) && $_SESSION['staff_logged_in'] === true && isset($_SESSION['xactoauth_access_token'])) {
        try {
            $sso_bar_html = xactoauth_get_sso_bar($_SESSION['xactoauth_access_token']);
            if (!empty($sso_bar_html)) {
                echo $sso_bar_html;
            }
        } catch (Exception $e) {
            // Silently fail - don't break the page if SSO bar fails
            log_activity("SSO bar failed to load: " . $e->getMessage(), 'WARNING');
        }
    }
    ?>
    <header class="site-header">
        <div class="header-container">
            <a href="<?php echo BASE_URL; ?>" class="logo">
                <img src="<?php echo BRAND_LOGO_URL; ?>" alt="<?php echo htmlspecialchars($company_name); ?>">
                <span class="logo-text"><?php echo htmlspecialchars($company_name); ?></span>
            </a>
            <?php if (isset($show_session_id) && $show_session_id && isset($session_id)): ?>
            <div class="session-info">
                <span style="font-size: var(--font-size-small); color: var(--color-header-text);">
                    Session ID: <strong style="color: var(--color-header-text);"><?php echo htmlspecialchars($session_id); ?></strong>
                </span>
            </div>
            <?php endif; ?>
        </div>
    </header>

    <main class="main-container">
