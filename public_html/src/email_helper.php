<?php
/**
 * Email Helper using MailerSend API
 * SNS Enrollment System
 */

if (!defined('SNS_ENROLLMENT')) {
    die('Direct access not permitted');
}

/**
 * Send email via MailerSend API
 */
function send_email_via_mailersend($to_email, $to_name, $subject, $html_content, $text_content = null) {
    global $pdo;

    try {
        // Get MailerSend configuration
        $stmt = $pdo->prepare("SELECT api_key_encrypted, additional_config, is_enabled FROM api_keys WHERE service_name = 'mailersend'");
        $stmt->execute();
        $config = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$config || !$config['is_enabled']) {
            log_activity("MailerSend is not enabled", 'ERROR');
            return ['success' => false, 'error' => 'Email service not configured'];
        }

        $additional_config = json_decode($config['additional_config'], true);
        $api_token = $additional_config['api_token'] ?? null;
        $from_email = $additional_config['from_email'] ?? 'noreply@example.com';
        $from_name = COMPANY_NAME ?? 'Your Company Name';

        if (!$api_token) {
            log_activity("MailerSend API token not found", 'ERROR');
            return ['success' => false, 'error' => 'Email service not configured'];
        }

        // Prepare email data
        $email_data = [
            'from' => [
                'email' => $from_email,
                'name' => $from_name
            ],
            'to' => [
                [
                    'email' => $to_email,
                    'name' => $to_name
                ]
            ],
            'subject' => $subject,
            'html' => $html_content
        ];

        if ($text_content) {
            $email_data['text'] = $text_content;
        }

        // Send via MailerSend API
        $ch = curl_init('https://api.mailersend.com/v1/email');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_token
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($email_data));

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code >= 200 && $http_code < 300) {
            log_activity("Email sent successfully to {$to_email}", 'INFO');
            return ['success' => true];
        } else {
            log_activity("MailerSend API error: HTTP {$http_code} - {$response}", 'ERROR');
            return ['success' => false, 'error' => 'Failed to send email'];
        }

    } catch (Exception $e) {
        log_activity("Email sending error: " . $e->getMessage(), 'ERROR');
        return ['success' => false, 'error' => 'Email service error'];
    }
}

/**
 * Send 2FA code via email
 */
function send_2fa_code_email($email, $code, $recipient_name = '') {
    global $pdo;

    // Load company name from database
    $company_name = COMPANY_NAME; // Fallback
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

    $subject = 'Your Verification Code - ' . $company_name;

    $logo_url = BRAND_LOGO_URL;

    $html_content = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; line-height: 1.6; color: #333; background: #dcd8d4; padding: 20px; }
        .email-wrapper { max-width: 600px; margin: 0 auto; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 12px rgba(156, 96, 70, 0.15); }
        .header { background: #9c6046; color: white; padding: 40px 30px; text-align: center; }
        .logo-img { max-width: 250px; height: auto; margin-bottom: 15px; }
        .header-subtitle { font-size: 16px; color: #dbc9bf; font-weight: 400; margin-top: 10px; }
        .content { padding: 40px 30px; background: white; }
        .greeting { font-size: 20px; font-weight: 600; color: #9c6046; margin-bottom: 20px; }
        .message { color: #555; margin-bottom: 30px; line-height: 1.8; font-size: 15px; }
        .code-container { background: #dcd8d4; border: 3px solid #9c6046; border-radius: 8px; padding: 30px; margin: 30px 0; text-align: center; }
        .code-label { font-size: 13px; color: #9c6046; margin-bottom: 15px; text-transform: uppercase; letter-spacing: 1.5px; font-weight: 700; }
        .code { font-size: 48px; font-weight: 700; color: #9c6046; letter-spacing: 10px; font-family: "Courier New", monospace; padding: 20px; background: white; border-radius: 6px; display: inline-block; box-shadow: 0 2px 8px rgba(156, 96, 70, 0.1); }
        .expiry-notice { background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px 20px; margin: 25px 0; border-radius: 4px; }
        .expiry-notice p { margin: 0; color: #856404; font-size: 14px; }
        .security-notice { background: #dbc9bf; border-left: 4px solid #9c6046; padding: 15px 20px; margin: 25px 0; border-radius: 4px; }
        .security-notice p { margin: 0; color: #5d3828; font-size: 14px; }
        .footer { background: #dcd8d4; padding: 30px; text-align: center; }
        .footer-text { color: #666; font-size: 13px; line-height: 1.8; }
        .footer-text strong { color: #9c6046; display: block; margin-bottom: 8px; font-size: 15px; }
        .contact-info { margin-top: 15px; padding-top: 15px; border-top: 1px solid #c2b5aa; }
        .contact-info a { color: #9c6046; text-decoration: none; font-weight: 600; }
    </style>
</head>
<body>
    <div class="email-wrapper">
        <div class="header">
            <img src="' . $logo_url . '" alt="' . htmlspecialchars($company_name) . '" class="logo-img">
            <div class="header-subtitle">Secure Account Verification</div>
        </div>
        <div class="content">
            <div class="greeting">Hello' . ($recipient_name ? ' ' . htmlspecialchars($recipient_name) : '') . ',</div>
            <div class="message">
                <p>We received a request to access your account. To complete the login process, please use the verification code below:</p>
            </div>
            <div class="code-container">
                <div class="code-label">Your Verification Code</div>
                <div class="code">' . htmlspecialchars($code) . '</div>
            </div>
            <div class="expiry-notice">
                <p><strong>⏱️ Time Sensitive:</strong> This code will expire in 15 minutes for your security.</p>
            </div>
            <div class="security-notice">
                <p><strong>🔒 Security Notice:</strong> If you did not attempt to log in, please disregard this email and consider changing your password immediately.</p>
            </div>
        </div>
        <div class="footer">
            <div class="footer-text">
                <strong>' . htmlspecialchars($company_name) . '</strong>
                Professional Notary & Credit Repair Services
            </div>
            <div class="contact-info footer-text">
                Questions? Contact us at <a href="mailto:support@example.com">support@example.com</a>
                <br>
                &copy; ' . date('Y') . ' ' . htmlspecialchars($company_name) . '. All rights reserved.
            </div>
        </div>
    </div>
</body>
</html>';

    $text_content = "Hello" . ($recipient_name ? ' ' . $recipient_name : '') . ",\n\n";
    $text_content .= "Your {$company_name} verification code is: {$code}\n\n";
    $text_content .= "This code will expire in 15 minutes.\n\n";
    $text_content .= "If you did not request this code, please ignore this email and consider changing your password.\n\n";
    $text_content .= "---\n";
    $text_content .= "{$company_name}\n";
    $text_content .= "Professional Notary & Credit Repair Services\n";
    $text_content .= "support@example.com\n";

    return send_email_via_mailersend($email, $recipient_name, $subject, $html_content, $text_content);
}
