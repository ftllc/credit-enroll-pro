<?php
/**
 * Credit Enroll Pro - Footer Template
 */

if (!defined('SNS_ENROLLMENT')) {
    die('Direct access not permitted');
}

// Load company name from database settings
$company_name = COMPANY_NAME; // Fallback to constant
try {
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'company_name' LIMIT 1");
    $stmt->execute();
    $result = $stmt->fetch();
    if ($result && !empty($result['setting_value'])) {
        $company_name = $result['setting_value'];
    }
} catch (Exception $e) {
    // Use fallback if query fails
}
?>
    </main>

    <footer class="site-footer">
        <div class="footer-container">
            <div class="footer-content">
                <div class="footer-section">
                    <h3>Contact Us</h3>
                    <p>For questions about your enrollment, please contact us.</p>
                </div>
                <div class="footer-section">
                    <h3>Legal</h3>
                    <ul>
                        <li><a href="/noc/">Notice of Cancellation</a></li>
                    </ul>
                </div>
            </div>
            <div class="footer-bottom">
                <p>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($company_name); ?>. All rights reserved.</p>
                <p class="footer-disclaimer">
                    This is a secure enrollment system. All information is encrypted and protected.
                </p>
            </div>
        </div>
    </footer>

    <style>
        .site-footer {
            background-color: var(--color-primary);
            color: #fff;
            padding: var(--spacing-lg) 0 var(--spacing-md);
            margin-top: var(--spacing-xl);
        }

        .footer-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 var(--spacing-md);
        }

        .footer-content {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: var(--spacing-lg);
            margin-bottom: var(--spacing-lg);
        }

        .footer-section h3 {
            font-size: var(--font-size-large);
            margin-bottom: var(--spacing-sm);
            color: var(--color-light-1);
        }

        .footer-section p,
        .footer-section ul {
            font-size: var(--font-size-small);
            line-height: 1.8;
        }

        .footer-section ul {
            list-style: none;
        }

        .footer-section ul li {
            margin-bottom: var(--spacing-xs);
        }

        .footer-section a {
            color: #fff;
            text-decoration: none;
            transition: color var(--transition-speed);
        }

        .footer-section a:hover {
            color: var(--color-light-2);
            text-decoration: underline;
        }

        .footer-bottom {
            border-top: 1px solid rgba(255, 255, 255, 0.2);
            padding-top: var(--spacing-md);
            text-align: center;
        }

        .footer-bottom p {
            font-size: var(--font-size-small);
            margin-bottom: var(--spacing-xs);
        }

        .footer-disclaimer {
            opacity: 0.8;
            font-size: 12px;
        }

        @media (max-width: 768px) {
            .footer-content {
                grid-template-columns: 1fr;
                gap: var(--spacing-md);
            }

            .site-footer {
                padding: var(--spacing-md) 0;
            }
        }
    </style>

    <!-- Common JavaScript -->
    <script>
        // Disable form auto-complete for sensitive fields
        document.addEventListener('DOMContentLoaded', function() {
            // Add autocomplete="off" to sensitive inputs
            const sensitiveInputs = document.querySelectorAll('input[type="password"], input[name*="ssn"], input[name*="dob"]');
            sensitiveInputs.forEach(input => {
                input.setAttribute('autocomplete', 'off');
            });
        });

        // Form validation helper
        function showError(inputElement, message) {
            inputElement.classList.add('error');
            const errorDiv = inputElement.parentElement.querySelector('.form-error');
            if (errorDiv) {
                errorDiv.textContent = message;
                errorDiv.classList.add('show');
            }
        }

        function clearError(inputElement) {
            inputElement.classList.remove('error');
            const errorDiv = inputElement.parentElement.querySelector('.form-error');
            if (errorDiv) {
                errorDiv.classList.remove('show');
            }
        }

        // Phone number formatting
        function formatPhoneNumber(input) {
            let value = input.value.replace(/\D/g, '');
            if (value.length > 10) value = value.substr(0, 10);

            if (value.length >= 6) {
                value = '(' + value.substr(0, 3) + ') ' + value.substr(3, 3) + '-' + value.substr(6);
            } else if (value.length >= 3) {
                value = '(' + value.substr(0, 3) + ') ' + value.substr(3);
            }

            input.value = value;
        }

        // Email validation
        function isValidEmail(email) {
            const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return re.test(email);
        }

        // Phone validation (US format)
        function isValidPhone(phone) {
            const cleaned = phone.replace(/\D/g, '');
            return cleaned.length === 10;
        }

        // SSN validation
        function isValidSSN(ssn) {
            const cleaned = ssn.replace(/\D/g, '');
            return cleaned.length === 9;
        }

        // Format SSN input
        function formatSSN(input) {
            let value = input.value.replace(/\D/g, '');
            if (value.length > 9) value = value.substr(0, 9);

            if (value.length >= 5) {
                value = value.substr(0, 3) + '-' + value.substr(3, 2) + '-' + value.substr(5);
            } else if (value.length >= 3) {
                value = value.substr(0, 3) + '-' + value.substr(3);
            }

            input.value = value;
        }

        // Session activity tracking
        let lastActivity = Date.now();

        function updateActivity() {
            lastActivity = Date.now();
            // Send heartbeat to server
            if (typeof enrollmentId !== 'undefined') {
                fetch('<?php echo BASE_URL; ?>/src/heartbeat.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        enrollment_id: enrollmentId,
                        timestamp: lastActivity
                    })
                }).catch(err => console.error('Heartbeat failed:', err));
            }
        }

        // Track user activity
        ['mousedown', 'keypress', 'scroll', 'touchstart'].forEach(event => {
            document.addEventListener(event, function() {
                updateActivity();
            }, true);
        });

        // Send heartbeat every 30 seconds
        setInterval(updateActivity, 30000);

        // Prevent accidental navigation away
        <?php if (isset($prevent_navigation) && $prevent_navigation): ?>
        window.addEventListener('beforeunload', function(e) {
            e.preventDefault();
            e.returnValue = 'You have unsaved changes. Are you sure you want to leave?';
            return e.returnValue;
        });
        <?php endif; ?>

        // Loading state helper
        function setLoading(button, loading = true) {
            if (loading) {
                button.disabled = true;
                button.dataset.originalText = button.innerHTML;
                button.innerHTML = '<span class="spinner"></span> Loading...';
            } else {
                button.disabled = false;
                button.innerHTML = button.dataset.originalText;
            }
        }
    </script>

</body>
</html>
