<?php
/**
 * Notification Helper
 * Handles SMS and Email notifications using templates from the database
 * SNS Enrollment System
 */

if (!defined('SNS_ENROLLMENT')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/email_helper.php';

/**
 * Split message into SMS-safe chunks
 * - Single message max: 140 chars (conservative, VoIP.ms allows 160)
 * - URLs are sent separately
 * - Splits on word boundaries
 *
 * @param string $message The message to split
 * @return array Array of message chunks
 */
function split_sms_message($message) {
    $max_length = 140;
    $chunks = [];

    // Extract URLs from message
    $url_pattern = '/https?:\/\/[^\s]+/i';
    preg_match_all($url_pattern, $message, $url_matches);
    $urls = $url_matches[0];

    // Remove URLs from message
    $text_without_urls = preg_replace($url_pattern, '', $message);
    $text_without_urls = trim(preg_replace('/\s+/', ' ', $text_without_urls));

    // Split text into chunks if needed
    if (!empty($text_without_urls)) {
        if (strlen($text_without_urls) <= $max_length) {
            $chunks[] = $text_without_urls;
        } else {
            // Split on word boundaries
            $words = explode(' ', $text_without_urls);
            $current_chunk = '';

            foreach ($words as $word) {
                $test_chunk = empty($current_chunk) ? $word : $current_chunk . ' ' . $word;

                if (strlen($test_chunk) <= $max_length) {
                    $current_chunk = $test_chunk;
                } else {
                    if (!empty($current_chunk)) {
                        $chunks[] = $current_chunk;
                    }
                    $current_chunk = $word;

                    // Handle single word longer than max_length
                    if (strlen($current_chunk) > $max_length) {
                        $chunks[] = substr($current_chunk, 0, $max_length);
                        $current_chunk = '';
                    }
                }
            }

            if (!empty($current_chunk)) {
                $chunks[] = $current_chunk;
            }
        }
    }

    // Add URLs as separate chunks
    foreach ($urls as $url) {
        $chunks[] = $url;
    }

    return $chunks;
}

/**
 * Get template from database
 *
 * @param string $template_name Template name (e.g., 'enrollment_complete_client')
 * @param string $template_type 'sms' or 'email'
 * @return array|null Template data or null if not found
 */
function get_notification_template($template_name, $template_type) {
    global $pdo;

    try {
        $stmt = $pdo->prepare("
            SELECT * FROM communication_templates
            WHERE template_name = ? AND template_type = ?
            LIMIT 1
        ");
        $stmt->execute([$template_name, $template_type]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        log_activity("Error fetching notification template {$template_name}: " . $e->getMessage(), 'ERROR');
        return null;
    }
}

/**
 * Replace placeholders in template content
 *
 * @param string $content Template content with placeholders
 * @param array $data Data to replace placeholders with
 * @return string Content with placeholders replaced
 */
function replace_template_placeholders($content, $data) {
    global $pdo;

    // Get brand name from settings
    $brand_name = COMPANY_NAME;
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'company_name' LIMIT 1");
        $stmt->execute();
        $result = $stmt->fetch();
        if ($result && !empty($result['setting_value'])) {
            $brand_name = $result['setting_value'];
        }
    } catch (Exception $e) {
        // Use fallback
    }

    // Get brand logo URL
    $brand_logo = '<img src="' . BRAND_LOGO_URL . '" alt="' . htmlspecialchars($brand_name) . '" style="max-width: 250px; height: auto; margin-bottom: 20px;">';

    // Build replacement map
    $replacements = [
        '[brand_name]' => $brand_name,
        '[brand_logo]' => $brand_logo,
        '[client_name]' => $data['client_name'] ?? '',
        '[client_first_name]' => $data['client_first_name'] ?? '',
        '[client_last_name]' => $data['client_last_name'] ?? '',
        '[client_email]' => $data['client_email'] ?? '',
        '[client_phone]' => $data['client_phone'] ?? '',
        '[client_spouse_name]' => $data['client_spouse_name'] ?? '',
        '[plan_name]' => $data['plan_name'] ?? '',
        '[enrollment_url]' => $data['enrollment_url'] ?? '',
        '[spouse_url]' => $data['spouse_url'] ?? '',
        '[login_url]' => $data['login_url'] ?? BASE_URL . '/admin/',
    ];

    return str_replace(array_keys($replacements), array_values($replacements), $content);
}

/**
 * Build email HTML wrapper
 *
 * @param string $content Email body content
 * @return string Full HTML email
 */
function build_email_html($content) {
    global $pdo;

    $brand_name = COMPANY_NAME;
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'company_name' LIMIT 1");
        $stmt->execute();
        $result = $stmt->fetch();
        if ($result && !empty($result['setting_value'])) {
            $brand_name = $result['setting_value'];
        }
    } catch (Exception $e) {
        // Use fallback
    }

    return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; line-height: 1.6; color: #333; background: #dcd8d4; padding: 20px; }
        .email-wrapper { max-width: 600px; margin: 0 auto; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 12px rgba(156, 96, 70, 0.15); }
        .header { background: #9c6046; color: white; padding: 40px 30px; text-align: center; }
        .content { padding: 40px 30px; background: white; }
        .content h2 { color: #9c6046; margin-bottom: 20px; }
        .content p { color: #555; margin-bottom: 15px; line-height: 1.8; font-size: 15px; }
        .content strong { color: #9c6046; }
        .footer { background: #dcd8d4; padding: 30px; text-align: center; }
        .footer-text { color: #666; font-size: 13px; line-height: 1.8; }
        .footer-text strong { color: #9c6046; display: block; margin-bottom: 8px; font-size: 15px; }
        .contact-info { margin-top: 15px; padding-top: 15px; border-top: 1px solid #c2b5aa; }
        .contact-info a { color: #9c6046; text-decoration: none; font-weight: 600; }
    </style>
</head>
<body>
    <div class="email-wrapper">
        <div class="content">
            ' . $content . '
        </div>
        <div class="footer">
            <div class="footer-text">
                <strong>' . htmlspecialchars($brand_name) . '</strong>
                Professional Notary & Credit Repair Services
            </div>
            <div class="contact-info footer-text">
                Questions? Contact us at <a href="mailto:support@example.com">support@example.com</a>
                <br>
                &copy; ' . date('Y') . ' ' . htmlspecialchars($brand_name) . '. All rights reserved.
            </div>
        </div>
    </div>
</body>
</html>';
}

/**
 * Send notification (SMS and/or Email) based on template
 *
 * @param string $template_name Template name (e.g., 'enrollment_complete_client')
 * @param string $category Template category ('client', 'staff', or 'affiliate')
 * @param array $recipient Recipient info ['name' => '', 'email' => '', 'phone' => '']
 * @param array $data Template data for placeholders
 * @param string|null $attachment_path Path to file to attach (email only)
 * @return array Result ['sms_sent' => bool, 'email_sent' => bool, 'errors' => []]
 */
function send_notification($template_name, $category, $recipient, $data, $attachment_path = null, $attachment_path_2 = null, $attachment_2_filename = null) {
    global $pdo;

    $result = [
        'sms_sent' => false,
        'email_sent' => false,
        'errors' => []
    ];

    // Get SMS template
    $sms_template = get_notification_template($template_name, 'sms');
    if ($sms_template && !empty($recipient['phone'])) {
        $sms_content = replace_template_placeholders($sms_template['content'], $data);

        try {
            // Send SMS directly via VoIP.MS API (not using send_sms function to avoid redirects)
            $voipms_stmt = $pdo->prepare("SELECT * FROM api_keys WHERE service_name = 'voipms'");
            $voipms_stmt->execute();
            $voipms_config = $voipms_stmt->fetch();

            if ($voipms_config && $voipms_config['is_enabled']) {
                $config = json_decode($voipms_config['additional_config'], true);
                $api_user = $config['username'] ?? '';
                $api_pass = $config['password'] ?? '';
                $did = $config['did'] ?? '';

                $from = clean_phone($did);
                $to = clean_phone($recipient['phone']);

                if (!empty($api_user) && !empty($api_pass) && !empty($from) && !empty($to)) {
                    // Split message into chunks (max 140 chars, URLs sent separately)
                    $chunks = split_sms_message($sms_content);
                    $all_success = true;

                    foreach ($chunks as $index => $chunk) {
                        // Build API URL
                        $url = 'https://voip.ms/api/v1/rest.php?' . http_build_query([
                            'api_username' => $api_user,
                            'api_password' => $api_pass,
                            'method' => 'sendSMS',
                            'did' => $from,
                            'dst' => $to,
                            'message' => $chunk
                        ]);

                        // Make API request
                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

                        $response = curl_exec($ch);
                        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        curl_close($ch);

                        $sms_api_result = json_decode($response, true);

                        if ($http_code === 200 && isset($sms_api_result['status']) && $sms_api_result['status'] === 'success') {
                            // Log to SMS messages table
                            try {
                                $stmt = $pdo->prepare("
                                    INSERT INTO sms_messages (direction, from_number, to_number, message, status, voipms_id)
                                    VALUES ('outbound', ?, ?, ?, 'sent', ?)
                                ");
                                $voipms_id = $sms_api_result['sms'] ?? null;
                                $stmt->execute([$from, $to, $chunk, $voipms_id]);
                            } catch (Exception $e) {
                                log_activity("Failed to log SMS: " . $e->getMessage(), 'ERROR');
                            }
                        } else {
                            $all_success = false;
                            $error_msg = "HTTP {$http_code}, Response: " . substr($response, 0, 200);
                            $result['errors'][] = "SMS chunk {$index} failed: {$error_msg}";
                            log_activity("SMS notification chunk {$index} failed: {$template_name} to {$recipient['phone']} - {$error_msg}", 'WARNING');
                        }

                        // Small delay between messages to avoid rate limiting
                        if ($index < count($chunks) - 1) {
                            usleep(500000); // 0.5 seconds
                        }
                    }

                    if ($all_success) {
                        $result['sms_sent'] = true;
                        log_activity("SMS notification sent: {$template_name} to {$recipient['phone']} (" . count($chunks) . " chunks)", 'INFO');
                    }
                } else {
                    $result['errors'][] = "SMS not configured properly";
                    log_activity("VoIP.MS not configured properly for SMS notification", 'WARNING');
                }
            } else {
                $result['errors'][] = "SMS not enabled";
                log_activity("VoIP.MS not enabled for SMS notification", 'WARNING');
            }
        } catch (Exception $e) {
            $result['errors'][] = "SMS exception: " . $e->getMessage();
            log_activity("SMS notification exception: {$template_name} - " . $e->getMessage(), 'ERROR');
        }
    }

    // Get Email template
    $email_template = get_notification_template($template_name, 'email');
    if ($email_template && !empty($recipient['email'])) {
        $email_content = replace_template_placeholders($email_template['content'], $data);
        $email_subject = replace_template_placeholders($email_template['subject'], $data);
        $email_html = build_email_html($email_content);

        try {
            // If there's an attachment, use the modified version of send_email_via_mailersend
            if ($attachment_path && file_exists($attachment_path)) {
                $email_result = send_email_with_attachment(
                    $recipient['email'],
                    $recipient['name'] ?? '',
                    $email_subject,
                    $email_html,
                    $attachment_path,
                    $attachment_path_2,
                    $attachment_2_filename
                );
            } else {
                $email_result = send_email_via_mailersend(
                    $recipient['email'],
                    $recipient['name'] ?? '',
                    $email_subject,
                    $email_html
                );
            }

            if ($email_result && $email_result['success']) {
                $result['email_sent'] = true;
                log_activity("Email notification sent: {$template_name} to {$recipient['email']}", 'INFO');
            } else {
                $error_msg = $email_result['error'] ?? 'Unknown error';
                $result['errors'][] = "Email failed: {$error_msg}";
                log_activity("Email notification failed: {$template_name} to {$recipient['email']} - {$error_msg}", 'WARNING');
            }
        } catch (Exception $e) {
            $result['errors'][] = "Email exception: " . $e->getMessage();
            log_activity("Email notification exception: {$template_name} - " . $e->getMessage(), 'ERROR');
        }
    }

    return $result;
}

/**
 * Send email with attachment via MailerSend API
 *
 * @param string $to_email Recipient email
 * @param string $to_name Recipient name
 * @param string $subject Email subject
 * @param string $html_content HTML content
 * @param string $attachment_path Path to attachment file
 * @return array Result ['success' => bool, 'error' => string]
 */
function send_email_with_attachment($to_email, $to_name, $subject, $html_content, $attachment_path, $attachment_path_2 = null, $attachment_2_filename = null) {
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

        // Add attachments if provided
        $attachments = [];
        if ($attachment_path && file_exists($attachment_path)) {
            $file_content = file_get_contents($attachment_path);
            $file_base64 = base64_encode($file_content);
            $file_name = basename($attachment_path);

            $attachments[] = [
                'content' => $file_base64,
                'filename' => $file_name
            ];
        }

        // Add second attachment if provided
        if ($attachment_path_2 && file_exists($attachment_path_2)) {
            $file_content_2 = file_get_contents($attachment_path_2);
            $file_base64_2 = base64_encode($file_content_2);
            $file_name_2 = $attachment_2_filename ?: basename($attachment_path_2);

            $attachments[] = [
                'content' => $file_base64_2,
                'filename' => $file_name_2
            ];
        }

        if (!empty($attachments)) {
            $email_data['attachments'] = $attachments;
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
            log_activity("Email with attachment sent successfully to {$to_email}", 'INFO');
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
 * Send notifications to all active staff members with the specified notification preference enabled
 *
 * @param string $template_name Template name (e.g., 'new_lead_staff')
 * @param string $notification_pref Column name in staff table (e.g., 'notify_enrollment_started')
 * @param array $data Template data for placeholders
 * @param string|null $attachment_path Path to file to attach (email only)
 * @return array Result ['staff_notified' => int, 'results' => []]
 */
function notify_staff($template_name, $notification_pref, $data, $attachment_path = null, $attachment_path_2 = null, $attachment_2_filename = null) {
    global $pdo;

    $results = [];
    $staff_notified = 0;

    try {
        // Get all active staff members with this notification preference enabled
        $stmt = $pdo->prepare("
            SELECT id, full_name, email, phone
            FROM staff
            WHERE is_active = 1 AND {$notification_pref} = 1
        ");
        $stmt->execute();
        $staff_members = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($staff_members as $staff) {
            $recipient = [
                'name' => $staff['full_name'],
                'email' => $staff['email'],
                'phone' => $staff['phone']
            ];

            $result = send_notification($template_name, 'staff', $recipient, $data, $attachment_path, $attachment_path_2, $attachment_2_filename);

            if ($result['sms_sent'] || $result['email_sent']) {
                $staff_notified++;
            }

            $results[] = [
                'staff_id' => $staff['id'],
                'staff_name' => $staff['full_name'],
                'result' => $result
            ];
        }

        log_activity("Staff notifications sent: {$template_name} - {$staff_notified} staff members notified", 'INFO');

    } catch (PDOException $e) {
        log_activity("Error sending staff notifications: " . $e->getMessage(), 'ERROR');
    }

    return [
        'staff_notified' => $staff_notified,
        'results' => $results
    ];
}
