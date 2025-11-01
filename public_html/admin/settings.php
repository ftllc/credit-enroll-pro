<?php
/**
 * Admin Settings Page - FULLY FUNCTIONAL
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
$active_tab = $_GET['tab'] ?? 'general';

// Check for OAuth success message
if (isset($_GET['zoho_success'])) {
    $success = urldecode($_GET['zoho_success']);
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_general'])) {
        $settings_to_update = [
            'company_name' => sanitize_input($_POST['company_name'] ?? ''),
            'brand_logo_url' => sanitize_input($_POST['brand_logo_url'] ?? ''),
            'profile_picture_url' => sanitize_input($_POST['profile_picture_url'] ?? ''),
            'color_primary' => sanitize_input($_POST['color_primary'] ?? '#9c6046'),
            'color_secondary' => sanitize_input($_POST['color_secondary'] ?? '#b7bbac'),
            'color_accent' => sanitize_input($_POST['color_accent'] ?? '#a2a698'),
            'color_light_1' => sanitize_input($_POST['color_light_1'] ?? '#dcd8d4'),
            'color_light_2' => sanitize_input($_POST['color_light_2'] ?? '#dbc9bf'),
            'color_header_text' => sanitize_input($_POST['color_header_text'] ?? '#ffffff'),
            'admin_email' => sanitize_input($_POST['admin_email'] ?? ''),
            'enrollments_enabled' => isset($_POST['enrollments_enabled']) ? 'true' : 'false',
            'enrollment_questions_enabled' => isset($_POST['enrollment_questions_enabled']) ? 'true' : 'false',
            'step_timeout_minutes' => intval($_POST['step_timeout_minutes'] ?? 5),
            'session_timeout_hours' => intval($_POST['session_timeout_hours'] ?? 72)
        ];

        try {
            foreach ($settings_to_update as $key => $value) {
                $stmt = $pdo->prepare("UPDATE settings SET setting_value = ?, updated_by = ? WHERE setting_key = ?");
                $stmt->execute([$value, $staff['id'], $key]);
            }
            $success = 'General settings updated successfully!';
        } catch (PDOException $e) {
            $error = 'Failed to update settings: ' . $e->getMessage();
        }
    } elseif (isset($_POST['save_plans'])) {
        try {
            $stmt = $pdo->prepare("UPDATE plans SET initial_work_fee = ?, monthly_fee = ? WHERE plan_type = 'individual'");
            $stmt->execute([floatval($_POST['individual_initial_fee'] ?? 0), floatval($_POST['individual_monthly_fee'] ?? 0)]);

            $stmt = $pdo->prepare("UPDATE plans SET initial_work_fee = ?, monthly_fee = ? WHERE plan_type = 'couple'");
            $stmt->execute([floatval($_POST['couple_initial_fee'] ?? 0), floatval($_POST['couple_monthly_fee'] ?? 0)]);

            $success = 'Plan pricing updated successfully!';
        } catch (PDOException $e) {
            $error = 'Failed to update plans: ' . $e->getMessage();
        }
    } elseif (isset($_POST['add_question'])) {
        $question_text = trim($_POST['question_text'] ?? '');
        $question_type = $_POST['question_type'] ?? 'short_answer';
        $is_required = isset($_POST['is_required']) ? 1 : 0;
        $options = $_POST['question_type'] === 'multiple_choice' ? json_encode(array_filter(array_map('trim', explode("\n", $_POST['options'] ?? '')))) : null;

        if (empty($question_text)) {
            $error = 'Question text is required.';
        } else {
            try {
                $stmt = $pdo->query("SELECT MAX(display_order) FROM enrollment_questions");
                $max_order = $stmt->fetchColumn() ?? 0;

                $stmt = $pdo->prepare("INSERT INTO enrollment_questions (question_text, question_type, options, is_required, display_order) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$question_text, $question_type, $options, $is_required, $max_order + 1]);
                $success = 'Question added successfully!';
            } catch (PDOException $e) {
                $error = 'Failed to add question: ' . $e->getMessage();
            }
        }
    } elseif (isset($_POST['update_question'])) {
        $question_id = intval($_POST['question_id'] ?? 0);
        $question_text = trim($_POST['question_text'] ?? '');
        $question_type = $_POST['question_type'] ?? 'short_answer';
        $is_required = isset($_POST['is_required']) ? 1 : 0;
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        // Handle options for multiple choice
        $options = null;
        if ($question_type === 'multiple_choice') {
            $options = json_encode(array_filter(array_map('trim', explode("\n", $_POST['options'] ?? ''))));
        }

        try {
            $stmt = $pdo->prepare("UPDATE enrollment_questions SET question_text = ?, question_type = ?, options = ?, is_required = ?, is_active = ? WHERE id = ?");
            $stmt->execute([$question_text, $question_type, $options, $is_required, $is_active, $question_id]);
            $success = 'Question updated successfully!';
        } catch (PDOException $e) {
            $error = 'Failed to update question: ' . $e->getMessage();
        }
    } elseif (isset($_POST['delete_question'])) {
        $question_id = intval($_POST['question_id'] ?? 0);
        try {
            $stmt = $pdo->prepare("DELETE FROM enrollment_questions WHERE id = ?");
            $stmt->execute([$question_id]);
            $success = 'Question deleted successfully!';
        } catch (PDOException $e) {
            $error = 'Failed to delete question: ' . $e->getMessage();
        }
    } elseif (isset($_POST['save_api_key'])) {
        $service = sanitize_input($_POST['service'] ?? '');
        $is_enabled = isset($_POST['is_enabled']) ? 1 : 0;
        $api_key = trim($_POST['api_key'] ?? '');
        $api_secret = trim($_POST['api_secret'] ?? '');

        try {
            // Get existing record
            $stmt = $pdo->prepare("SELECT * FROM api_keys WHERE service_name = ?");
            $stmt->execute([$service]);
            $existing = $stmt->fetch();

            $config = [];
            if (!empty($api_key)) $config['api_key'] = $api_key;
            if (!empty($api_secret)) $config['api_secret'] = $api_secret;

            // Add service-specific fields
            if ($service === 'recaptcha') {
                $config['site_key'] = trim($_POST['site_key'] ?? '');
                $config['secret_key'] = trim($_POST['secret_key'] ?? '');
                $config['project_id'] = trim($_POST['project_id'] ?? '');
            } elseif ($service === 'voipms') {
                $config['username'] = trim($_POST['username'] ?? '');
                $config['password'] = trim($_POST['password'] ?? '');
                $config['did'] = trim($_POST['did'] ?? '');
            } elseif ($service === 'zoho_books') {
                $config['client_id'] = trim($_POST['client_id'] ?? '');
                $config['client_secret'] = trim($_POST['client_secret'] ?? '');
                $config['refresh_token'] = trim($_POST['refresh_token'] ?? '');
                $config['organization_id'] = trim($_POST['organization_id'] ?? '');
            } elseif ($service === 'mailersend') {
                $config['api_token'] = trim($_POST['api_token'] ?? '');
                $config['from_email'] = trim($_POST['from_email'] ?? '');
            } elseif ($service === 'credit_repair_cloud') {
                $config['auth_key'] = trim($_POST['auth_key'] ?? '');
                $config['secret_key'] = trim($_POST['secret_key'] ?? '');
            } elseif ($service === 'systeme_io') {
                $config['api_key'] = trim($_POST['api_key'] ?? '');
            } elseif ($service === 'zapier') {
                // Generate API key if not exists or regenerate requested
                if (empty($existing['additional_config']) || isset($_POST['regenerate_key'])) {
                    $config['api_key'] = 'zpk_' . bin2hex(random_bytes(32));
                } else {
                    $existing_config = json_decode($existing['additional_config'], true);
                    $config['api_key'] = $existing_config['api_key'] ?? '';
                }
                $config['install_url'] = BASE_URL;
            }

            if ($existing) {
                $stmt = $pdo->prepare("UPDATE api_keys SET is_enabled = ?, additional_config = ? WHERE service_name = ?");
                $stmt->execute([$is_enabled, json_encode($config), $service]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO api_keys (service_name, is_enabled, additional_config) VALUES (?, ?, ?)");
                $stmt->execute([$service, $is_enabled, json_encode($config)]);
            }

            $success = 'API key settings saved successfully!';
        } catch (PDOException $e) {
            $error = 'Failed to save API key: ' . $e->getMessage();
        }
    } elseif (isset($_POST['create_contract_package'])) {
        $package_name = trim($_POST['package_name'] ?? '');
        $is_default = isset($_POST['is_default']) ? 1 : 0;
        $days_to_cancel = intval($_POST['days_to_cancel'] ?? 5);

        if (empty($package_name)) {
            $error = 'Package name is required.';
        } elseif ($days_to_cancel < 1 || $days_to_cancel > 365) {
            $error = 'Days to cancel must be between 1 and 365.';
        } else {
            try {
                $pdo->beginTransaction();

                // If setting as default, unset other defaults
                if ($is_default) {
                    $stmt = $pdo->prepare("UPDATE state_contract_packages SET is_default = 0");
                    $stmt->execute();
                }

                // Create package
                $stmt = $pdo->prepare("INSERT INTO state_contract_packages (package_name, is_default, days_to_cancel, created_by, updated_by) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$package_name, $is_default, $days_to_cancel, $staff['id'], $staff['id']]);

                $pdo->commit();
                $success = 'Contract package created successfully!';
                $active_tab = 'contracts';
            } catch (PDOException $e) {
                $pdo->rollBack();
                $error = 'Failed to create package: ' . $e->getMessage();
            }
        }
    } elseif (isset($_POST['update_days_to_cancel'])) {
        $package_id = intval($_POST['package_id'] ?? 0);
        $days_to_cancel = intval($_POST['days_to_cancel'] ?? 5);

        if ($days_to_cancel < 1 || $days_to_cancel > 365) {
            $error = 'Days to cancel must be between 1 and 365.';
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE state_contract_packages SET days_to_cancel = ?, updated_by = ? WHERE id = ?");
                $stmt->execute([$days_to_cancel, $staff['id'], $package_id]);
                $success = 'Days to cancel updated successfully!';
                $active_tab = 'contracts';
            } catch (PDOException $e) {
                $error = 'Failed to update days to cancel: ' . $e->getMessage();
            }
        }
    } elseif (isset($_POST['upload_contract'])) {
        $package_id = intval($_POST['package_id'] ?? 0);
        $contract_type = $_POST['contract_type'] ?? '';

        if ($package_id <= 0 || empty($contract_type)) {
            $error = 'Invalid package or contract type.';
        } elseif (!isset($_FILES['contract_pdf']) || $_FILES['contract_pdf']['error'] !== UPLOAD_ERR_OK) {
            $error = 'Please select a valid PDF file.';
        } else {
            try {
                $file = $_FILES['contract_pdf'];

                // Validate PDF
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime_type = finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);

                if ($mime_type !== 'application/pdf') {
                    throw new Exception('Only PDF files are allowed.');
                }

                // Read PDF content
                $pdf_content = file_get_contents($file['tmp_name']);
                $pdf_hash = hash('sha256', $pdf_content);

                // Check if contract type already exists for this package
                $stmt = $pdo->prepare("SELECT id FROM state_contract_documents WHERE package_id = ? AND contract_type = ?");
                $stmt->execute([$package_id, $contract_type]);
                $existing = $stmt->fetch();

                if ($existing) {
                    // Update existing
                    $stmt = $pdo->prepare("UPDATE state_contract_documents SET contract_pdf = ?, file_name = ?, file_size = ?, mime_type = ?, pdf_hash = ?, uploaded_at = NOW(), uploaded_by = ? WHERE id = ?");
                    $stmt->execute([$pdf_content, $file['name'], $file['size'], $mime_type, $pdf_hash, $staff['id'], $existing['id']]);
                    $success = "Contract '{$contract_type}' updated for package #{$package_id} (document #{$existing['id']})";
                } else {
                    // Insert new
                    $stmt = $pdo->prepare("INSERT INTO state_contract_documents (package_id, contract_type, contract_pdf, file_name, file_size, mime_type, pdf_hash, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$package_id, $contract_type, $pdf_content, $file['name'], $file['size'], $mime_type, $pdf_hash, $staff['id']]);
                    $new_id = $pdo->lastInsertId();
                    $success = "Contract '{$contract_type}' uploaded to package #{$package_id} (new document #{$new_id})";
                }

                $active_tab = 'contracts';
            } catch (Exception $e) {
                $error = 'Failed to upload contract: ' . $e->getMessage();
            }
        }
    } elseif (isset($_POST['update_package_states'])) {
        $package_id = intval($_POST['package_id'] ?? 0);
        $selected_states = $_POST['states'] ?? [];

        if ($package_id <= 0) {
            $error = 'Invalid package ID.';
        } else {
            try {
                $pdo->beginTransaction();

                // Delete existing mappings
                $stmt = $pdo->prepare("DELETE FROM state_contract_mappings WHERE package_id = ?");
                $stmt->execute([$package_id]);

                // Insert new mappings
                if (!empty($selected_states)) {
                    $stmt = $pdo->prepare("INSERT INTO state_contract_mappings (package_id, state_code, created_by) VALUES (?, ?, ?)");
                    foreach ($selected_states as $state) {
                        $stmt->execute([$package_id, $state, $staff['id']]);
                    }
                }

                $pdo->commit();
                $success = 'State assignments updated successfully!';
                $active_tab = 'contracts';
            } catch (PDOException $e) {
                $pdo->rollBack();
                $error = 'Failed to update states: ' . $e->getMessage();
            }
        }
    } elseif (isset($_POST['set_default_package'])) {
        $package_id = intval($_POST['package_id'] ?? 0);

        try {
            $pdo->beginTransaction();

            // Unset all defaults
            $stmt = $pdo->prepare("UPDATE state_contract_packages SET is_default = 0");
            $stmt->execute();

            // Set new default
            $stmt = $pdo->prepare("UPDATE state_contract_packages SET is_default = 1, updated_by = ? WHERE id = ?");
            $stmt->execute([$staff['id'], $package_id]);

            $pdo->commit();
            $success = 'Default package updated successfully!';
            $active_tab = 'contracts';
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = 'Failed to set default package: ' . $e->getMessage();
        }
    } elseif (isset($_POST['delete_contract_package'])) {
        $package_id = intval($_POST['package_id'] ?? 0);

        try {
            // Check if it's the default package
            $stmt = $pdo->prepare("SELECT is_default FROM state_contract_packages WHERE id = ?");
            $stmt->execute([$package_id]);
            $package = $stmt->fetch();

            if ($package && $package['is_default']) {
                $error = 'Cannot delete the default package. Please set another package as default first.';
            } else {
                $stmt = $pdo->prepare("DELETE FROM state_contract_packages WHERE id = ?");
                $stmt->execute([$package_id]);
                $success = 'Package deleted successfully!';
                $active_tab = 'contracts';
            }
        } catch (PDOException $e) {
            $error = 'Failed to delete package: ' . $e->getMessage();
        }
    } elseif (isset($_POST['upload_countersign']) || isset($_POST['save_countersign_canvas'])) {
        $package_id = intval($_POST['package_id'] ?? 0);

        if ($package_id <= 0) {
            $error = 'Invalid package ID.';
        } else {
            try {
                $signature_data = null;
                $filename = null;

                // Handle file upload
                if (isset($_POST['upload_countersign']) && isset($_FILES['countersign_image']) && $_FILES['countersign_image']['error'] === UPLOAD_ERR_OK) {
                    $file = $_FILES['countersign_image'];

                    // Validate image
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mime_type = finfo_file($finfo, $file['tmp_name']);
                    finfo_close($finfo);

                    if (!in_array($mime_type, ['image/png', 'image/jpeg', 'image/jpg'])) {
                        throw new Exception('Only PNG and JPEG images are allowed.');
                    }

                    // Read image content
                    $signature_data = file_get_contents($file['tmp_name']);
                    $filename = $file['name'];
                }
                // Handle canvas drawing
                elseif (isset($_POST['save_countersign_canvas']) && !empty($_POST['signature_data'])) {
                    $signature_base64 = $_POST['signature_data'];

                    // Validate data URL format
                    if (preg_match('/^data:image\/png;base64,(.+)$/', $signature_base64, $matches)) {
                        $signature_data = base64_decode($matches[1]);
                        $filename = 'countersign_' . date('Y-m-d_H-i-s') . '.png';
                    } else {
                        throw new Exception('Invalid signature data format.');
                    }
                } else {
                    throw new Exception('No signature data provided.');
                }

                // Update package with countersign signature
                $stmt = $pdo->prepare("UPDATE state_contract_packages SET
                    countersign_signature = ?,
                    countersign_filename = ?,
                    countersign_uploaded_at = NOW(),
                    countersign_uploaded_by = ?
                    WHERE id = ?");
                $stmt->execute([$signature_data, $filename, $staff['id'], $package_id]);

                $success = 'Countersign signature saved successfully!';
                $active_tab = 'contracts';
            } catch (Exception $e) {
                $error = 'Failed to save countersign signature: ' . $e->getMessage();
            }
        }
    } elseif (isset($_POST['delete_countersign'])) {
        $package_id = intval($_POST['package_id'] ?? 0);

        if ($package_id <= 0) {
            $error = 'Invalid package ID.';
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE state_contract_packages SET
                    countersign_signature = NULL,
                    countersign_filename = NULL,
                    countersign_uploaded_at = NULL,
                    countersign_uploaded_by = NULL
                    WHERE id = ?");
                $stmt->execute([$package_id]);

                $success = 'Countersign signature removed successfully!';
                $active_tab = 'contracts';
            } catch (PDOException $e) {
                $error = 'Failed to remove countersign signature: ' . $e->getMessage();
            }
        }
    } elseif (isset($_POST['update_client_id'])) {
        $package_id = intval($_POST['package_id'] ?? 0);
        $client_id = trim($_POST['xactosign_client_id'] ?? '');

        if ($package_id <= 0) {
            $error = 'Invalid package ID.';
        } else {
            try {
                // Validate client ID (alphanumeric, dash, underscore only)
                if (!empty($client_id) && !preg_match('/^[A-Za-z0-9_-]+$/', $client_id)) {
                    throw new Exception('Client ID can only contain letters, numbers, dashes, and underscores.');
                }

                $stmt = $pdo->prepare("UPDATE state_contract_packages SET
                    xactosign_client_id = ?
                    WHERE id = ?");
                $stmt->execute([empty($client_id) ? null : strtoupper($client_id), $package_id]);

                $success = 'XactoSign Client ID updated successfully!';
                $active_tab = 'contracts';
            } catch (Exception $e) {
                $error = 'Failed to update Client ID: ' . $e->getMessage();
            }
        }
    } elseif (isset($_POST['update_sig_coords'])) {
        $doc_id = intval($_POST['doc_id'] ?? 0);

        if ($doc_id <= 0) {
            $error = 'Invalid document ID.';
        } else {
            try {
                // Build signature coordinates array from form data
                $coords = [];
                $sig_count = intval($_POST['sig_count'] ?? 0);

                for ($i = 0; $i < $sig_count; $i++) {
                    $coords[] = [
                        'signature_type' => sanitize_input($_POST["sig_{$i}_type"] ?? ''),
                        'label' => sanitize_input($_POST["sig_{$i}_label"] ?? ''),
                        'page' => $_POST["sig_{$i}_page"] ?? 'last',
                        'x1' => floatval($_POST["sig_{$i}_x1"] ?? 0),
                        'y1' => floatval($_POST["sig_{$i}_y1"] ?? 0),
                        'x2' => floatval($_POST["sig_{$i}_x2"] ?? 0),
                        'y2' => floatval($_POST["sig_{$i}_y2"] ?? 0)
                    ];
                }

                $coords_json = json_encode($coords);

                $stmt = $pdo->prepare("UPDATE state_contract_documents SET signature_coords = ? WHERE id = ?");
                $stmt->execute([$coords_json, $doc_id]);

                $success = 'Signature coordinates updated successfully!';
                $active_tab = 'contracts';
            } catch (Exception $e) {
                $error = 'Failed to update signature coordinates: ' . $e->getMessage();
            }
        }
    } elseif (isset($_POST['save_template'])) {
        $template_id = intval($_POST['template_id'] ?? 0);
        $subject = isset($_POST['subject']) ? sanitize_input($_POST['subject']) : null;
        $content = $_POST['content'] ?? '';
        $return_tab = $_POST['return_tab'] ?? 'sms';

        try {
            if ($_POST['template_type'] === 'email') {
                $stmt = $pdo->prepare("UPDATE communication_templates SET subject = ?, content = ?, updated_by = ? WHERE id = ?");
                $stmt->execute([$subject, $content, $staff['id'], $template_id]);
            } else {
                $stmt = $pdo->prepare("UPDATE communication_templates SET content = ?, updated_by = ? WHERE id = ?");
                $stmt->execute([$content, $staff['id'], $template_id]);
            }
            $success = 'Template saved successfully!';
            $active_tab = 'templates';
            $_GET['subtype'] = $return_tab;
        } catch (PDOException $e) {
            $error = 'Failed to save template: ' . $e->getMessage();
            $active_tab = 'templates';
        }
    } elseif (isset($_POST['send_test_template'])) {
        require_once __DIR__ . '/../src/email_helper.php';

        $template_id = intval($_POST['template_id'] ?? 0);
        $template_type = $_POST['template_type'] ?? '';
        $test_destination = sanitize_input($_POST['test_destination'] ?? '');

        // Get template
        $stmt = $pdo->prepare("SELECT * FROM communication_templates WHERE id = ?");
        $stmt->execute([$template_id]);
        $template = $stmt->fetch();

        if (!$template) {
            $error = 'Template not found.';
        } else {
            // Get placeholder values from POST
            $placeholders = [
                'client_name', 'client_spouse_name', 'plan_name', 'brand_name', 'brand_logo', 'client_phone', 'client_email',
                'client_spouse_phone', 'client_spouse_email', 'affiliate_name', 'affiliate_brand', 'affiliate_email',
                'affiliate_phone', 'enrollment_url', 'brand_url', 'login_url', 'docs_url', 'spouse_url'
            ];

            $content = $template['content'];
            $subject = $template['subject'] ?? '';

            // Replace placeholders
            foreach ($placeholders as $ph) {
                $value = $_POST['placeholder_' . $ph] ?? '[' . $ph . ']';

                // Special handling for brand_logo - convert URL to img tag for emails
                if ($ph === 'brand_logo' && $value !== '[brand_logo]' && $template_type === 'email') {
                    $value = '<img src="' . htmlspecialchars($value) . '" alt="' . htmlspecialchars($brand_name) . '" style="max-width: 250px; height: auto;">';
                }

                $content = str_replace('[' . $ph . ']', $value, $content);
                $subject = str_replace('[' . $ph . ']', $value, $subject);
            }

            if ($template_type === 'email') {
                $result = send_email_via_mailersend($test_destination, 'Test Recipient', $subject, $content);
                if ($result['success']) {
                    $success = 'Test email sent successfully!';
                } else {
                    $error = 'Failed to send test email: ' . ($result['error'] ?? 'Unknown error');
                }
            }
            // Note: SMS is handled via AJAX to /src/outbound_sms.php (see handleTestFormSubmit JavaScript function)

            $active_tab = 'templates';
            $_GET['subtype'] = $template_type;
        }
    }
}

// Fetch settings - reorganize into array by category and key
$stmt = $pdo->query("SELECT category, setting_key, setting_value FROM settings ORDER BY category, setting_key");
$settings_raw = $stmt->fetchAll();
$all_settings = [];
foreach ($settings_raw as $setting) {
    $all_settings[$setting['category']][$setting['setting_key']]['setting_value'] = $setting['setting_value'];
}

// Fetch plans
$stmt = $pdo->query("SELECT * FROM plans ORDER BY display_order LIMIT 2");
$plans = $stmt->fetchAll();

// Fetch questions
$stmt = $pdo->query("SELECT * FROM enrollment_questions ORDER BY display_order");
$questions = $stmt->fetchAll();

// Fetch API keys
$stmt = $pdo->query("SELECT * FROM api_keys ORDER BY service_name");
$api_keys = [];
while ($row = $stmt->fetch()) {
    $api_keys[$row['service_name']] = $row;
}

// Fetch contract packages with documents and state mappings
$stmt = $pdo->query("SELECT * FROM state_contract_packages ORDER BY is_default DESC, package_name");
$contract_packages = $stmt->fetchAll();

// For each package, fetch documents and states
foreach ($contract_packages as &$package) {
    // Fetch documents
    $stmt = $pdo->prepare("SELECT id, contract_type, file_name, file_size, uploaded_at, signature_coords FROM state_contract_documents WHERE package_id = ?");
    $stmt->execute([$package['id']]);
    $package['documents'] = [];
    while ($doc = $stmt->fetch()) {
        $package['documents'][$doc['contract_type']] = $doc;
    }

    // Fetch assigned states
    $stmt = $pdo->prepare("SELECT state_code FROM state_contract_mappings WHERE package_id = ? ORDER BY state_code");
    $stmt->execute([$package['id']]);
    $package['states'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
}
unset($package); // IMPORTANT: Break the reference to avoid issues with later foreach loops

// US States list
$us_states = [
    'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas', 'CA' => 'California',
    'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware', 'FL' => 'Florida', 'GA' => 'Georgia',
    'HI' => 'Hawaii', 'ID' => 'Idaho', 'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa',
    'KS' => 'Kansas', 'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine', 'MD' => 'Maryland',
    'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota', 'MS' => 'Mississippi', 'MO' => 'Missouri',
    'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada', 'NH' => 'New Hampshire', 'NJ' => 'New Jersey',
    'NM' => 'New Mexico', 'NY' => 'New York', 'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio',
    'OK' => 'Oklahoma', 'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island', 'SC' => 'South Carolina',
    'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah', 'VT' => 'Vermont',
    'VA' => 'Virginia', 'WA' => 'Washington', 'WV' => 'West Virginia', 'WI' => 'Wisconsin', 'WY' => 'Wyoming',
    'DC' => 'District of Columbia', 'PR' => 'Puerto Rico'
];

$page_title = 'Settings';
include __DIR__ . '/../src/header.php';
?>

<style>
.settings-tabs {
    display: flex;
    gap: var(--spacing-sm);
    border-bottom: 2px solid #ddd;
    margin-bottom: var(--spacing-lg);
    overflow-x: auto;
}
.settings-tabs a {
    padding: var(--spacing-sm) var(--spacing-md);
    text-decoration: none;
    color: #666;
    border-bottom: 3px solid transparent;
    margin-bottom: -2px;
    white-space: nowrap;
    transition: all var(--transition-speed);
    font-weight: 500;
}
.settings-tabs a:hover {
    color: var(--color-primary);
}
.settings-tabs a.active {
    color: var(--color-primary);
    border-bottom-color: var(--color-primary);
    font-weight: 600;
}
</style>

<div class="admin-container">
    <div class="admin-header">
        <h1>Settings</h1>
        <div class="admin-user-info">
            <span>Welcome, <?php echo htmlspecialchars($staff['full_name']); ?></span>
            <a href="logout.php" class="btn btn-secondary">Logout</a>
        </div>
    </div>

    <div class="admin-nav">
        <a href="panel.php">Dashboard</a>
        <a href="settings.php" class="active">Settings</a>
        <a href="staff.php">Staff</a>
        <a href="aff_details.php">Affiliates</a>
    </div>

    <?php if (!empty($success)): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="settings-tabs">
        <a href="?tab=general" class="<?php echo $active_tab === 'general' ? 'active' : ''; ?>">General</a>
        <a href="?tab=plans" class="<?php echo $active_tab === 'plans' ? 'active' : ''; ?>">Plans</a>
        <a href="?tab=api" class="<?php echo $active_tab === 'api' ? 'active' : ''; ?>">API Keys</a>
        <a href="?tab=xactoauth" class="<?php echo $active_tab === 'xactoauth' ? 'active' : ''; ?>">XactoAuth</a>
        <a href="?tab=zapier" class="<?php echo $active_tab === 'zapier' ? 'active' : ''; ?>">Zapier Settings</a>
        <a href="?tab=questions" class="<?php echo $active_tab === 'questions' ? 'active' : ''; ?>">Questions</a>
        <a href="?tab=contracts" class="<?php echo $active_tab === 'contracts' ? 'active' : ''; ?>">State Contracts</a>
        <a href="?tab=templates" class="<?php echo $active_tab === 'templates' ? 'active' : ''; ?>">Communication Templates</a>
    </div>

    <?php if ($active_tab === 'general'): ?>
        <div class="card">
            <div class="card-header"><h2 class="card-title">General Settings</h2></div>
            <form method="POST">
                <input type="hidden" name="save_general" value="1">
                <div class="form-group">
                    <label for="company_name" class="form-label required">Company Name</label>
                    <input type="text" id="company_name" name="company_name" class="form-control"
                           value="<?php echo htmlspecialchars($all_settings['general']['company_name']['setting_value'] ?? ''); ?>" required>
                </div>
                <div class="form-group">
                    <label for="brand_logo_url" class="form-label">Brand Logo URL</label>
                    <input type="url" id="brand_logo_url" name="brand_logo_url" class="form-control"
                           value="<?php echo htmlspecialchars($all_settings['general']['brand_logo_url']['setting_value'] ?? ''); ?>" placeholder="https://example.com/logo.png">
                    <small style="color: #666; font-size: 13px; margin-top: 0.25rem; display: block;">Full URL to your company logo image (used in emails and documents)</small>
                </div>
                <div class="form-group">
                    <label for="profile_picture_url" class="form-label">Profile Picture URL</label>
                    <input type="url" id="profile_picture_url" name="profile_picture_url" class="form-control"
                           value="<?php echo htmlspecialchars($all_settings['general']['profile_picture_url']['setting_value'] ?? ''); ?>" placeholder="https://example.com/profile.png">
                    <small style="color: #666; font-size: 13px; margin-top: 0.25rem; display: block;">Full URL to profile picture/avatar image</small>
                </div>

                <h3 style="color: var(--color-primary); margin: var(--spacing-lg) 0 var(--spacing-md) 0; padding-top: var(--spacing-lg); border-top: 2px solid var(--color-light-2);">Brand Colors</h3>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: var(--spacing-md);">
                    <div class="form-group">
                        <label for="color_primary" class="form-label">Primary Color</label>
                        <input type="color" id="color_primary" name="color_primary" class="form-control" style="height: 50px;"
                               value="<?php echo htmlspecialchars($all_settings['branding']['color_primary']['setting_value'] ?? '#9c6046'); ?>">
                    </div>
                    <div class="form-group">
                        <label for="color_secondary" class="form-label">Secondary Color</label>
                        <input type="color" id="color_secondary" name="color_secondary" class="form-control" style="height: 50px;"
                               value="<?php echo htmlspecialchars($all_settings['branding']['color_secondary']['setting_value'] ?? '#b7bbac'); ?>">
                    </div>
                    <div class="form-group">
                        <label for="color_accent" class="form-label">Accent Color</label>
                        <input type="color" id="color_accent" name="color_accent" class="form-control" style="height: 50px;"
                               value="<?php echo htmlspecialchars($all_settings['branding']['color_accent']['setting_value'] ?? '#a2a698'); ?>">
                    </div>
                    <div class="form-group">
                        <label for="color_light_1" class="form-label">Light Color 1</label>
                        <input type="color" id="color_light_1" name="color_light_1" class="form-control" style="height: 50px;"
                               value="<?php echo htmlspecialchars($all_settings['branding']['color_light_1']['setting_value'] ?? '#dcd8d4'); ?>">
                    </div>
                    <div class="form-group">
                        <label for="color_light_2" class="form-label">Light Color 2</label>
                        <input type="color" id="color_light_2" name="color_light_2" class="form-control" style="height: 50px;"
                               value="<?php echo htmlspecialchars($all_settings['branding']['color_light_2']['setting_value'] ?? '#dbc9bf'); ?>">
                    </div>
                    <div class="form-group">
                        <label for="color_header_text" class="form-label">Header Text Color</label>
                        <input type="color" id="color_header_text" name="color_header_text" class="form-control" style="height: 50px;"
                               value="<?php echo htmlspecialchars($all_settings['branding']['color_header_text']['setting_value'] ?? '#ffffff'); ?>">
                        <small style="color: #666; font-size: 13px; margin-top: 0.25rem; display: block;">Color of text and logo in the header. Use white (#ffffff) for dark headers, or dark colors for light headers.</small>
                    </div>
                </div>

                <div class="form-group">
                    <label for="admin_email" class="form-label required">Admin Email Address</label>
                    <input type="email" id="admin_email" name="admin_email" class="form-control"
                           value="<?php echo htmlspecialchars($all_settings['general']['admin_email']['setting_value'] ?? ''); ?>" required>
                    <small style="color: #666; font-size: 13px; margin-top: 0.25rem; display: block;">This email will be shown to visitors when enrollments are disabled</small>
                </div>
                <div class="form-group">
                    <label><input type="checkbox" name="enrollments_enabled" <?php echo ($all_settings['enrollment']['enrollments_enabled']['setting_value'] ?? 'false') === 'true' ? 'checked' : ''; ?>> Enable New Enrollments</label>
                </div>
                <div class="form-group">
                    <label><input type="checkbox" name="enrollment_questions_enabled" <?php echo ($all_settings['enrollment']['enrollment_questions_enabled']['setting_value'] ?? 'false') === 'true' ? 'checked' : ''; ?>> Enable Enrollment Questions</label>
                </div>
                <div class="form-group">
                    <label for="step_timeout_minutes" class="form-label required">Step Timeout (minutes)</label>
                    <input type="number" id="step_timeout_minutes" name="step_timeout_minutes" class="form-control"
                           value="<?php echo htmlspecialchars($all_settings['notifications']['step_timeout_minutes']['setting_value'] ?? 5); ?>" min="1" max="60" required>
                </div>
                <div class="form-group">
                    <label for="session_timeout_hours" class="form-label required">Session Timeout (hours)</label>
                    <input type="number" id="session_timeout_hours" name="session_timeout_hours" class="form-control"
                           value="<?php echo htmlspecialchars($all_settings['enrollment']['session_timeout_hours']['setting_value'] ?? 72); ?>" min="1" max="168" required>
                </div>
                <button type="submit" class="btn btn-primary">Save Settings</button>
            </form>
        </div>

        <?php if (function_exists('render_test_certificate_ui')) { render_test_certificate_ui(); } ?>
    <?php endif; ?>

    <?php if ($active_tab === 'plans'): ?>
        <div class="card">
            <div class="card-header"><h2 class="card-title">Plans & Pricing</h2></div>
            <form method="POST" action="?tab=plans">
                <input type="hidden" name="save_plans" value="1">
                <?php foreach ($plans as $plan): ?>
                    <div style="border: 1px solid var(--color-light-2); padding: var(--spacing-lg); margin-bottom: var(--spacing-md); border-radius: var(--border-radius);">
                        <h3 style="color: var(--color-primary); margin-bottom: var(--spacing-md);"><?php echo htmlspecialchars($plan['plan_name']); ?></h3>
                        <div class="form-group">
                            <label class="form-label required">Initial Work Fee</label>
                            <input type="number" name="<?php echo $plan['plan_type']; ?>_initial_fee" class="form-control" step="0.01" min="0" value="<?php echo htmlspecialchars($plan['initial_work_fee']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label required">Monthly Fee</label>
                            <input type="number" name="<?php echo $plan['plan_type']; ?>_monthly_fee" class="form-control" step="0.01" min="0" value="<?php echo htmlspecialchars($plan['monthly_fee']); ?>" required>
                        </div>
                    </div>
                <?php endforeach; ?>
                <button type="submit" class="btn btn-primary">Save Pricing</button>
            </form>
        </div>
    <?php endif; ?>

    <?php if ($active_tab === 'api'): ?>
        <?php
        $primary_services = [
            'credit_repair_cloud' => ['name' => 'Credit Repair Cloud', 'fields' => ['auth_key' => 'Auth Key', 'secret_key' => 'Secret Key']],
            'zoho_books' => ['name' => 'Zoho Books', 'fields' => ['client_id' => 'Client ID', 'client_secret' => 'Client Secret', 'refresh_token' => 'Refresh Token', 'organization_id' => 'Organization ID']],
            'systeme_io' => ['name' => 'Systeme.io', 'fields' => ['api_key' => 'API Key']],
            'google_analytics' => ['name' => 'Google Analytics', 'fields' => ['tracking_id' => 'Tracking ID']]
        ];

        $backend_services = [
            'google_maps' => ['name' => 'Google Maps', 'fields' => ['api_key' => 'API Key']],
            'recaptcha' => ['name' => 'reCAPTCHA Enterprise', 'fields' => ['site_key' => 'Site Key', 'secret_key' => 'Secret Key', 'project_id' => 'Project ID']],
            'voipms' => ['name' => 'VoIP.ms (SMS)', 'fields' => ['username' => 'API Username (Email)', 'password' => 'API Password', 'did' => 'Phone Number (DID)']],
            'mailersend' => ['name' => 'MailerSend (Email)', 'fields' => ['api_token' => 'API Token', 'from_email' => 'From Email']]
        ];
        ?>

        <!-- Primary Integrations -->
        <?php foreach ($primary_services as $service_key => $service_info): ?>
            <?php
            $service_data = $api_keys[$service_key] ?? null;
            $is_enabled = $service_data && $service_data['is_enabled'];
            $config = $service_data ? json_decode($service_data['additional_config'], true) : [];
            ?>
            <div class="card" style="margin-bottom: var(--spacing-md);">
                <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                    <h3 style="margin:0;color:var(--color-primary);"><?php echo $service_info['name']; ?></h3>
                    <span class="badge badge-<?php echo $is_enabled ? 'success' : 'danger'; ?>">
                        <?php echo $is_enabled ? 'Enabled' : 'Disabled'; ?>
                    </span>
                </div>
                <form method="POST" action="?tab=api" style="padding:var(--spacing-lg);">
                    <input type="hidden" name="save_api_key" value="1">
                    <input type="hidden" name="service" value="<?php echo $service_key; ?>">

                    <div class="form-group">
                        <label><input type="checkbox" name="is_enabled" <?php echo $is_enabled ? 'checked' : ''; ?>> Enable this integration</label>
                    </div>

                    <?php foreach ($service_info['fields'] as $field_key => $field_label): ?>
                        <?php $is_password_field = strpos($field_key, 'password') !== false || strpos($field_key, 'secret') !== false || strpos($field_key, 'token') !== false; ?>
                        <div class="form-group">
                            <label class="form-label"><?php echo $field_label; ?></label>
                            <div style="position: relative;">
                                <input type="<?php echo $is_password_field ? 'password' : 'text'; ?>"
                                       name="<?php echo $field_key; ?>"
                                       class="form-control<?php echo $is_password_field ? ' password-field' : ''; ?>"
                                       value="<?php echo htmlspecialchars($config[$field_key] ?? ''); ?>"
                                       placeholder="<?php echo $field_label; ?>"
                                       style="<?php echo $is_password_field ? 'padding-right: 45px;' : ''; ?>">
                                <?php if ($is_password_field): ?>
                                <button type="button" class="toggle-password" onclick="togglePassword(this)" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: var(--color-primary); font-size: 18px; padding: 5px;">
                                    👁️
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <button type="submit" class="btn btn-primary">Save <?php echo $service_info['name']; ?> Settings</button>

                    <?php if ($service_key === 'credit_repair_cloud' && $is_enabled): ?>
                        <hr style="margin: var(--spacing-lg) 0;">
                        <h4 style="margin-bottom: var(--spacing-md); color: var(--color-primary);">API Testing</h4>

                        <div style="display: flex; gap: var(--spacing-sm); margin-top: var(--spacing-md); flex-wrap: wrap;">
                            <button type="button" class="btn btn-secondary" onclick="testCrcApi()">Test API Connection</button>
                            <button type="button" class="btn btn-secondary" onclick="openMemoTestModal()" id="memoTestBtn" style="display: none;">Test Memo Field</button>
                        </div>

                        <div id="crc-test-result" style="margin-top: var(--spacing-md); display: none;"></div>

                        <!-- Memo Test Modal -->
                        <div id="memoTestModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000; align-items: center; justify-content: center;">
                            <div style="background: white; border-radius: 16px; padding: 2rem; max-width: 600px; width: 90%; max-height: 80vh; overflow-y: auto;">
                                <h3 style="margin: 0 0 1rem; color: var(--color-primary);">Test Memo & Custom Fields</h3>
                                <p style="color: #666; font-size: 14px; margin-bottom: 1.5rem;">This will update the test lead's memo field. You can use this to experiment with what data to include.</p>

                                <div class="form-group">
                                    <label class="form-label">Record ID</label>
                                    <input type="text" id="memo_record_id" class="form-control" readonly style="background: #f3f4f6;">
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Memo Text (leave empty for default test memo)</label>
                                    <textarea id="memo_text" class="form-control" rows="8" placeholder="Enrollment ID: TEST-123&#10;Package: Individual Plan&#10;Referral: TESTREF&#10;Notes: Custom information here..."></textarea>
                                    <small style="color: #666; font-size: 13px; margin-top: 0.25rem; display: block;">
                                        You can include enrollment details, package info, referral data, or any notes you want visible in CRC.
                                    </small>
                                </div>

                                <div id="memoTestResult" style="margin-top: 1rem;"></div>

                                <div style="display: flex; gap: 0.5rem; margin-top: 1.5rem; justify-content: flex-end;">
                                    <button type="button" class="btn btn-secondary" onclick="closeMemoTestModal()">Cancel</button>
                                    <button type="button" class="btn btn-primary" onclick="submitMemoTest()">Send Test Memo</button>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($service_key === 'zoho_books' && $is_enabled): ?>
                        <hr style="margin: var(--spacing-lg) 0;">
                        <h4 style="margin-bottom: var(--spacing-md); color: var(--color-primary);">Setup Instructions</h4>

                        <div style="background: #e0f2fe; border: 1px solid #0284c7; border-radius: 8px; padding: 1rem; margin-bottom: var(--spacing-md);">
                            <p style="margin: 0 0 0.5rem 0; color: #075985; font-size: 14px; font-weight: 600;">
                                How to get your Zoho Books OAuth credentials:
                            </p>
                            <ol style="margin: 0.5rem 0 0 1.5rem; padding: 0; color: #075985; font-size: 13px; line-height: 1.6;">
                                <li>Go to <a href="https://api-console.zoho.com/" target="_blank" style="color: #0284c7; text-decoration: underline;">Zoho API Console</a></li>
                                <li>Click "Add Client" → Select "Server-based Applications"</li>
                                <li>Fill in:
                                    <ul style="margin: 0.25rem 0 0 1rem; list-style-type: disc;">
                                        <li><strong>Client Name:</strong> SNS Enrollment System</li>
                                        <li><strong>Homepage URL:</strong> <?php echo htmlspecialchars(BASE_URL); ?></li>
                                        <li><strong>Authorized Redirect URI:</strong> <code style="background: #f0f9ff; padding: 2px 6px; border-radius: 3px; font-size: 12px;"><?php echo htmlspecialchars(BASE_URL . '/admin/zoho_oauth_callback.php'); ?></code></li>
                                    </ul>
                                </li>
                                <li>Click "Create" and copy your Client ID and Client Secret</li>
                                <li>Paste them in the fields above and click "Save Zoho Books Settings"</li>
                                <li>Then click the "Authorize with Zoho" button below</li>
                            </ol>
                        </div>

                        <h4 style="margin: var(--spacing-lg) 0 var(--spacing-md); color: var(--color-primary);">OAuth Authorization</h4>

                        <?php
                        // Build OAuth authorization URL
                        $zoho_client_id = $config['client_id'] ?? '';
                        $has_refresh_token = !empty($config['refresh_token']);

                        $redirect_uri = BASE_URL . '/admin/zoho_oauth_callback.php';
                        $scope = 'ZohoBooks.settings.READ,ZohoBooks.contacts.CREATE,ZohoBooks.contacts.READ,ZohoBooks.contacts.UPDATE';
                        $auth_url = 'https://accounts.zoho.com/oauth/v2/auth?' . http_build_query([
                            'scope' => $scope,
                            'client_id' => $zoho_client_id,
                            'response_type' => 'code',
                            'redirect_uri' => $redirect_uri,
                            'access_type' => 'offline',
                            'prompt' => 'consent'
                        ]);
                        ?>

                        <div style="display: flex; gap: var(--spacing-sm); align-items: center; flex-wrap: wrap;">
                            <?php if (!empty($zoho_client_id)): ?>
                                <a href="<?php echo htmlspecialchars($auth_url); ?>" class="btn btn-primary" target="_blank">
                                    <?php echo $has_refresh_token ? 'Re-authorize with Zoho' : 'Authorize with Zoho'; ?>
                                </a>
                                <?php if ($has_refresh_token): ?>
                                    <span style="color: #059669; font-size: 14px; display: flex; align-items: center; gap: 0.5rem;">
                                        <svg width="20" height="20" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                        </svg>
                                        Authorized
                                    </span>
                                <?php endif; ?>
                            <?php else: ?>
                                <button type="button" class="btn btn-primary" disabled title="Save Client ID and Client Secret first">
                                    Authorize with Zoho
                                </button>
                                <span style="color: #dc2626; font-size: 14px;">Please save Client ID and Client Secret first</span>
                            <?php endif; ?>
                        </div>

                        <?php
                        $has_org_id = !empty($config['organization_id']);
                        if ($has_refresh_token && !$has_org_id):
                        ?>
                            <div style="background: #fef3c7; border: 1px solid #f59e0b; border-radius: 8px; padding: 1rem; margin-top: var(--spacing-md);">
                                <p style="margin: 0 0 0.5rem 0; color: #92400e; font-size: 14px; font-weight: 600;">
                                    Organization ID Required
                                </p>
                                <p style="margin: 0 0 0.5rem 0; color: #92400e; font-size: 13px;">
                                    The Organization ID could not be fetched automatically. Please enter it manually in the field above.
                                </p>
                                <p style="margin: 0; color: #92400e; font-size: 13px;">
                                    To find your Organization ID: Log into <a href="https://books.zoho.com/" target="_blank" style="color: #0284c7; text-decoration: underline;">Zoho Books</a>,
                                    go to Settings → Organization Profile, and look for your Organization ID.
                                </p>
                            </div>
                        <?php endif; ?>

                        <?php if ($has_refresh_token && $has_org_id): ?>
                            <hr style="margin: var(--spacing-lg) 0;">
                            <h4 style="margin-bottom: var(--spacing-md); color: var(--color-primary);">API Testing</h4>

                            <div style="display: flex; gap: var(--spacing-sm); margin-top: var(--spacing-md); flex-wrap: wrap;">
                                <button type="button" class="btn btn-secondary" onclick="testZohoApi()">Test Zoho Books</button>
                            </div>

                            <div id="zoho-test-result" style="margin-top: var(--spacing-md); display: none;"></div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($service_key === 'systeme_io' && $is_enabled): ?>
                        <hr style="margin: var(--spacing-lg) 0;">
                        <h4 style="margin-bottom: var(--spacing-md); color: var(--color-primary);">API Testing</h4>

                        <div style="display: flex; gap: var(--spacing-sm); margin-top: var(--spacing-md); flex-wrap: wrap;">
                            <button type="button" class="btn btn-secondary" onclick="testSystemeApi()">Test Systeme.io</button>
                            <button type="button" class="btn btn-secondary" onclick="openSystemeUpdateModal()" id="systemeUpdateBtn" style="display: none;">Update Contact (Test)</button>
                        </div>

                        <div id="systeme-test-result" style="margin-top: var(--spacing-md); display: none;"></div>

                        <!-- Systeme.io Update Test Modal -->
                        <div id="systemeUpdateModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000; align-items: center; justify-content: center;">
                            <div style="background: white; border-radius: 16px; padding: 2rem; max-width: 600px; width: 90%; max-height: 80vh; overflow-y: auto;">
                                <h3 style="margin: 0 0 1rem; color: var(--color-primary);">Test Contact Update</h3>
                                <p style="color: #666; font-size: 14px; margin-bottom: 1.5rem;">Update the test contact with new information to verify the update API works correctly.</p>

                                <div class="form-group">
                                    <label class="form-label">Contact ID</label>
                                    <input type="text" id="systeme_contact_id" class="form-control" readonly style="background: #f3f4f6;">
                                </div>

                                <div class="form-group">
                                    <label class="form-label">First Name</label>
                                    <input type="text" id="systeme_first_name" class="form-control" placeholder="Updated First Name">
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Last Name</label>
                                    <input type="text" id="systeme_last_name" class="form-control" placeholder="Updated Last Name">
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Phone</label>
                                    <input type="text" id="systeme_phone" class="form-control" placeholder="555-555-5555">
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Street Address</label>
                                    <input type="text" id="systeme_address" class="form-control" placeholder="456 Updated Street">
                                </div>

                                <div class="form-group">
                                    <label class="form-label">City</label>
                                    <input type="text" id="systeme_city" class="form-control" placeholder="Dallas">
                                </div>

                                <div class="form-group">
                                    <label class="form-label">State</label>
                                    <input type="text" id="systeme_state" class="form-control" placeholder="TX" maxlength="2">
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Zip Code</label>
                                    <input type="text" id="systeme_zip" class="form-control" placeholder="75201">
                                </div>

                                <div id="systemeUpdateResult" style="margin-top: 1rem;"></div>

                                <div style="display: flex; gap: 0.5rem; margin-top: 1.5rem; justify-content: flex-end;">
                                    <button type="button" class="btn btn-secondary" onclick="closeSystemeUpdateModal()">Cancel</button>
                                    <button type="button" class="btn btn-primary" onclick="submitSystemeUpdate()">Update Contact</button>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($service_key === 'voipms' && $is_enabled): ?>
                        <hr style="margin: var(--spacing-lg) 0;">
                        <h4 style="margin-bottom: var(--spacing-md); color: var(--color-primary);">SMS Tools</h4>

                        <div class="form-group">
                            <label class="form-label">Webhook URL for SMS/MMS</label>
                            <div style="display: flex; gap: var(--spacing-sm);">
                                <input type="text" id="webhook_url" class="form-control"
                                       value="<?php echo htmlspecialchars(BASE_URL . '/src/voipms_webhook.php?to={TO}&from={FROM}&message={MESSAGE}&files={MEDIA}&id={ID}&date={TIMESTAMP}'); ?>"
                                       readonly style="flex: 1;">
                                <button type="button" class="btn btn-secondary" onclick="copyWebhookUrl()">Copy URL</button>
                            </div>
                            <small style="color: #666; font-size: 13px; margin-top: 0.25rem; display: block;">
                                Configure this URL in VoIP.ms: Main Menu → DID Numbers → Manage DID (click your number) → SMS/MMS tab → URL Callback field
                            </small>
                        </div>

                        <div style="display: flex; gap: var(--spacing-sm); margin-top: var(--spacing-md); flex-wrap: wrap;">
                            <button type="button" class="btn btn-secondary" onclick="openTestSmsModal()">Test Outbound SMS</button>
                            <button type="button" class="btn btn-secondary" onclick="openViewMessagesModal()">View Inbound Messages</button>
                            <button type="button" class="btn btn-secondary" onclick="openWebhookLogsModal()">View Webhook Logs</button>
                        </div>
                    <?php endif; ?>
                </form>
            </div>
        <?php endforeach; ?>

        <!-- Backend Integrations (Collapsible) -->
        <details style="margin-top: var(--spacing-lg);">
            <summary style="cursor: pointer; padding: var(--spacing-md); background: #f3f4f6; border-radius: 8px; font-weight: 600; color: #374151; user-select: none;">
                Back-end Integrations
                <span style="float: right; opacity: 0.6;">▼</span>
            </summary>
            <div style="margin-top: var(--spacing-md);">
                <?php foreach ($backend_services as $service_key => $service_info): ?>
                    <?php
                    $service_data = $api_keys[$service_key] ?? null;
                    $is_enabled = $service_data && $service_data['is_enabled'];
                    $config = $service_data ? json_decode($service_data['additional_config'], true) : [];
                    ?>
                    <div class="card" style="margin-bottom: var(--spacing-md);">
                        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                            <h3 style="margin:0;color:var(--color-primary);"><?php echo $service_info['name']; ?></h3>
                            <span class="badge badge-<?php echo $is_enabled ? 'success' : 'danger'; ?>">
                                <?php echo $is_enabled ? 'Enabled' : 'Disabled'; ?>
                            </span>
                        </div>
                        <form method="POST" action="?tab=api" style="padding:var(--spacing-lg);">
                            <input type="hidden" name="save_api_key" value="1">
                            <input type="hidden" name="service" value="<?php echo $service_key; ?>">

                            <div class="form-group">
                                <label><input type="checkbox" name="is_enabled" <?php echo $is_enabled ? 'checked' : ''; ?>> Enable this integration</label>
                            </div>

                            <?php foreach ($service_info['fields'] as $field_key => $field_label): ?>
                                <?php $is_password_field = strpos($field_key, 'password') !== false || strpos($field_key, 'secret') !== false || strpos($field_key, 'token') !== false; ?>
                                <div class="form-group">
                                    <label class="form-label"><?php echo $field_label; ?></label>
                                    <div style="position: relative;">
                                        <input type="<?php echo $is_password_field ? 'password' : 'text'; ?>"
                                               name="<?php echo $field_key; ?>"
                                               class="form-control<?php echo $is_password_field ? ' password-field' : ''; ?>"
                                               value="<?php echo htmlspecialchars($config[$field_key] ?? ''); ?>"
                                               placeholder="<?php echo $field_label; ?>"
                                               style="<?php echo $is_password_field ? 'padding-right: 45px;' : ''; ?>">
                                        <?php if ($is_password_field): ?>
                                        <button type="button" class="toggle-password" onclick="togglePassword(this)" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: var(--color-primary); font-size: 18px; padding: 5px;">
                                            👁️
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                            <button type="submit" class="btn btn-primary">Save <?php echo $service_info['name']; ?> Settings</button>

                            <?php if ($service_key === 'voipms' && $is_enabled): ?>
                                <hr style="margin: var(--spacing-lg) 0;">
                                <h4 style="color: var(--color-primary); margin-bottom: var(--spacing-md);">Webhook Configuration</h4>
                                <div class="form-group">
                                    <label class="form-label">Webhook URL (for incoming SMS)</label>
                                    <div style="display: flex; gap: var(--spacing-sm);">
                                        <input type="text" id="webhook_url" class="form-control" readonly value="<?php echo BASE_URL; ?>/api/voipms_webhook.php">
                                        <button type="button" class="btn btn-secondary" onclick="copyWebhookUrl()">Copy</button>
                                    </div>
                                    <small style="color: #666; font-size: 13px; margin-top: 0.25rem; display: block;">
                                        Configure this URL in VoIP.ms: Main Menu → DID Numbers → Manage DID (click your number) → SMS/MMS tab → URL Callback field
                                    </small>
                                </div>

                                <div style="display: flex; gap: var(--spacing-sm); margin-top: var(--spacing-md); flex-wrap: wrap;">
                                    <button type="button" class="btn btn-secondary" onclick="openTestSmsModal()">Test Outbound SMS</button>
                                    <button type="button" class="btn btn-secondary" onclick="openWebhookLogsModal()">View Webhook Logs</button>
                                </div>
                            <?php endif; ?>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        </details>
    <?php endif; ?>

    <?php if ($active_tab === 'xactoauth'): ?>
        <?php
        $xactoauth_config = $api_keys['xactoauth'] ?? null;
        $xactoauth_enabled = $xactoauth_config && $xactoauth_config['is_enabled'];
        $xactoauth_data = $xactoauth_config ? json_decode($xactoauth_config['additional_config'], true) : [];
        ?>

        <div class="card">
            <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
                <h2 class="card-title" style="margin: 0;">XactoAuth Single Sign-On Configuration</h2>
                <span class="badge badge-<?php echo $xactoauth_enabled ? 'success' : 'danger'; ?>">
                    <?php echo $xactoauth_enabled ? 'Enabled' : 'Disabled'; ?>
                </span>
            </div>

            <div style="padding: var(--spacing-lg);">
                <div class="alert alert-info" style="margin-bottom: var(--spacing-lg);">
                    <strong>About XactoAuth</strong>
                    <p style="margin: 0.5rem 0 0 0;">
                        XactoAuth provides centralized single sign-on (SSO) authentication across all TransXacto applications.
                        Once configured, users can log in once and access EnrollMagic and all other TransXacto services seamlessly.
                    </p>
                </div>

                <h3 style="color: var(--color-primary); margin-top: var(--spacing-lg); margin-bottom: var(--spacing-md);">OAuth Configuration</h3>

                <div class="info-grid" style="display: grid; grid-template-columns: 200px 1fr; gap: var(--spacing-md); margin-bottom: var(--spacing-lg);">
                    <div><strong>App ID:</strong></div>
                    <div style="font-family: monospace; background: var(--color-light-1); padding: 0.5rem; border-radius: 4px;">
                        <?php echo htmlspecialchars($xactoauth_data['app_id'] ?? 'Not configured'); ?>
                    </div>

                    <div><strong>App Name:</strong></div>
                    <div><?php echo htmlspecialchars($xactoauth_data['app_name'] ?? 'Not configured'); ?></div>

                    <div><strong>API Key:</strong></div>
                    <div style="font-family: monospace; background: var(--color-light-1); padding: 0.5rem; border-radius: 4px; word-break: break-all;">
                        <?php echo htmlspecialchars($xactoauth_data['api_key'] ?? 'Not configured'); ?>
                    </div>

                    <div><strong>Status:</strong></div>
                    <div>
                        <?php if ($xactoauth_enabled): ?>
                            <span style="color: var(--color-success);">✓ Active and configured</span>
                        <?php else: ?>
                            <span style="color: var(--color-danger);">✗ Disabled</span>
                        <?php endif; ?>
                    </div>
                </div>

                <h3 style="color: var(--color-primary); margin-top: var(--spacing-lg); margin-bottom: var(--spacing-md);">Webhook Configuration</h3>

                <div class="alert alert-warning" style="margin-bottom: var(--spacing-md);">
                    <strong>Important: Configure this webhook URL in your XactoAuth admin panel</strong>
                    <p style="margin: 0.5rem 0 0 0;">
                        This webhook is required for "Sign Out of All Apps" functionality to work properly.
                    </p>
                </div>

                <div class="form-group">
                    <label class="form-label">Webhook URL</label>
                    <div style="display: flex; gap: var(--spacing-sm);">
                        <input type="text" class="form-control" id="webhook-url" readonly
                               value="<?php echo BASE_URL; ?>/webhooks/xactoauth.php"
                               style="font-family: monospace; background: var(--color-light-1);">
                        <button type="button" class="btn btn-secondary" onclick="copyWebhookUrl()">Copy</button>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Webhook Secret</label>
                    <div style="display: flex; gap: var(--spacing-sm);">
                        <input type="text" class="form-control" id="webhook-secret" readonly
                               value="<?php echo htmlspecialchars($xactoauth_data['webhook_secret'] ?? 'Not configured'); ?>"
                               style="font-family: monospace; background: var(--color-light-1);">
                        <button type="button" class="btn btn-secondary" onclick="copyWebhookSecret()">Copy</button>
                    </div>
                </div>

                <h3 style="color: var(--color-primary); margin-top: var(--spacing-lg); margin-bottom: var(--spacing-md);">XactoAuth API Endpoints</h3>

                <div class="info-grid" style="display: grid; grid-template-columns: 200px 1fr; gap: var(--spacing-md); margin-bottom: var(--spacing-lg);">
                    <div><strong>Initiate Auth URL:</strong></div>
                    <div style="font-family: monospace; font-size: 0.9em; word-break: break-all;">
                        https://auth.transxacto.net/api/v1/auth.php?action=initiate
                    </div>

                    <div><strong>Token Exchange URL:</strong></div>
                    <div style="font-family: monospace; font-size: 0.9em; word-break: break-all;">
                        https://auth.transxacto.net/api/v1/auth.php?action=token
                    </div>

                    <div><strong>User Info URL:</strong></div>
                    <div style="font-family: monospace; font-size: 0.9em; word-break: break-all;">
                        https://auth.transxacto.net/api/v1/user
                    </div>

                    <div><strong>Logout URL:</strong></div>
                    <div style="font-family: monospace; font-size: 0.9em; word-break: break-all;">
                        https://auth.transxacto.net/api/v1/auth.php?action=logout
                    </div>

                    <div><strong>Callback URL:</strong></div>
                    <div style="font-family: monospace; font-size: 0.9em; word-break: break-all;">
                        <?php echo BASE_URL; ?>/admin/xactoauth-callback.php
                    </div>
                </div>

                <h3 style="color: var(--color-primary); margin-top: var(--spacing-lg); margin-bottom: var(--spacing-md);">User Accounts</h3>

                <?php
                $stmt = $pdo->prepare("SELECT id, name, email, xactoauth_user_id FROM staff WHERE xactoauth_user_id IS NOT NULL");
                $stmt->execute();
                $linked_users = $stmt->fetchAll();
                ?>

                <?php if (!empty($linked_users)): ?>
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: var(--color-light-1); border-bottom: 2px solid #ddd;">
                                <th style="padding: var(--spacing-sm); text-align: left;">Name</th>
                                <th style="padding: var(--spacing-sm); text-align: left;">Email</th>
                                <th style="padding: var(--spacing-sm); text-align: left;">XactoAuth User ID</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($linked_users as $user): ?>
                                <tr style="border-bottom: 1px solid #eee;">
                                    <td style="padding: var(--spacing-sm);"><?php echo htmlspecialchars($user['name']); ?></td>
                                    <td style="padding: var(--spacing-sm);"><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td style="padding: var(--spacing-sm); font-family: monospace; font-size: 0.9em;">
                                        <?php echo htmlspecialchars($user['xactoauth_user_id']); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p style="margin-top: var(--spacing-md); color: #666; font-size: 0.9em;">
                        <?php echo count($linked_users); ?> user(s) linked to XactoAuth
                    </p>
                <?php else: ?>
                    <div class="alert alert-warning">
                        No users have linked their XactoAuth accounts yet. Users can link their accounts from their
                        <a href="preferences.php" style="color: var(--color-primary); font-weight: 600;">Preferences</a> page.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <script>
        function copyWebhookUrl() {
            const input = document.getElementById('webhook-url');
            input.select();
            document.execCommand('copy');
            alert('Webhook URL copied to clipboard!');
        }

        function copyWebhookSecret() {
            const input = document.getElementById('webhook-secret');
            input.select();
            document.execCommand('copy');
            alert('Webhook secret copied to clipboard!');
        }
        </script>
    <?php endif; ?>

    <?php if ($active_tab === 'zapier'): ?>
        <?php
        $zapier_config = $api_keys['zapier'] ?? null;
        $zapier_enabled = $zapier_config && $zapier_config['is_enabled'];
        $zapier_data = $zapier_config ? json_decode($zapier_config['additional_config'], true) : [];
        $zapier_api_key = $zapier_data['api_key'] ?? '';
        ?>

        <div class="card">
            <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
                <h2 class="card-title" style="margin: 0;">Zapier Integration Settings</h2>
                <span class="badge badge-<?php echo $zapier_enabled ? 'success' : 'danger'; ?>">
                    <?php echo $zapier_enabled ? 'Enabled' : 'Disabled'; ?>
                </span>
            </div>

            <div style="padding: var(--spacing-lg);">
                <div class="alert alert-info" style="margin-bottom: var(--spacing-lg);">
                    <strong>About Zapier Integration</strong>
                    <p style="margin: 0.5rem 0 0 0;">
                        Connect Credit Enroll Pro to 5,000+ apps via Zapier. Use these credentials to authenticate
                        your Zapier zaps and automate enrollment workflows.
                    </p>
                </div>

                <form method="POST" action="?tab=zapier">
                    <input type="hidden" name="save_api_key" value="1">
                    <input type="hidden" name="service" value="zapier">

                    <div class="form-group">
                        <label><input type="checkbox" name="is_enabled" <?php echo $zapier_enabled ? 'checked' : ''; ?>> Enable Zapier Integration</label>
                    </div>

                    <?php if (!$zapier_enabled): ?>
                        <div class="alert alert-warning" style="margin-top: var(--spacing-md);">
                            <strong>Integration Disabled</strong>
                            <p style="margin: 0.5rem 0 0 0;">
                                Check "Enable Zapier Integration" and click "Generate API Key" below to get started.
                            </p>
                        </div>
                    <?php endif; ?>

                    <?php if ($zapier_enabled || !empty($zapier_api_key)): ?>
                    <h3 style="color: var(--color-primary); margin-top: var(--spacing-lg); margin-bottom: var(--spacing-md);">Connection Details</h3>

                    <div class="form-group">
                        <label class="form-label">Install URL</label>
                        <div style="display: flex; gap: var(--spacing-sm);">
                            <input type="text" class="form-control" id="zapier-install-url" readonly
                                   value="<?php echo BASE_URL; ?>"
                                   style="font-family: monospace; background: var(--color-light-1);">
                            <button type="button" class="btn btn-secondary" onclick="copyToClipboard('zapier-install-url', this)">Copy</button>
                        </div>
                        <small style="color: #666; font-size: 13px; margin-top: 0.25rem; display: block;">
                            Use this URL when setting up authentication in Zapier
                        </small>
                    </div>

                    <div class="form-group">
                        <label class="form-label">API Key</label>
                        <div style="display: flex; gap: var(--spacing-sm);">
                            <input type="text" name="api_key" class="form-control" id="zapier-api-key" readonly
                                   value="<?php echo htmlspecialchars($zapier_api_key); ?>"
                                   style="font-family: monospace; background: var(--color-light-1);">
                            <button type="button" class="btn btn-secondary" onclick="copyToClipboard('zapier-api-key', this)">Copy</button>
                        </div>
                        <small style="color: #666; font-size: 13px; margin-top: 0.25rem; display: block;">
                            Use this API key to authenticate Zapier requests
                        </small>
                    </div>

                    <div style="display: flex; gap: var(--spacing-sm); margin-top: var(--spacing-md);">
                        <button type="submit" class="btn btn-primary">Save Settings</button>
                        <?php if (empty($zapier_api_key)): ?>
                            <button type="submit" name="regenerate_key" value="1" class="btn btn-secondary">Generate API Key</button>
                        <?php else: ?>
                            <button type="submit" name="regenerate_key" value="1" class="btn btn-danger"
                                    onclick="return confirm('This will invalidate the current API key and break existing Zapier connections. Continue?');">
                                Regenerate API Key
                            </button>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </form>

                <?php if ($zapier_enabled && !empty($zapier_api_key)): ?>
                <h3 style="color: var(--color-primary); margin-top: var(--spacing-xl); margin-bottom: var(--spacing-md);">Setup Instructions</h3>

                <div style="background: #e0f2fe; border: 1px solid #0284c7; border-radius: 8px; padding: 1rem; margin-bottom: var(--spacing-md);">
                    <p style="margin: 0 0 0.5rem 0; color: #075985; font-size: 14px; font-weight: 600;">
                        How to connect Credit Enroll Pro to Zapier:
                    </p>
                    <ol style="margin: 0.5rem 0 0 1.5rem; padding: 0; color: #075985; font-size: 13px; line-height: 1.6;">
                        <li>Click "Generate API Key" above if you haven't already</li>
                        <li>Click "Save Settings" to enable the integration</li>
                        <li>Copy the Install URL and API Key</li>
                        <li>In Zapier, create a new Zap and search for "Credit Enroll Pro" (or use Webhooks)</li>
                        <li>When prompted for authentication, paste the Install URL and API Key</li>
                        <li>Test the connection and start automating!</li>
                    </ol>
                </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($active_tab === 'questions'): ?>
        <div class="card">
            <div class="card-header"><h2 class="card-title">Add Question</h2></div>
            <form method="POST" action="?tab=questions">
                <input type="hidden" name="add_question" value="1">
                <div class="form-group">
                    <label for="question_text" class="form-label required">Question Text</label>
                    <input type="text" id="question_text" name="question_text" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="question_type" class="form-label required">Type</label>
                    <select id="question_type" name="question_type" class="form-control" onchange="document.getElementById('options_group').style.display=this.value==='multiple_choice'?'block':'none'">
                        <option value="yes_no">Yes/No</option>
                        <option value="multiple_choice">Multiple Choice</option>
                        <option value="short_answer">Short Answer</option>
                        <option value="long_answer">Long Answer</option>
                    </select>
                </div>
                <div class="form-group" id="options_group" style="display:none;">
                    <label for="options" class="form-label">Options (one per line)</label>
                    <textarea id="options" name="options" class="form-control" rows="4"></textarea>
                </div>
                <div class="form-group"><label><input type="checkbox" name="is_required"> Required</label></div>
                <button type="submit" class="btn btn-primary">Add Question</button>
            </form>
        </div>
        <div class="card">
            <div class="card-header"><h2 class="card-title">Questions</h2></div>
            <div class="table-responsive">
                <table class="data-table">
                    <thead><tr><th>Order</th><th>Question</th><th>Type</th><th>Required</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php if (count($questions) > 0): ?>
                            <?php foreach ($questions as $q): ?>
                                <tr>
                                    <td><?php echo $q['display_order']; ?></td>
                                    <td><?php echo htmlspecialchars($q['question_text']); ?></td>
                                    <td><?php echo ucfirst(str_replace('_', ' ', $q['question_type'])); ?></td>
                                    <td><?php echo $q['is_required'] ? '✓' : ''; ?></td>
                                    <td><span class="badge badge-<?php echo $q['is_active'] ? 'success' : 'danger'; ?>"><?php echo $q['is_active'] ? 'Active' : 'Inactive'; ?></span></td>
                                    <td>
                                        <button type="button" class="btn btn-primary btn-sm" onclick="toggleEditForm(<?php echo $q['id']; ?>)">Edit</button>
                                        <form method="POST" action="?tab=questions" style="display:inline;">
                                            <input type="hidden" name="delete_question" value="1">
                                            <input type="hidden" name="question_id" value="<?php echo $q['id']; ?>">
                                            <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Delete?')">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                                <tr id="edit-form-<?php echo $q['id']; ?>" style="display:none;">
                                    <td colspan="6" style="background: #f8f9fa; padding: 1.5rem;">
                                        <form method="POST" action="?tab=questions">
                                            <input type="hidden" name="update_question" value="1">
                                            <input type="hidden" name="question_id" value="<?php echo $q['id']; ?>">
                                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                                                <div class="form-group">
                                                    <label class="form-label required">Question Text</label>
                                                    <input type="text" name="question_text" class="form-control" value="<?php echo htmlspecialchars($q['question_text']); ?>" required>
                                                </div>
                                                <div class="form-group">
                                                    <label class="form-label required">Type</label>
                                                    <select name="question_type" class="form-control" onchange="toggleOptionsField(<?php echo $q['id']; ?>, this.value)">
                                                        <option value="yes_no" <?php echo $q['question_type'] === 'yes_no' ? 'selected' : ''; ?>>Yes/No</option>
                                                        <option value="multiple_choice" <?php echo $q['question_type'] === 'multiple_choice' ? 'selected' : ''; ?>>Multiple Choice</option>
                                                        <option value="short_answer" <?php echo $q['question_type'] === 'short_answer' ? 'selected' : ''; ?>>Short Answer</option>
                                                        <option value="long_answer" <?php echo $q['question_type'] === 'long_answer' ? 'selected' : ''; ?>>Long Answer</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="form-group" id="options-field-<?php echo $q['id']; ?>" style="<?php echo $q['question_type'] !== 'multiple_choice' ? 'display:none;' : ''; ?> margin-bottom: 1rem;">
                                                <label class="form-label">Options (one per line)</label>
                                                <textarea name="options" class="form-control" rows="4"><?php
                                                    if ($q['question_type'] === 'multiple_choice' && !empty($q['options'])) {
                                                        $opts = json_decode($q['options'], true);
                                                        echo htmlspecialchars(implode("\n", $opts));
                                                    }
                                                ?></textarea>
                                            </div>
                                            <div style="display: flex; gap: 1rem; align-items: center;">
                                                <label style="margin: 0;"><input type="checkbox" name="is_required" <?php echo $q['is_required'] ? 'checked' : ''; ?>> Required</label>
                                                <label style="margin: 0;"><input type="checkbox" name="is_active" <?php echo $q['is_active'] ? 'checked' : ''; ?>> Active</label>
                                                <button type="submit" class="btn btn-success btn-sm">Save Changes</button>
                                                <button type="button" class="btn btn-secondary btn-sm" onclick="toggleEditForm(<?php echo $q['id']; ?>)">Cancel</button>
                                            </div>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="6" style="text-align:center;padding:2rem;color:#666;">No questions yet</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($active_tab === 'contracts'): ?>
        <!-- Create New Package -->
        <div class="card">
            <div class="card-header"><h2 class="card-title">Create New Contract Package</h2></div>
            <form method="POST" action="?tab=contracts">
                <input type="hidden" name="create_contract_package" value="1">
                <div class="form-group">
                    <label for="package_name" class="form-label required">Package Name</label>
                    <input type="text" id="package_name" name="package_name" class="form-control"
                           placeholder="e.g., Texas Contracts, California Contracts" required>
                    <small style="color: #666; font-size: 13px; margin-top: 0.25rem; display: block;">
                        Give this package a descriptive name (e.g., "Texas & Oklahoma Contracts")
                    </small>
                </div>
                <div class="form-group">
                    <label for="days_to_cancel" class="form-label required">Days to Cancel</label>
                    <input type="number" id="days_to_cancel" name="days_to_cancel" class="form-control"
                           value="5" min="1" max="365" required style="max-width: 150px;">
                    <small style="color: #666; font-size: 13px; margin-top: 0.25rem; display: block;">
                        Number of days allowed for contract cancellation (typically 3-10 days)
                    </small>
                </div>
                <div class="form-group">
                    <label><input type="checkbox" name="is_default"> Set as Default Package</label>
                    <small style="color: #666; font-size: 13px; margin-left: 1.5rem; display: block;">
                        The default package is used when no state-specific package is found
                    </small>
                </div>
                <button type="submit" class="btn btn-primary">Create Package</button>
            </form>
        </div>

        <!-- Existing Packages -->
        <?php if (count($contract_packages) > 0): ?>
            <?php foreach ($contract_packages as $package): ?>
                <div class="card" style="margin-top: var(--spacing-lg);">
                    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                        <h3 style="margin:0;color:var(--color-primary);">
                            <?php echo htmlspecialchars($package['package_name']); ?>
                            <?php if ($package['is_default']): ?>
                                <span class="badge badge-success" style="margin-left:0.5rem;">DEFAULT</span>
                            <?php endif; ?>
                        </h3>
                        <div style="display:flex;gap:0.5rem;">
                            <?php if (!$package['is_default']): ?>
                                <form method="POST" action="?tab=contracts" style="display:inline;">
                                    <input type="hidden" name="set_default_package" value="1">
                                    <input type="hidden" name="package_id" value="<?php echo $package['id']; ?>">
                                    <button type="submit" class="btn btn-secondary btn-sm">Set as Default</button>
                                </form>
                                <form method="POST" action="?tab=contracts" style="display:inline;">
                                    <input type="hidden" name="delete_contract_package" value="1">
                                    <input type="hidden" name="package_id" value="<?php echo $package['id']; ?>">
                                    <button type="submit" class="btn btn-danger btn-sm"
                                            onclick="return confirm('Delete this package? This will remove all associated contracts and state mappings.')">
                                        Delete Package
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div style="padding:var(--spacing-lg);">
                        <!-- XactoSign Client ID Section -->
                        <div style="background:#e7f3ff;padding:var(--spacing-md);border-radius:var(--border-radius);margin-bottom:var(--spacing-lg);border-left:4px solid #0066cc;">
                            <h4 style="margin:0 0 var(--spacing-md) 0;color:#003d7a;">XactoSign Client ID</h4>
                            <form method="POST" action="?tab=contracts" style="display:flex;align-items:end;gap:var(--spacing-md);">
                                <input type="hidden" name="update_client_id" value="1">
                                <input type="hidden" name="package_id" value="<?php echo $package['id']; ?>">
                                <div style="flex:1;max-width:400px;">
                                    <label for="client_id_<?php echo $package['id']; ?>" style="display:block;font-weight:600;margin-bottom:0.25rem;font-size:14px;color:#003d7a;">
                                        Client ID
                                    </label>
                                    <input type="text" id="client_id_<?php echo $package['id']; ?>" name="xactosign_client_id"
                                           class="form-control" value="<?php echo htmlspecialchars($package['xactosign_client_id'] ?? ''); ?>"
                                           placeholder="e.g., SHERIDAN" maxlength="50">
                                    <small style="color:#003d7a;font-size:12px;display:block;margin-top:0.25rem;">
                                        Used in certificate IDs: XACT-<strong>CLIENTID</strong>-XXXXXXXXXX
                                    </small>
                                </div>
                                <button type="submit" class="btn btn-primary btn-sm">Update</button>
                            </form>
                        </div>

                        <!-- Countersign Signature Section -->
                        <div style="background:#fff3cd;padding:var(--spacing-md);border-radius:var(--border-radius);margin-bottom:var(--spacing-lg);border-left:4px solid #ffc107;">
                            <h4 style="margin:0 0 var(--spacing-md) 0;color:#856404;">Countersign Signature</h4>
                            <p style="margin:0 0 var(--spacing-md) 0;color:#856404;font-size:14px;">
                                Upload or draw your company's countersignature to be added to contracts. This signature represents your company's approval of the contract.
                            </p>

                            <?php if (!empty($package['countersign_signature'])): ?>
                                <div style="background:#fff;padding:var(--spacing-md);border-radius:4px;margin-bottom:var(--spacing-md);border:1px solid #dee2e6;">
                                    <div style="display:flex;align-items:start;gap:var(--spacing-md);">
                                        <img src="data:image/png;base64,<?php echo base64_encode($package['countersign_signature']); ?>"
                                             alt="Countersign Signature"
                                             style="max-width:300px;max-height:100px;border:1px solid #dee2e6;padding:0.5rem;background:#fff;">
                                        <div style="flex:1;">
                                            <p style="margin:0 0 0.25rem 0;font-size:13px;"><strong>Filename:</strong> <?php echo htmlspecialchars($package['countersign_filename'] ?? 'N/A'); ?></p>
                                            <p style="margin:0 0 0.25rem 0;font-size:13px;"><strong>Uploaded:</strong> <?php echo !empty($package['countersign_uploaded_at']) ? date('M j, Y g:i A', strtotime($package['countersign_uploaded_at'])) : 'N/A'; ?></p>
                                            <form method="POST" action="?tab=contracts" style="margin-top:var(--spacing-sm);">
                                                <input type="hidden" name="delete_countersign" value="1">
                                                <input type="hidden" name="package_id" value="<?php echo $package['id']; ?>">
                                                <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Remove countersign signature?')">Remove Signature</button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <p style="color:#856404;font-size:13px;margin-bottom:var(--spacing-md);font-style:italic;">No countersign signature uploaded yet.</p>
                            <?php endif; ?>

                            <div style="display:flex;gap:var(--spacing-md);flex-wrap:wrap;">
                                <!-- Upload Image Option -->
                                <div style="flex:1;min-width:250px;">
                                    <form method="POST" action="?tab=contracts" enctype="multipart/form-data">
                                        <input type="hidden" name="upload_countersign" value="1">
                                        <input type="hidden" name="package_id" value="<?php echo $package['id']; ?>">
                                        <label style="display:block;font-weight:600;margin-bottom:0.25rem;font-size:14px;">Upload Signature Image</label>
                                        <input type="file" name="countersign_image" accept="image/png,image/jpeg" required
                                               style="font-size:12px;margin-bottom:0.5rem;width:100%;">
                                        <button type="submit" class="btn btn-primary btn-sm">Upload Image</button>
                                    </form>
                                </div>

                                <!-- Draw Signature Option -->
                                <div style="flex:1;min-width:250px;">
                                    <label style="display:block;font-weight:600;margin-bottom:0.25rem;font-size:14px;">Draw Signature</label>
                                    <p style="margin:0 0 0.5rem 0;color:#856404;font-size:12px;">Click to open signature pad</p>
                                    <button type="button" class="btn btn-primary btn-sm" onclick="openCountersignModal(<?php echo $package['id']; ?>)">Draw Signature</button>
                                </div>
                            </div>
                        </div>

                        <!-- Days to Cancel Section -->
                        <div style="background:#f8f9fa;padding:var(--spacing-md);border-radius:var(--border-radius);margin-bottom:var(--spacing-lg);border-left:4px solid var(--color-primary);">
                            <form method="POST" action="?tab=contracts" style="display:flex;align-items:end;gap:var(--spacing-md);">
                                <input type="hidden" name="update_days_to_cancel" value="1">
                                <input type="hidden" name="package_id" value="<?php echo $package['id']; ?>">
                                <div style="flex:0 0 auto;">
                                    <label for="days_to_cancel_<?php echo $package['id']; ?>" style="display:block;font-weight:600;margin-bottom:0.25rem;font-size:14px;">
                                        Days to Cancel
                                    </label>
                                    <input type="number" id="days_to_cancel_<?php echo $package['id']; ?>" name="days_to_cancel"
                                           class="form-control" value="<?php echo htmlspecialchars($package['days_to_cancel'] ?? 5); ?>"
                                           min="1" max="365" required style="width:100px;">
                                </div>
                                <button type="submit" class="btn btn-primary btn-sm">Update</button>
                                <small style="color:#666;flex:1;font-size:13px;margin-left:var(--spacing-md);">
                                    Number of days clients have to cancel their contract
                                </small>
                            </form>
                        </div>

                        <!-- Upload Contracts Section -->
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:var(--spacing-md);">
                            <h4 style="color:var(--color-primary);margin:0;">Contract Documents</h4>
                            <?php
                            // Check if all documents and countersign exist for test package
                            $has_croa = !empty($package['documents']['croa_disclosure']);
                            $has_agreement = !empty($package['documents']['client_agreement']);
                            $has_notice = !empty($package['documents']['notice_of_cancellation']);
                            $has_countersign = !empty($package['countersign_signature']);
                            $can_test_package = $has_croa && $has_agreement && $has_notice && $has_countersign;
                            ?>
                            <?php if ($can_test_package): ?>
                                <button class="btn btn-success" onclick="openTestPackageModal(<?php echo $package['id']; ?>)" style="font-size:14px;">
                                    Test Complete Package
                                </button>
                            <?php else: ?>
                                <button class="btn btn-sm" disabled style="background:#ffc107;color:#856404;cursor:not-allowed;font-size:13px;"
                                        title="Upload all 3 contracts and countersign signature first">
                                    Test Complete Package
                                </button>
                            <?php endif; ?>
                        </div>
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:var(--spacing-md);margin-bottom:var(--spacing-lg);">
                            <?php
                            $contract_types = [
                                'croa_disclosure' => [
                                    'name' => 'CROA Disclosure',
                                    'fields' => ['client_name', 'enrollment_date', 'client_signature'],
                                    'autofill_enabled' => true
                                ],
                                'client_agreement' => [
                                    'name' => 'Client Agreement',
                                    'fields' => ['client_name', 'client_address', 'client_phone', 'client_email', 'enrollment_date', 'cs_title', 'client_signature', 'counter_signature'],
                                    'autofill_enabled' => true
                                ],
                                'power_of_attorney' => [
                                    'name' => 'Power of Attorney',
                                    'fields' => ['client_name', 'client_address', 'enrollment_date', 'client_signature'],
                                    'autofill_enabled' => true
                                ],
                                'notice_of_cancellation' => [
                                    'name' => 'Notice of Cancellation',
                                    'fields' => ['notice_date'],
                                    'autofill_enabled' => true
                                ]
                            ];
                            foreach ($contract_types as $type => $info):
                                $doc = $package['documents'][$type] ?? null;
                            ?>
                                <div style="border:1px solid var(--color-light-2);padding:var(--spacing-md);border-radius:var(--border-radius);">
                                    <h5 style="margin:0 0 var(--spacing-sm) 0;color:#333;"><?php echo $info['name']; ?></h5>

                                    <!-- Expected Fields -->
                                    <div style="background:#fffbea;border-left:3px solid #f59e0b;padding:var(--spacing-sm);margin-bottom:var(--spacing-sm);font-size:12px;">
                                        <strong style="color:#92400e;">Expected PDF Fields:</strong><br>
                                        <code style="background:#fff;padding:2px 4px;border-radius:3px;font-size:11px;">
                                            <?php echo implode(', ', $info['fields']); ?>
                                        </code>
                                    </div>

                                    <?php if ($doc): ?>
                                        <div style="background:#f0f9ff;padding:var(--spacing-sm);border-radius:4px;margin-bottom:var(--spacing-sm);">
                                            <div style="font-size:13px;color:#666;">
                                                <strong>File:</strong> <?php echo htmlspecialchars($doc['file_name']); ?><br>
                                                <strong>Size:</strong> <?php echo number_format($doc['file_size'] / 1024, 1); ?> KB<br>
                                                <strong>Uploaded:</strong> <?php echo date('M j, Y', strtotime($doc['uploaded_at'])); ?>
                                            </div>
                                        </div>
                                        <div style="display:flex;gap:0.5rem;margin-bottom:var(--spacing-sm);">
                                            <a href="download_contract.php?doc_id=<?php echo $doc['id']; ?>"
                                               class="btn btn-secondary btn-sm" target="_blank">
                                                View PDF
                                            </a>
                                            <?php if ($info['autofill_enabled']): ?>
                                                <?php if ($type === 'croa_disclosure'): ?>
                                                    <button class="btn btn-success btn-sm"
                                                            onclick="openSignatureModal(<?php echo $doc['id']; ?>, <?php echo $package['id']; ?>)"
                                                            title="Draw signature and fill form fields with test data">
                                                        Test Autofill
                                                    </button>
                                                <?php elseif ($type === 'client_agreement'): ?>
                                                    <?php if (!empty($package['countersign_signature'])): ?>
                                                        <button class="btn btn-success btn-sm"
                                                                onclick="openClientAgreementModal(<?php echo $doc['id']; ?>, <?php echo $package['id']; ?>)"
                                                                title="Draw client signature and fill form fields with test data">
                                                            Test Autofill
                                                        </button>
                                                    <?php else: ?>
                                                        <button class="btn btn-sm" disabled style="background:#ffc107;color:#856404;cursor:not-allowed;"
                                                                title="Please upload a countersign signature first">
                                                            Test Autofill (Need Countersign)
                                                        </button>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <a href="test_autofill_contract.php?doc_id=<?php echo $doc['id']; ?>&package_id=<?php echo $package['id']; ?>"
                                                       class="btn btn-success btn-sm" target="_blank" title="Fill form fields with test data">
                                                        Test Autofill
                                                    </a>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <button class="btn btn-sm" disabled style="background:#e5e7eb;color:#9ca3af;cursor:not-allowed;" title="Autofill coming soon">
                                                    Test Autofill
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <p style="color:#999;font-size:13px;margin-bottom:var(--spacing-sm);">Not uploaded</p>
                                    <?php endif; ?>
                                    <form method="POST" action="?tab=contracts" enctype="multipart/form-data" style="margin-top:var(--spacing-sm);">
                                        <input type="hidden" name="upload_contract" value="1">
                                        <input type="hidden" name="package_id" value="<?php echo $package['id']; ?>">
                                        <input type="hidden" name="contract_type" value="<?php echo $type; ?>">
                                        <input type="file" name="contract_pdf" accept="application/pdf" required
                                               style="font-size:12px;margin-bottom:0.5rem;width:100%;">
                                        <button type="submit" class="btn btn-primary btn-sm">
                                            <?php echo $doc ? 'Replace' : 'Upload'; ?> PDF
                                        </button>
                                    </form>

                                    <?php if ($doc): ?>
                                        <!-- Signature Coordinates Configuration -->
                                        <details style="margin-top:var(--spacing-md);border-top:1px solid #e5e7eb;padding-top:var(--spacing-sm);">
                                            <summary style="cursor:pointer;font-weight:600;font-size:12px;color:#6b7280;padding:0.25rem 0;">
                                                ⚙️ Configure Signature Placement (<?php
                                                    $sig_coords = [];
                                                    if (!empty($doc['signature_coords'])) {
                                                        $sig_coords = json_decode($doc['signature_coords'], true) ?: [];
                                                    } else {
                                                        if ($type === 'croa_disclosure') {
                                                            $sig_coords = [['signature_type' => 'client', 'label' => 'Client Signature', 'page' => 2, 'x1' => 84.0, 'y1' => 145.0, 'x2' => 327.0, 'y2' => 167.0]];
                                                        } elseif ($type === 'client_agreement') {
                                                            $sig_coords = [
                                                                ['signature_type' => 'client', 'label' => 'Client Signature', 'page' => 'last', 'x1' => 96.0, 'y1' => 457.0, 'x2' => 283.0, 'y2' => 480.0],
                                                                ['signature_type' => 'countersign', 'label' => 'Company Representative', 'page' => 'last', 'x1' => 182.0, 'y1' => 399.0, 'x2' => 374.0, 'y2' => 422.0]
                                                            ];
                                                        }
                                                    }
                                                    echo count($sig_coords);
                                                ?> signatures)
                                            </summary>
                                            <form method="POST" action="?tab=contracts" style="margin-top:var(--spacing-sm);" id="sig_form_<?php echo $doc['id']; ?>">
                                                <input type="hidden" name="update_sig_coords" value="1">
                                                <input type="hidden" name="doc_id" value="<?php echo $doc['id']; ?>">
                                                <input type="hidden" name="sig_count" id="sig_count_<?php echo $doc['id']; ?>" value="<?php echo count($sig_coords); ?>">

                                                <div style="background:#fef3c7;padding:var(--spacing-md);border-radius:4px;font-size:12px;margin-bottom:var(--spacing-sm);border-left:3px solid #f59e0b;">
                                                    <strong style="color:#92400e;">📐 PDF Coordinate System:</strong><br>
                                                    <div style="margin-top:0.5rem;color:#78350f;">
                                                        • <strong>Origin:</strong> Bottom-Left corner (PDF standard)<br>
                                                        • <strong>Units:</strong> Points (72 points = 1 inch)<br>
                                                        • <strong>Page Height:</strong> Letter size = 792 points (11 inches)<br>
                                                        • <strong>X-axis:</strong> Left (0) → Right (612 for letter width)<br>
                                                        • <strong>Y-axis:</strong> Bottom (0) → Top (792)<br><br>
                                                        💡 <strong>Tip:</strong> To convert from top-down measurements:<br>
                                                        &nbsp;&nbsp;&nbsp;If your signature is <strong>335 points from top</strong>, use Y = <strong>792 - 335 = 457</strong><br><br>
                                                        <a href="find_pdf_coordinates.php?doc_id=<?php echo $doc['id']; ?>" target="_blank" class="btn btn-sm" style="background:#0284c7;color:white;font-size:11px;padding:0.4rem 0.8rem;text-decoration:none;display:inline-block;border-radius:4px;">
                                                            🎯 Open Coordinate Finder
                                                        </a>
                                                    </div>
                                                </div>

                                                <div id="signatures_container_<?php echo $doc['id']; ?>">
                                                    <?php foreach ($sig_coords as $idx => $sig): ?>
                                                        <div class="signature-item" data-doc="<?php echo $doc['id']; ?>" data-index="<?php echo $idx; ?>" style="background:#fff;border:1px solid #e5e7eb;border-radius:4px;padding:var(--spacing-sm);margin-bottom:var(--spacing-sm);">
                                                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.5rem;">
                                                                <div style="font-weight:600;font-size:12px;color:#374151;">
                                                                    Signature #<span class="sig-number"><?php echo $idx + 1; ?></span>
                                                                </div>
                                                                <button type="button" onclick="removeSignature(<?php echo $doc['id']; ?>, this)" class="btn btn-danger" style="font-size:10px;padding:0.2rem 0.5rem;">✕ Remove</button>
                                                            </div>

                                                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;margin-bottom:0.5rem;">
                                                                <div>
                                                                    <label style="font-size:10px;color:#6b7280;display:block;">Signature Type</label>
                                                                    <select name="sig_<?php echo $idx; ?>_type" class="form-control" style="font-size:11px;padding:0.25rem;height:28px;">
                                                                        <option value="client" <?php echo ($sig['signature_type'] ?? '') === 'client' ? 'selected' : ''; ?>>Client</option>
                                                                        <option value="countersign" <?php echo ($sig['signature_type'] ?? '') === 'countersign' ? 'selected' : ''; ?>>Company/Countersign</option>
                                                                        <option value="witness" <?php echo ($sig['signature_type'] ?? '') === 'witness' ? 'selected' : ''; ?>>Witness</option>
                                                                        <option value="other" <?php echo ($sig['signature_type'] ?? '') === 'other' ? 'selected' : ''; ?>>Other</option>
                                                                    </select>
                                                                </div>
                                                                <div>
                                                                    <label style="font-size:10px;color:#6b7280;display:block;">Label</label>
                                                                    <input type="text" name="sig_<?php echo $idx; ?>_label" value="<?php echo htmlspecialchars($sig['label'] ?? ''); ?>"
                                                                           class="form-control" style="font-size:11px;padding:0.25rem;height:28px;" placeholder="e.g., Client Signature">
                                                                </div>
                                                            </div>

                                                            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:0.25rem;">
                                                                <div>
                                                                    <label style="font-size:10px;color:#6b7280;display:block;">Page</label>
                                                                    <input type="text" name="sig_<?php echo $idx; ?>_page" value="<?php echo htmlspecialchars($sig['page'] ?? 'last'); ?>"
                                                                           class="form-control" style="font-size:11px;padding:0.25rem;height:28px;" placeholder="last">
                                                                </div>
                                                                <div>
                                                                    <label style="font-size:10px;color:#6b7280;display:block;">Top-Left X1</label>
                                                                    <input type="number" step="0.1" name="sig_<?php echo $idx; ?>_x1" value="<?php echo $sig['x1'] ?? 0; ?>"
                                                                           class="form-control" style="font-size:11px;padding:0.25rem;height:28px;">
                                                                </div>
                                                                <div>
                                                                    <label style="font-size:10px;color:#6b7280;display:block;">Top-Left Y1</label>
                                                                    <input type="number" step="0.1" name="sig_<?php echo $idx; ?>_y1" value="<?php echo $sig['y1'] ?? 0; ?>"
                                                                           class="form-control" style="font-size:11px;padding:0.25rem;height:28px;">
                                                                </div>
                                                                <div></div>
                                                                <div>
                                                                    <label style="font-size:10px;color:#6b7280;display:block;">Bottom-Right X2</label>
                                                                    <input type="number" step="0.1" name="sig_<?php echo $idx; ?>_x2" value="<?php echo $sig['x2'] ?? 0; ?>"
                                                                           class="form-control" style="font-size:11px;padding:0.25rem;height:28px;">
                                                                </div>
                                                                <div>
                                                                    <label style="font-size:10px;color:#6b7280;display:block;">Bottom-Right Y2</label>
                                                                    <input type="number" step="0.1" name="sig_<?php echo $idx; ?>_y2" value="<?php echo $sig['y2'] ?? 0; ?>"
                                                                           class="form-control" style="font-size:11px;padding:0.25rem;height:28px;">
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>

                                                <div style="display:flex;gap:0.5rem;">
                                                    <button type="button" onclick="addSignature(<?php echo $doc['id']; ?>)" class="btn btn-secondary btn-sm" style="font-size:11px;padding:0.35rem 0.75rem;">
                                                        ➕ Add Signature
                                                    </button>
                                                    <button type="submit" class="btn btn-primary btn-sm" style="font-size:11px;padding:0.35rem 0.75rem;">
                                                        💾 Save Coordinates
                                                    </button>
                                                </div>
                                            </form>
                                        </details>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- State Assignment Section -->
                        <h4 style="color:var(--color-primary);margin-bottom:var(--spacing-md);padding-top:var(--spacing-md);border-top:1px solid var(--color-light-2);">
                            Assigned States
                        </h4>
                        <?php if (!empty($package['states'])): ?>
                            <div style="background:#f0f9ff;padding:var(--spacing-md);border-radius:4px;margin-bottom:var(--spacing-md);">
                                <strong>Currently assigned to:</strong>
                                <?php
                                $state_names = array_map(function($code) use ($us_states) {
                                    return $us_states[$code] ?? $code;
                                }, $package['states']);
                                echo implode(', ', $state_names);
                                ?>
                            </div>
                        <?php else: ?>
                            <p style="color:#999;margin-bottom:var(--spacing-md);">No states assigned yet.</p>
                        <?php endif; ?>

                        <form method="POST" action="?tab=contracts">
                            <input type="hidden" name="update_package_states" value="1">
                            <input type="hidden" name="package_id" value="<?php echo $package['id']; ?>">

                            <div style="max-height:300px;overflow-y:auto;border:1px solid var(--color-light-2);padding:var(--spacing-md);border-radius:var(--border-radius);margin-bottom:var(--spacing-md);">
                                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:var(--spacing-sm);">
                                    <?php foreach ($us_states as $code => $name): ?>
                                        <label style="display:flex;align-items:center;cursor:pointer;">
                                            <input type="checkbox" name="states[]" value="<?php echo $code; ?>"
                                                   <?php echo in_array($code, $package['states']) ? 'checked' : ''; ?>
                                                   style="margin-right:0.5rem;">
                                            <span><?php echo $name; ?> (<?php echo $code; ?>)</span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary">Update State Assignments</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="card" style="margin-top: var(--spacing-lg);">
                <div style="padding:var(--spacing-lg);text-align:center;color:#666;">
                    No contract packages yet. Create one above to get started.
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($active_tab === 'templates'): ?>
        <?php
        // Fetch all templates
        $stmt = $pdo->query("SELECT * FROM communication_templates ORDER BY template_type, template_category, template_name");
        $all_templates = $stmt->fetchAll();

        // Organize templates
        $templates_by_type = ['sms' => [], 'email' => []];
        foreach ($all_templates as $template) {
            $templates_by_type[$template['template_type']][] = $template;
        }

        $template_subtab = $_GET['subtype'] ?? 'sms';

        // Get brand name from settings
        $brand_name = $all_settings['general']['company_name']['setting_value'] ?? 'Your Company Name';
        ?>

        <!-- Include Quill Rich Text Editor -->
        <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
        <script src="https://cdn.quilljs.com/1.3.6/quill.min.js"></script>
        <script>
            // Pass PHP settings to JavaScript
            window.BRAND_NAME = <?php echo json_encode($brand_name); ?>;
            window.BRAND_LOGO_URL = <?php echo json_encode(BRAND_LOGO_URL); ?>;
        </script>

        <div class="card">
            <div class="card-header"><h2 class="card-title">Communication Templates</h2></div>
            <div style="padding: var(--spacing-lg);">
                <p style="color: #666; margin-bottom: var(--spacing-lg);">
                    Manage SMS and email templates for staff, affiliate, and client notifications.
                    Use placeholders like [client_name], [plan_name], etc. to personalize messages.
                </p>

                <!-- Sub-tabs for SMS/Email -->
                <div style="display: flex; gap: var(--spacing-sm); border-bottom: 2px solid var(--color-light-2); margin-bottom: var(--spacing-lg);">
                    <a href="?tab=templates&subtype=sms"
                       style="padding: var(--spacing-md); text-decoration: none; color: <?php echo $template_subtab === 'sms' ? 'var(--color-primary)' : '#666'; ?>; border-bottom: 3px solid <?php echo $template_subtab === 'sms' ? 'var(--color-primary)' : 'transparent'; ?>; margin-bottom: -2px; font-weight: <?php echo $template_subtab === 'sms' ? '600' : '400'; ?>;">
                        SMS Templates
                    </a>
                    <a href="?tab=templates&subtype=email"
                       style="padding: var(--spacing-md); text-decoration: none; color: <?php echo $template_subtab === 'email' ? 'var(--color-primary)' : '#666'; ?>; border-bottom: 3px solid <?php echo $template_subtab === 'email' ? 'var(--color-primary)' : 'transparent'; ?>; margin-bottom: -2px; font-weight: <?php echo $template_subtab === 'email' ? '600' : '400'; ?>;">
                        Email Templates
                    </a>
                </div>

                <div id="templatesContainer">
                    <?php
                    $template_list = $templates_by_type[$template_subtab];
                    $template_labels = [
                        'new_lead_staff' => 'New Lead (Staff Notification)',
                        'contracts_signed_staff' => 'Contracts Signed (Staff Notification)',
                        'enrollment_complete_staff' => 'Enrollment Complete (Staff Notification)',
                        'ids_uploaded_staff' => 'IDs Uploaded (Staff Notification)',
                        'spouse_contracted_staff' => 'Spouse Contracted (Staff Notification)',
                        'spouse_id_uploaded_staff' => 'Spouse ID Uploaded (Staff Notification)',
                        'new_lead_affiliate' => 'New Lead (Affiliate Notification)',
                        'contracts_signed_affiliate' => 'Contracts Signed (Affiliate Notification)',
                        'enrollment_complete_affiliate' => 'Enrollment Complete (Affiliate Notification)',
                        'ids_uploaded_affiliate' => 'IDs Uploaded (Affiliate Notification)',
                        'spouse_contracted_affiliate' => 'Spouse Contracted (Affiliate Notification)',
                        'spouse_id_uploaded_affiliate' => 'Spouse ID Uploaded (Affiliate Notification)',
                        'spouse_contract_request_client' => 'Spouse Contract Request (Client Notification)',
                        'enrollment_complete_client' => 'Enrollment Complete (Client Notification)',
                        'ids_uploaded_client' => 'IDs Uploaded (Client Notification)',
                        'lost_enrollment_client' => 'Lost Enrollment (Client Notification)'
                    ];

                    foreach ($template_list as $template):
                        $template_label = $template_labels[$template['template_name']] ?? $template['template_name'];
                    ?>
                        <div class="template-card" style="background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: var(--spacing-lg); margin-bottom: var(--spacing-md);">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--spacing-md);">
                                <h3 style="margin: 0; color: var(--color-primary); font-size: 16px;"><?php echo htmlspecialchars($template_label); ?></h3>
                                <button onclick="openTestModal(<?php echo $template['id']; ?>, '<?php echo $template['template_type']; ?>')" class="btn btn-sm" style="background: #10b981; color: white;">
                                    Send Test
                                </button>
                            </div>

                            <form method="POST" action="" id="template_form_<?php echo $template['id']; ?>">
                                <input type="hidden" name="save_template" value="1">
                                <input type="hidden" name="template_id" value="<?php echo $template['id']; ?>">
                                <input type="hidden" name="template_type" value="<?php echo $template['template_type']; ?>">
                                <input type="hidden" name="return_tab" value="<?php echo $template_subtab; ?>">

                                <?php if ($template['template_type'] === 'email'): ?>
                                    <div class="form-group">
                                        <label class="form-label">Subject Line</label>
                                        <input type="text" name="subject" class="form-control" value="<?php echo htmlspecialchars($template['subject'] ?? ''); ?>" required>
                                    </div>
                                <?php endif; ?>

                                <div class="form-group">
                                    <label class="form-label">
                                        <?php echo $template['template_type'] === 'sms' ? 'Message' : 'Content'; ?>
                                        <?php if ($template['template_type'] === 'sms'): ?>
                                            <span id="sms_count_<?php echo $template['id']; ?>" style="float: right; font-size: 12px; color: #666;"></span>
                                        <?php endif; ?>
                                    </label>

                                    <?php if ($template['template_type'] === 'sms'): ?>
                                        <textarea name="content" id="content_<?php echo $template['id']; ?>" class="form-control sms-template" rows="4" required><?php echo htmlspecialchars($template['content']); ?></textarea>
                                    <?php else: ?>
                                        <div class="quill-editor-wrapper" id="editor_wrapper_<?php echo $template['id']; ?>">
                                            <div id="quill_editor_<?php echo $template['id']; ?>" style="background: white; min-height: 200px;"></div>
                                            <textarea name="content" id="content_<?php echo $template['id']; ?>" class="form-control email-template" style="display: none;" required><?php echo htmlspecialchars($template['content']); ?></textarea>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div style="margin-top: var(--spacing-md); padding: var(--spacing-md); background: white; border-radius: 6px; border: 1px solid #e5e7eb;">
                                    <div style="font-weight: 600; font-size: 13px; color: #666; margin-bottom: var(--spacing-sm);">Available Placeholders (drag and drop):</div>
                                    <div class="placeholders-container" style="display: flex; flex-wrap: wrap; gap: 0.5rem;">
                                        <?php
                                        $placeholders = ['client_name', 'client_spouse_name', 'plan_name', 'brand_name', 'brand_logo', 'client_phone', 'client_email',
                                                        'client_spouse_phone', 'client_spouse_email', 'affiliate_name', 'affiliate_brand', 'affiliate_email',
                                                        'affiliate_phone', 'enrollment_url', 'brand_url', 'login_url', 'docs_url', 'spouse_url'];
                                        foreach ($placeholders as $ph):
                                        ?>
                                            <span class="placeholder-tag" draggable="true"
                                                  style="background: #dcd8d4; color: #9c6046; padding: 0.25rem 0.75rem; border-radius: 4px; font-size: 12px; font-family: monospace; cursor: move; user-select: none;"
                                                  data-placeholder="[<?php echo $ph; ?>]"
                                                  ondragstart="handleDragStart(event)">
                                                [<?php echo $ph; ?>]
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <div style="margin-top: var(--spacing-md);">
                                    <button type="submit" class="btn btn-primary">Save Template</button>
                                </div>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Test Template Modal -->
    <div id="testTemplateModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0, 0, 0, 0.7); z-index: 10000; overflow-y: auto; padding: 20px;">
        <div style="max-width: 700px; margin: 50px auto; background: white; border-radius: 12px; padding: var(--spacing-lg); box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);">
            <h3 style="margin: 0 0 var(--spacing-md) 0; color: var(--color-primary);">Send Test Message</h3>
            <p style="color: #666; margin-bottom: var(--spacing-md);">Fill in placeholder values and provide a destination to send a test.</p>

            <form method="POST" action="" id="testTemplateForm" onsubmit="return handleTestFormSubmit(event)">
                <input type="hidden" name="send_test_template" value="1">
                <input type="hidden" name="template_id" id="test_template_id">
                <input type="hidden" name="template_type" id="test_template_type">

                <div class="form-group">
                    <label class="form-label" id="test_destination_label">Email Address</label>
                    <input type="text" name="test_destination" id="test_destination" class="form-control" required>
                </div>

                <div style="margin-bottom: var(--spacing-md); padding: var(--spacing-md); background: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb; max-height: 400px; overflow-y: auto;">
                    <div style="font-weight: 600; font-size: 14px; color: #666; margin-bottom: var(--spacing-sm);">Placeholder Values:</div>
                    <div id="placeholderInputs" style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--spacing-sm);"></div>
                </div>

                <div id="testPreview" style="margin-bottom: var(--spacing-md); padding: var(--spacing-md); background: white; border-radius: 6px; border: 2px solid #e5e7eb; display: none;">
                    <div style="font-weight: 600; font-size: 14px; color: #666; margin-bottom: var(--spacing-sm);">Preview:</div>
                    <div id="testSubjectPreview" style="display: none; margin-bottom: var(--spacing-sm); padding: var(--spacing-sm); background: #f3f4f6; border-radius: 4px;">
                        <strong>Subject:</strong> <span id="testSubjectContent"></span>
                    </div>
                    <div id="testContentPreview" style="padding: var(--spacing-sm); background: #f9fafb; border-radius: 4px; font-family: var(--font-family); line-height: 1.6;"></div>
                </div>

                <div id="testResultMessage" style="margin-bottom: var(--spacing-md);"></div>

                <div style="display: flex; gap: var(--spacing-sm); justify-content: flex-end;">
                    <button type="button" onclick="closeTestModal()" class="btn btn-secondary">Cancel</button>
                    <button type="button" onclick="updateTestPreview()" class="btn" style="background: #6366f1; color: white;">Preview</button>
                    <button type="submit" class="btn btn-primary" id="sendTestBtn">Send Test</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Signature Modal (for test autofill) -->
    <div id="signatureModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.7);z-index:10000;align-items:center;justify-content:center;">
        <div style="background:#fff;border-radius:8px;padding:var(--spacing-lg);max-width:600px;width:90%;">
            <h3 style="margin:0 0 var(--spacing-md) 0;color:var(--color-primary);">Draw Test Signature</h3>
            <p style="color:#666;margin-bottom:var(--spacing-md);">Draw your test signature below for Spongebob Squarepants:</p>

            <div style="border:2px solid var(--color-primary);border-radius:4px;margin-bottom:var(--spacing-md);">
                <canvas id="signatureCanvas" width="550" height="150" style="display:block;cursor:crosshair;background:#fff;"></canvas>
            </div>

            <div style="display:flex;gap:var(--spacing-sm);justify-content:space-between;">
                <button type="button" class="btn btn-secondary" onclick="clearSignature()">Clear</button>
                <div style="display:flex;gap:var(--spacing-sm);">
                    <button type="button" class="btn btn-secondary" onclick="closeSignatureModal()">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="submitSignature()">Submit & Test Autofill</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Countersign Signature Modal -->
    <div id="countersignModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.7);z-index:10000;align-items:center;justify-content:center;">
        <div style="background:#fff;border-radius:8px;padding:var(--spacing-lg);max-width:600px;width:90%;">
            <h3 style="margin:0 0 var(--spacing-md) 0;color:var(--color-primary);">Draw Countersign Signature</h3>
            <p style="color:#666;margin-bottom:var(--spacing-md);">Draw your company's countersignature below. This will be saved and applied to all contracts in this package:</p>

            <div style="border:2px solid var(--color-primary);border-radius:4px;margin-bottom:var(--spacing-md);">
                <canvas id="countersignCanvas" width="550" height="150" style="display:block;cursor:crosshair;background:#fff;"></canvas>
            </div>

            <div style="display:flex;gap:var(--spacing-sm);justify-content:space-between;">
                <button type="button" class="btn btn-secondary" onclick="clearCountersign()">Clear</button>
                <div style="display:flex;gap:var(--spacing-sm);">
                    <button type="button" class="btn btn-secondary" onclick="closeCountersignModal()">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="submitCountersign()">Save Signature</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Client Agreement Signature Modal -->
    <div id="clientAgreementModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.7);z-index:10000;align-items:center;justify-content:center;">
        <div style="background:#fff;border-radius:8px;padding:var(--spacing-lg);max-width:600px;width:90%;">
            <h3 style="margin:0 0 var(--spacing-md) 0;color:var(--color-primary);">Draw Client Test Signature</h3>
            <p style="color:#666;margin-bottom:var(--spacing-md);">Draw a test client signature below for Spongebob Squarepants. The countersign will be added automatically:</p>

            <div style="border:2px solid var(--color-primary);border-radius:4px;margin-bottom:var(--spacing-md);">
                <canvas id="clientAgreementCanvas" width="550" height="150" style="display:block;cursor:crosshair;background:#fff;"></canvas>
            </div>

            <div style="display:flex;gap:var(--spacing-sm);justify-content:space-between;">
                <button type="button" class="btn btn-secondary" onclick="clearClientAgreement()">Clear</button>
                <div style="display:flex;gap:var(--spacing-sm);">
                    <button type="button" class="btn btn-secondary" onclick="closeClientAgreementModal()">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="submitClientAgreement()">Submit & Test Autofill</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Test Package Modal (Dual Signatures) -->
    <div id="testPackageModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.7);z-index:10000;align-items:center;justify-content:center;overflow-y:auto;">
        <div style="background:#fff;border-radius:8px;padding:var(--spacing-lg);max-width:700px;width:90%;margin:20px auto;">
            <h3 style="margin:0 0 var(--spacing-md) 0;color:var(--color-primary);">Test Complete Contract Package</h3>
            <p style="color:#666;margin-bottom:var(--spacing-lg);">Draw two test signatures for Spongebob Squarepants. A complete contract package will be generated with all documents, signatures, and an XactoSign certificate.</p>

            <!-- CROA Signature -->
            <div style="margin-bottom:var(--spacing-lg);">
                <h4 style="margin:0 0 var(--spacing-sm) 0;color:#333;font-size:15px;">Signature #1: CROA Disclosure</h4>
                <div style="border:2px solid var(--color-primary);border-radius:4px;margin-bottom:var(--spacing-sm);">
                    <canvas id="testPackageCroaCanvas" width="650" height="120" style="display:block;cursor:crosshair;background:#fff;"></canvas>
                </div>
                <button type="button" class="btn btn-secondary btn-sm" onclick="clearTestPackageCroa()">Clear CROA Signature</button>
            </div>

            <!-- Client Agreement Signature -->
            <div style="margin-bottom:var(--spacing-lg);">
                <h4 style="margin:0 0 var(--spacing-sm) 0;color:#333;font-size:15px;">Signature #2: Client Agreement</h4>
                <div style="border:2px solid #28a745;border-radius:4px;margin-bottom:var(--spacing-sm);">
                    <canvas id="testPackageAgreementCanvas" width="650" height="120" style="display:block;cursor:crosshair;background:#fff;"></canvas>
                </div>
                <button type="button" class="btn btn-secondary btn-sm" onclick="clearTestPackageAgreement()">Clear Agreement Signature</button>
            </div>

            <div style="display:flex;gap:var(--spacing-sm);justify-content:space-between;">
                <button type="button" class="btn btn-secondary" onclick="closeTestPackageModal()">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="submitTestPackage()">Generate Complete Package</button>
            </div>
        </div>
    </div>

</div>

<script>
let signatureCanvas, signatureCtx;
let isDrawing = false;
let currentDocId, currentPackageId;

function openSignatureModal(docId, packageId) {
    currentDocId = docId;
    currentPackageId = packageId;

    const modal = document.getElementById('signatureModal');
    modal.style.display = 'flex';

    if (!signatureCanvas) {
        signatureCanvas = document.getElementById('signatureCanvas');
        signatureCtx = signatureCanvas.getContext('2d');

        // Set up drawing
        signatureCtx.strokeStyle = '#003399';
        signatureCtx.lineWidth = 2;
        signatureCtx.lineCap = 'round';
        signatureCtx.lineJoin = 'round';

        // Mouse events
        signatureCanvas.addEventListener('mousedown', startDrawing);
        signatureCanvas.addEventListener('mousemove', draw);
        signatureCanvas.addEventListener('mouseup', stopDrawing);
        signatureCanvas.addEventListener('mouseout', stopDrawing);

        // Touch events for mobile
        signatureCanvas.addEventListener('touchstart', handleTouch);
        signatureCanvas.addEventListener('touchmove', handleTouch);
        signatureCanvas.addEventListener('touchend', stopDrawing);
    }

    clearSignature();
}

function closeSignatureModal() {
    document.getElementById('signatureModal').style.display = 'none';
}

function startDrawing(e) {
    isDrawing = true;
    const rect = signatureCanvas.getBoundingClientRect();
    signatureCtx.beginPath();
    signatureCtx.moveTo(e.clientX - rect.left, e.clientY - rect.top);
}

function draw(e) {
    if (!isDrawing) return;
    const rect = signatureCanvas.getBoundingClientRect();
    signatureCtx.lineTo(e.clientX - rect.left, e.clientY - rect.top);
    signatureCtx.stroke();
}

function stopDrawing() {
    isDrawing = false;
}

function handleTouch(e) {
    e.preventDefault();
    const touch = e.touches[0];
    const mouseEvent = new MouseEvent(e.type === 'touchstart' ? 'mousedown' : 'mousemove', {
        clientX: touch.clientX,
        clientY: touch.clientY
    });
    signatureCanvas.dispatchEvent(mouseEvent);
}

function clearSignature() {
    if (signatureCtx) {
        signatureCtx.clearRect(0, 0, signatureCanvas.width, signatureCanvas.height);
    }
}

function submitSignature() {
    // Get signature as data URL
    const signatureData = signatureCanvas.toDataURL('image/png');

    // Create a form and submit with signature data
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'test_autofill_contract.php?doc_id=' + currentDocId + '&package_id=' + currentPackageId;
    form.target = '_blank';

    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'signature_data';
    input.value = signatureData;

    form.appendChild(input);
    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);

    closeSignatureModal();
}

// Countersign Signature Modal Functions
let countersignCanvas, countersignCtx;
let isDrawingCountersign = false;
let currentCountersignPackageId;

function openCountersignModal(packageId) {
    currentCountersignPackageId = packageId;

    const modal = document.getElementById('countersignModal');
    modal.style.display = 'flex';

    if (!countersignCanvas) {
        countersignCanvas = document.getElementById('countersignCanvas');
        countersignCtx = countersignCanvas.getContext('2d');

        // Set up drawing
        countersignCtx.strokeStyle = '#003399';
        countersignCtx.lineWidth = 2;
        countersignCtx.lineCap = 'round';
        countersignCtx.lineJoin = 'round';

        // Mouse events
        countersignCanvas.addEventListener('mousedown', startDrawingCountersign);
        countersignCanvas.addEventListener('mousemove', drawCountersign);
        countersignCanvas.addEventListener('mouseup', stopDrawingCountersign);
        countersignCanvas.addEventListener('mouseout', stopDrawingCountersign);

        // Touch events for mobile
        countersignCanvas.addEventListener('touchstart', handleTouchCountersign);
        countersignCanvas.addEventListener('touchmove', handleTouchCountersign);
        countersignCanvas.addEventListener('touchend', stopDrawingCountersign);
    }

    clearCountersign();
}

function closeCountersignModal() {
    document.getElementById('countersignModal').style.display = 'none';
}

function startDrawingCountersign(e) {
    isDrawingCountersign = true;
    const rect = countersignCanvas.getBoundingClientRect();
    countersignCtx.beginPath();
    countersignCtx.moveTo(e.clientX - rect.left, e.clientY - rect.top);
}

function drawCountersign(e) {
    if (!isDrawingCountersign) return;
    const rect = countersignCanvas.getBoundingClientRect();
    countersignCtx.lineTo(e.clientX - rect.left, e.clientY - rect.top);
    countersignCtx.stroke();
}

function stopDrawingCountersign() {
    isDrawingCountersign = false;
}

function handleTouchCountersign(e) {
    e.preventDefault();
    const touch = e.touches[0];
    const mouseEvent = new MouseEvent(e.type === 'touchstart' ? 'mousedown' : 'mousemove', {
        clientX: touch.clientX,
        clientY: touch.clientY
    });
    countersignCanvas.dispatchEvent(mouseEvent);
}

function clearCountersign() {
    if (countersignCtx) {
        countersignCtx.clearRect(0, 0, countersignCanvas.width, countersignCanvas.height);
    }
}

function submitCountersign() {
    // Get signature as data URL
    const signatureData = countersignCanvas.toDataURL('image/png');

    // Create a form and submit with signature data
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'settings.php?tab=contracts';

    const actionInput = document.createElement('input');
    actionInput.type = 'hidden';
    actionInput.name = 'save_countersign_canvas';
    actionInput.value = '1';

    const packageInput = document.createElement('input');
    packageInput.type = 'hidden';
    packageInput.name = 'package_id';
    packageInput.value = currentCountersignPackageId;

    const signatureInput = document.createElement('input');
    signatureInput.type = 'hidden';
    signatureInput.name = 'signature_data';
    signatureInput.value = signatureData;

    form.appendChild(actionInput);
    form.appendChild(packageInput);
    form.appendChild(signatureInput);
    document.body.appendChild(form);
    form.submit();
}

// Client Agreement Modal Functions
let clientAgreementCanvas, clientAgreementCtx;
let isDrawingClientAgreement = false;
let currentClientDocId, currentClientPackageId;

function openClientAgreementModal(docId, packageId) {
    currentClientDocId = docId;
    currentClientPackageId = packageId;

    const modal = document.getElementById('clientAgreementModal');
    modal.style.display = 'flex';

    if (!clientAgreementCanvas) {
        clientAgreementCanvas = document.getElementById('clientAgreementCanvas');
        clientAgreementCtx = clientAgreementCanvas.getContext('2d');

        // Set up drawing
        clientAgreementCtx.strokeStyle = '#003399';
        clientAgreementCtx.lineWidth = 2;
        clientAgreementCtx.lineCap = 'round';
        clientAgreementCtx.lineJoin = 'round';

        // Mouse events
        clientAgreementCanvas.addEventListener('mousedown', startDrawingClientAgreement);
        clientAgreementCanvas.addEventListener('mousemove', drawClientAgreement);
        clientAgreementCanvas.addEventListener('mouseup', stopDrawingClientAgreement);
        clientAgreementCanvas.addEventListener('mouseout', stopDrawingClientAgreement);

        // Touch events for mobile
        clientAgreementCanvas.addEventListener('touchstart', handleTouchClientAgreement);
        clientAgreementCanvas.addEventListener('touchmove', handleTouchClientAgreement);
        clientAgreementCanvas.addEventListener('touchend', stopDrawingClientAgreement);
    }

    clearClientAgreement();
}

function closeClientAgreementModal() {
    document.getElementById('clientAgreementModal').style.display = 'none';
}

function startDrawingClientAgreement(e) {
    isDrawingClientAgreement = true;
    const rect = clientAgreementCanvas.getBoundingClientRect();
    clientAgreementCtx.beginPath();
    clientAgreementCtx.moveTo(e.clientX - rect.left, e.clientY - rect.top);
}

function drawClientAgreement(e) {
    if (!isDrawingClientAgreement) return;
    const rect = clientAgreementCanvas.getBoundingClientRect();
    clientAgreementCtx.lineTo(e.clientX - rect.left, e.clientY - rect.top);
    clientAgreementCtx.stroke();
}

function stopDrawingClientAgreement() {
    isDrawingClientAgreement = false;
}

function handleTouchClientAgreement(e) {
    e.preventDefault();
    const touch = e.touches[0];
    const mouseEvent = new MouseEvent(e.type === 'touchstart' ? 'mousedown' : 'mousemove', {
        clientX: touch.clientX,
        clientY: touch.clientY
    });
    clientAgreementCanvas.dispatchEvent(mouseEvent);
}

function clearClientAgreement() {
    if (clientAgreementCtx) {
        clientAgreementCtx.clearRect(0, 0, clientAgreementCanvas.width, clientAgreementCanvas.height);
    }
}

function submitClientAgreement() {
    // Get signature as data URL
    const signatureData = clientAgreementCanvas.toDataURL('image/png');

    // Create a form and submit with signature data
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'test_autofill_contract.php?doc_id=' + currentClientDocId + '&package_id=' + currentClientPackageId;
    form.target = '_blank';

    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'client_signature_data';
    input.value = signatureData;

    form.appendChild(input);
    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);

    closeClientAgreementModal();
}

// Test Package Modal Functions
let testPackageCroaCanvas, testPackageCroaCtx;
let testPackageAgreementCanvas, testPackageAgreementCtx;
let isDrawingTestCroa = false;
let isDrawingTestAgreement = false;
let currentTestPackageId;

function setupCanvas(canvas, contextVar, drawingVar, drawFunc, stopFunc, touchFunc) {
    const ctx = canvas.getContext('2d');
    ctx.strokeStyle = '#003399';
    ctx.lineWidth = 2;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';

    canvas.addEventListener('mousedown', drawFunc);
    canvas.addEventListener('mousemove', function(e) {
        if (drawingVar === 'croa' && !isDrawingTestCroa) return;
        if (drawingVar === 'agreement' && !isDrawingTestAgreement) return;
        const rect = canvas.getBoundingClientRect();
        ctx.lineTo(e.clientX - rect.left, e.clientY - rect.top);
        ctx.stroke();
    });
    canvas.addEventListener('mouseup', stopFunc);
    canvas.addEventListener('mouseout', stopFunc);
    canvas.addEventListener('touchstart', touchFunc);
    canvas.addEventListener('touchmove', touchFunc);
    canvas.addEventListener('touchend', stopFunc);

    return ctx;
}

function openTestPackageModal(packageId) {
    currentTestPackageId = packageId;

    const modal = document.getElementById('testPackageModal');
    modal.style.display = 'flex';

    if (!testPackageCroaCanvas) {
        testPackageCroaCanvas = document.getElementById('testPackageCroaCanvas');
        testPackageCroaCtx = testPackageCroaCanvas.getContext('2d');
        testPackageCroaCtx.strokeStyle = '#003399';
        testPackageCroaCtx.lineWidth = 2;
        testPackageCroaCtx.lineCap = 'round';
        testPackageCroaCtx.lineJoin = 'round';

        testPackageCroaCanvas.addEventListener('mousedown', startDrawingTestCroa);
        testPackageCroaCanvas.addEventListener('mousemove', drawTestCroa);
        testPackageCroaCanvas.addEventListener('mouseup', stopDrawingTestCroa);
        testPackageCroaCanvas.addEventListener('mouseout', stopDrawingTestCroa);
        testPackageCroaCanvas.addEventListener('touchstart', handleTouchTestCroa);
        testPackageCroaCanvas.addEventListener('touchmove', handleTouchTestCroa);
        testPackageCroaCanvas.addEventListener('touchend', stopDrawingTestCroa);
    }

    if (!testPackageAgreementCanvas) {
        testPackageAgreementCanvas = document.getElementById('testPackageAgreementCanvas');
        testPackageAgreementCtx = testPackageAgreementCanvas.getContext('2d');
        testPackageAgreementCtx.strokeStyle = '#003399';
        testPackageAgreementCtx.lineWidth = 2;
        testPackageAgreementCtx.lineCap = 'round';
        testPackageAgreementCtx.lineJoin = 'round';

        testPackageAgreementCanvas.addEventListener('mousedown', startDrawingTestAgreement);
        testPackageAgreementCanvas.addEventListener('mousemove', drawTestAgreement);
        testPackageAgreementCanvas.addEventListener('mouseup', stopDrawingTestAgreement);
        testPackageAgreementCanvas.addEventListener('mouseout', stopDrawingTestAgreement);
        testPackageAgreementCanvas.addEventListener('touchstart', handleTouchTestAgreement);
        testPackageAgreementCanvas.addEventListener('touchmove', handleTouchTestAgreement);
        testPackageAgreementCanvas.addEventListener('touchend', stopDrawingTestAgreement);
    }

    clearTestPackageCroa();
    clearTestPackageAgreement();
}

function closeTestPackageModal() {
    document.getElementById('testPackageModal').style.display = 'none';
}

function startDrawingTestCroa(e) {
    isDrawingTestCroa = true;
    const rect = testPackageCroaCanvas.getBoundingClientRect();
    testPackageCroaCtx.beginPath();
    testPackageCroaCtx.moveTo(e.clientX - rect.left, e.clientY - rect.top);
}

function drawTestCroa(e) {
    if (!isDrawingTestCroa) return;
    const rect = testPackageCroaCanvas.getBoundingClientRect();
    testPackageCroaCtx.lineTo(e.clientX - rect.left, e.clientY - rect.top);
    testPackageCroaCtx.stroke();
}

function stopDrawingTestCroa() {
    isDrawingTestCroa = false;
}

function handleTouchTestCroa(e) {
    e.preventDefault();
    const touch = e.touches[0];
    const mouseEvent = new MouseEvent(e.type === 'touchstart' ? 'mousedown' : 'mousemove', {
        clientX: touch.clientX,
        clientY: touch.clientY
    });
    testPackageCroaCanvas.dispatchEvent(mouseEvent);
}

function clearTestPackageCroa() {
    if (testPackageCroaCtx) {
        testPackageCroaCtx.clearRect(0, 0, testPackageCroaCanvas.width, testPackageCroaCanvas.height);
    }
}

function startDrawingTestAgreement(e) {
    isDrawingTestAgreement = true;
    const rect = testPackageAgreementCanvas.getBoundingClientRect();
    testPackageAgreementCtx.beginPath();
    testPackageAgreementCtx.moveTo(e.clientX - rect.left, e.clientY - rect.top);
}

function drawTestAgreement(e) {
    if (!isDrawingTestAgreement) return;
    const rect = testPackageAgreementCanvas.getBoundingClientRect();
    testPackageAgreementCtx.lineTo(e.clientX - rect.left, e.clientY - rect.top);
    testPackageAgreementCtx.stroke();
}

function stopDrawingTestAgreement() {
    isDrawingTestAgreement = false;
}

function handleTouchTestAgreement(e) {
    e.preventDefault();
    const touch = e.touches[0];
    const mouseEvent = new MouseEvent(e.type === 'touchstart' ? 'mousedown' : 'mousemove', {
        clientX: touch.clientX,
        clientY: touch.clientY
    });
    testPackageAgreementCanvas.dispatchEvent(mouseEvent);
}

function clearTestPackageAgreement() {
    if (testPackageAgreementCtx) {
        testPackageAgreementCtx.clearRect(0, 0, testPackageAgreementCanvas.width, testPackageAgreementCanvas.height);
    }
}

function submitTestPackage() {
    // Get both signatures as data URLs
    const croaSignature = testPackageCroaCanvas.toDataURL('image/png');
    const agreementSignature = testPackageAgreementCanvas.toDataURL('image/png');

    // Show processing message in modal
    const modal = document.getElementById('testPackageModal');
    const modalContent = modal.querySelector('div > div');
    modalContent.innerHTML = `
        <h3 style="margin:0 0 var(--spacing-md) 0;color:var(--color-primary);">Generating Package...</h3>
        <div style="text-align:center;padding:var(--spacing-lg);">
            <div style="border:4px solid #f3f3f3;border-top:4px solid var(--color-primary);border-radius:50%;width:60px;height:60px;animation:spin 1s linear infinite;margin:0 auto var(--spacing-md);"></div>
            <p style="color:#666;font-size:14px;margin:0 0 var(--spacing-sm) 0;">Processing contract package...</p>
            <p style="color:#999;font-size:12px;margin:0;">This may take 10-30 seconds</p>
            <div id="progressMessage" style="margin-top:var(--spacing-md);color:var(--color-primary);font-weight:600;"></div>
        </div>
        <style>
            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
        </style>
    `;

    // Submit via AJAX to start background processing
    fetch('test_package_start.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams({
            package_id: currentTestPackageId,
            croa_signature_data: croaSignature,
            agreement_signature_data: agreementSignature
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Start polling for completion
            pollPackageStatus(data.test_package_id, data.xactosign_package_id);
        } else {
            showError('Failed to start package generation: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(error => {
        showError('Error: ' + error.message);
    });
}

function pollPackageStatus(testPackageId, xactosignId) {
    const progressMsg = document.getElementById('progressMessage');
    if (progressMsg) {
        progressMsg.textContent = 'Package ID: ' + xactosignId;
    }

    const checkStatus = () => {
        fetch('package_status.php?id=' + testPackageId)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (data.status === 'completed') {
                        // Success! Open the PDF
                        if (progressMsg) {
                            progressMsg.textContent = 'Complete! Opening PDF...';
                        }
                        setTimeout(() => {
                            window.open('view_package.php?id=' + testPackageId, '_blank');
                            closeTestPackageModal();
                            // Reset modal content for next use
                            location.reload();
                        }, 500);
                    } else if (data.status === 'failed') {
                        showError('Package generation failed: ' + (data.error_message || 'Unknown error'));
                    } else {
                        // Still processing, check again
                        setTimeout(checkStatus, 2000); // Check every 2 seconds
                    }
                } else {
                    showError('Error checking status: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(error => {
                showError('Error: ' + error.message);
            });
    };

    // Start checking after a brief delay
    setTimeout(checkStatus, 2000);
}

function showError(message) {
    const modal = document.getElementById('testPackageModal');
    const modalContent = modal.querySelector('div > div');
    modalContent.innerHTML = `
        <h3 style="margin:0 0 var(--spacing-md) 0;color:#dc3545;">Error</h3>
        <div style="padding:var(--spacing-lg);text-align:center;">
            <p style="color:#666;margin-bottom:var(--spacing-lg);">${message}</p>
            <button type="button" class="btn btn-secondary" onclick="closeTestPackageModal(); location.reload();">Close</button>
        </div>
    `;
}

// Signature Coordinate Management Functions
function addSignature(docId) {
    const container = document.getElementById('signatures_container_' + docId);
    const countInput = document.getElementById('sig_count_' + docId);
    const currentCount = parseInt(countInput.value);
    const newIndex = currentCount;

    const sigHTML = `
        <div class="signature-item" data-doc="${docId}" data-index="${newIndex}" style="background:#fff;border:1px solid #e5e7eb;border-radius:4px;padding:var(--spacing-sm);margin-bottom:var(--spacing-sm);">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.5rem;">
                <div style="font-weight:600;font-size:12px;color:#374151;">
                    Signature #<span class="sig-number">${newIndex + 1}</span>
                </div>
                <button type="button" onclick="removeSignature(${docId}, this)" class="btn btn-danger" style="font-size:10px;padding:0.2rem 0.5rem;">✕ Remove</button>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;margin-bottom:0.5rem;">
                <div>
                    <label style="font-size:10px;color:#6b7280;display:block;">Signature Type</label>
                    <select name="sig_${newIndex}_type" class="form-control" style="font-size:11px;padding:0.25rem;height:28px;">
                        <option value="client">Client</option>
                        <option value="countersign">Company/Countersign</option>
                        <option value="witness">Witness</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div>
                    <label style="font-size:10px;color:#6b7280;display:block;">Label</label>
                    <input type="text" name="sig_${newIndex}_label" value="New Signature"
                           class="form-control" style="font-size:11px;padding:0.25rem;height:28px;" placeholder="e.g., Client Signature">
                </div>
            </div>

            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:0.25rem;">
                <div>
                    <label style="font-size:10px;color:#6b7280;display:block;">Page</label>
                    <input type="text" name="sig_${newIndex}_page" value="last"
                           class="form-control" style="font-size:11px;padding:0.25rem;height:28px;" placeholder="last">
                </div>
                <div>
                    <label style="font-size:10px;color:#6b7280;display:block;">Top-Left X1</label>
                    <input type="number" step="0.1" name="sig_${newIndex}_x1" value="100"
                           class="form-control" style="font-size:11px;padding:0.25rem;height:28px;">
                </div>
                <div>
                    <label style="font-size:10px;color:#6b7280;display:block;">Top-Left Y1</label>
                    <input type="number" step="0.1" name="sig_${newIndex}_y1" value="500"
                           class="form-control" style="font-size:11px;padding:0.25rem;height:28px;">
                </div>
                <div></div>
                <div>
                    <label style="font-size:10px;color:#6b7280;display:block;">Bottom-Right X2</label>
                    <input type="number" step="0.1" name="sig_${newIndex}_x2" value="300"
                           class="form-control" style="font-size:11px;padding:0.25rem;height:28px;">
                </div>
                <div>
                    <label style="font-size:10px;color:#6b7280;display:block;">Bottom-Right Y2</label>
                    <input type="number" step="0.1" name="sig_${newIndex}_y2" value="520"
                           class="form-control" style="font-size:11px;padding:0.25rem;height:28px;">
                </div>
            </div>
        </div>
    `;

    container.insertAdjacentHTML('beforeend', sigHTML);
    countInput.value = newIndex + 1;
    renumberSignatures(docId);
}

function removeSignature(docId, button) {
    const sigItem = button.closest('.signature-item');
    sigItem.remove();
    renumberSignatures(docId);

    // Update count
    const container = document.getElementById('signatures_container_' + docId);
    const countInput = document.getElementById('sig_count_' + docId);
    const remaining = container.querySelectorAll('.signature-item').length;
    countInput.value = remaining;
}

function renumberSignatures(docId) {
    const container = document.getElementById('signatures_container_' + docId);
    const items = container.querySelectorAll('.signature-item');

    items.forEach((item, index) => {
        // Update visual number
        const numberSpan = item.querySelector('.sig-number');
        if (numberSpan) {
            numberSpan.textContent = index + 1;
        }

        // Update input names
        item.querySelectorAll('input, select').forEach(input => {
            const name = input.name;
            if (name && name.startsWith('sig_')) {
                const parts = name.split('_');
                parts[1] = index; // Update the index
                input.name = parts.join('_');
            }
        });

        // Update data-index
        item.setAttribute('data-index', index);
    });
}

// ===== Communication Templates Functions =====
const placeholders = ['client_name', 'client_spouse_name', 'plan_name', 'brand_name', 'brand_logo', 'client_phone', 'client_email',
                     'client_spouse_phone', 'client_spouse_email', 'affiliate_name', 'affiliate_brand', 'affiliate_email',
                     'affiliate_phone', 'enrollment_url', 'brand_url', 'login_url', 'docs_url', 'spouse_url'];

let currentTestTemplateId = null;
let currentTestTemplateType = null;
let currentTestTemplateContent = '';
let currentTestTemplateSubject = '';

function openTestModal(templateId, templateType) {
    currentTestTemplateId = templateId;
    currentTestTemplateType = templateType;

    // Get template content from the form
    if (templateType === 'email' && window.quillEditors && window.quillEditors[templateId]) {
        // Get content from Quill editor
        currentTestTemplateContent = window.quillEditors[templateId].root.innerHTML;
        const subjectInput = document.querySelector('#template_form_' + templateId + ' input[name="subject"]');
        currentTestTemplateSubject = subjectInput ? subjectInput.value : '';
    } else {
        // Get content from textarea (SMS)
        currentTestTemplateContent = document.getElementById('content_' + templateId).value;
        currentTestTemplateSubject = '';
    }

    document.getElementById('test_template_id').value = templateId;
    document.getElementById('test_template_type').value = templateType;

    // Update destination label
    const destLabel = document.getElementById('test_destination_label');
    const destInput = document.getElementById('test_destination');
    const sendTestBtn = document.getElementById('sendTestBtn');

    if (templateType === 'sms') {
        destLabel.textContent = 'Phone Number';
        destInput.placeholder = '(555) 555-5555';
        destInput.type = 'tel';
        sendTestBtn.textContent = 'Send Test SMS';
    } else {
        destLabel.textContent = 'Email Address';
        destInput.placeholder = 'test@example.com';
        destInput.type = 'email';
        sendTestBtn.textContent = 'Send Test';
    }

    // Always enable the button
    sendTestBtn.disabled = false;
    sendTestBtn.style.opacity = '1';
    destInput.value = '';

    // Generate placeholder inputs
    const placeholderInputsDiv = document.getElementById('placeholderInputs');
    placeholderInputsDiv.innerHTML = '';

    placeholders.forEach(ph => {
        const inputGroup = document.createElement('div');
        inputGroup.style.marginBottom = '0.5rem';

        const label = document.createElement('label');
        label.style.fontSize = '12px';
        label.style.color = '#666';
        label.style.display = 'block';
        label.style.marginBottom = '0.25rem';
        label.textContent = ph.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());

        const input = document.createElement('input');
        input.type = 'text';
        input.name = 'placeholder_' + ph;
        input.className = 'form-control';
        input.style.fontSize = '13px';
        input.style.padding = '0.5rem';
        input.value = getSampleValue(ph);
        input.placeholder = '[' + ph + ']';

        inputGroup.appendChild(label);
        inputGroup.appendChild(input);
        placeholderInputsDiv.appendChild(inputGroup);
    });

    document.getElementById('testPreview').style.display = 'none';
    document.getElementById('testTemplateModal').style.display = 'block';
}

function closeTestModal() {
    document.getElementById('testTemplateModal').style.display = 'none';
}

function getSampleValue(placeholder) {
    const samples = {
        'client_name': 'John Smith',
        'client_spouse_name': 'Jane Smith',
        'plan_name': 'Individual Plan',
        'brand_name': window.BRAND_NAME || 'Your Company Name',
        'brand_logo': window.BRAND_LOGO_URL || 'https://yourdomain.com/src/img/primary_logo.png',
        'client_phone': '(555) 123-4567',
        'client_email': 'john@example.com',
        'client_spouse_phone': '(555) 765-4321',
        'client_spouse_email': 'jane@example.com',
        'affiliate_name': 'Bob Johnson',
        'affiliate_brand': 'Johnson Affiliates',
        'affiliate_email': 'bob@example.com',
        'affiliate_phone': '(555) 999-8888',
        'enrollment_url': 'https://sns-enroll.transxacto.net/enroll/?session=ABC1-2345',
        'brand_url': 'https://thesheridanco.com',
        'login_url': 'https://sns-enroll.transxacto.net/admin/login.php',
        'docs_url': 'https://sns-enroll.transxacto.net/documents',
        'spouse_url': 'https://sns-enroll.transxacto.net/enroll/spouse.php?session=ABC1-2345'
    };
    return samples[placeholder] || '[' + placeholder + ']';
}

function handleTestFormSubmit(event) {
    const templateType = document.getElementById('test_template_type').value;

    // For SMS, handle via AJAX like the working test SMS
    if (templateType === 'sms') {
        event.preventDefault();

        const destination = document.getElementById('test_destination').value;
        const sendBtn = document.getElementById('sendTestBtn');
        const resultDiv = document.getElementById('testResultMessage');

        // Get the preview content (which has placeholders replaced)
        let content = currentTestTemplateContent;
        placeholders.forEach(ph => {
            const input = document.querySelector('input[name="placeholder_' + ph + '"]');
            if (input) {
                let value = input.value || '[' + ph + ']';
                const regex = new RegExp('\\[' + ph + '\\]', 'g');
                content = content.replace(regex, value);
            }
        });

        // Strip HTML tags for SMS
        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = content;
        content = tempDiv.textContent || tempDiv.innerText || '';
        content = content.trim();

        if (!content) {
            resultDiv.innerHTML = '<div class="alert alert-danger">Message content is empty. Please fill in the required placeholders.</div>';
            return false;
        }

        sendBtn.disabled = true;
        sendBtn.textContent = 'Sending...';
        resultDiv.innerHTML = '<div class="alert" style="background: #e3f2fd; color: #1976d2;">Sending SMS...</div>';

        // Send via the same endpoint that works for Test Outbound SMS
        fetch('/src/outbound_sms.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({to: destination, message: content})
        })
        .then(res => res.json())
        .then(data => {
            sendBtn.disabled = false;
            sendBtn.textContent = 'Send Test SMS';

            if (data.status === 'success') {
                resultDiv.innerHTML = '<div class="alert alert-success">Test SMS sent successfully! Total chunks: ' + data.total_chunks + '</div>';
                setTimeout(() => {
                    closeTestModal();
                    location.reload();
                }, 2000);
            } else {
                let errorMsg = data.message || 'Unknown error';
                if (data.results && data.results.length > 0) {
                    const firstError = data.results[0].result;
                    if (firstError && firstError.message) {
                        errorMsg = firstError.message;
                    }
                }
                resultDiv.innerHTML = '<div class="alert alert-danger">Failed to send SMS: ' + errorMsg + '</div>';
            }
        })
        .catch(err => {
            sendBtn.disabled = false;
            sendBtn.textContent = 'Send Test SMS';
            resultDiv.innerHTML = '<div class="alert alert-danger">Network error: ' + err.message + '</div>';
        });

        return false;
    }

    // For email, allow normal form submission
    return true;
}

function updateTestPreview() {
    let content = currentTestTemplateContent;
    let subject = currentTestTemplateSubject;

    // Replace placeholders with values from inputs
    placeholders.forEach(ph => {
        const input = document.querySelector('input[name="placeholder_' + ph + '"]');
        if (input) {
            let value = input.value || '[' + ph + ']';

            // Special handling for brand_logo - convert URL to img tag
            if (ph === 'brand_logo' && value !== '[brand_logo]') {
                value = '<img src="' + value + '" alt="' + (window.BRAND_NAME || 'Brand Logo') + '" style="max-width: 250px; height: auto;">';
            }

            const regex = new RegExp('\\[' + ph + '\\]', 'g');
            content = content.replace(regex, value);
            subject = subject.replace(regex, value);
        }
    });

    // Show preview
    const previewDiv = document.getElementById('testPreview');
    const contentDiv = document.getElementById('testContentPreview');
    const subjectDiv = document.getElementById('testSubjectPreview');
    const subjectContent = document.getElementById('testSubjectContent');

    if (currentTestTemplateType === 'email') {
        subjectDiv.style.display = 'block';
        subjectContent.textContent = subject;
        contentDiv.innerHTML = content;
    } else {
        subjectDiv.style.display = 'none';
        contentDiv.textContent = content;
    }

    previewDiv.style.display = 'block';
}

// Drag and drop for placeholders
let draggedPlaceholder = '';

function handleDragStart(e) {
    draggedPlaceholder = e.target.dataset.placeholder;
    e.dataTransfer.effectAllowed = 'copy';
    e.dataTransfer.setData('text/plain', draggedPlaceholder);
}

// Add drop zones to all textareas
document.addEventListener('DOMContentLoaded', function() {
    // Initialize Quill editors for email templates
    const quillEditors = {};
    document.querySelectorAll('.email-template').forEach(textarea => {
        const templateId = textarea.id.replace('content_', '');
        const editorDiv = document.getElementById('quill_editor_' + templateId);

        if (editorDiv && typeof Quill !== 'undefined') {
            const quill = new Quill(editorDiv, {
                theme: 'snow',
                modules: {
                    toolbar: [
                        [{ 'header': [1, 2, 3, false] }],
                        ['bold', 'italic', 'underline', 'strike'],
                        [{ 'color': [] }, { 'background': [] }],
                        [{ 'list': 'ordered'}, { 'list': 'bullet' }],
                        [{ 'align': [] }],
                        ['link', 'image'],
                        ['clean']
                    ]
                }
            });

            // Set initial content
            const htmlContent = textarea.value;
            quill.root.innerHTML = htmlContent;

            // Update hidden textarea on change
            quill.on('text-change', function() {
                textarea.value = quill.root.innerHTML;
            });

            // Store quill instance
            quillEditors[templateId] = quill;

            // Enable drag and drop for Quill editor
            editorDiv.addEventListener('dragover', function(e) {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'copy';
                editorDiv.style.borderColor = '#9c6046';
            });

            editorDiv.addEventListener('dragleave', function(e) {
                editorDiv.style.borderColor = '';
            });

            editorDiv.addEventListener('drop', function(e) {
                e.preventDefault();
                editorDiv.style.borderColor = '';

                const placeholder = e.dataTransfer.getData('text/plain');
                const selection = quill.getSelection();
                const index = selection ? selection.index : quill.getLength();

                // Insert placeholder as text
                quill.insertText(index, placeholder);
                quill.setSelection(index + placeholder.length);
            });
        }
    });

    // Add drop zones for SMS textareas
    const smsTextareas = document.querySelectorAll('.sms-template');
    smsTextareas.forEach(textarea => {
        textarea.addEventListener('dragover', function(e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'copy';
            this.style.borderColor = '#9c6046';
            this.style.backgroundColor = '#fef3f2';
        });

        textarea.addEventListener('dragleave', function(e) {
            this.style.borderColor = '';
            this.style.backgroundColor = '';
        });

        textarea.addEventListener('drop', function(e) {
            e.preventDefault();
            this.style.borderColor = '';
            this.style.backgroundColor = '';

            const placeholder = e.dataTransfer.getData('text/plain');
            const cursorPos = this.selectionStart;
            const textBefore = this.value.substring(0, cursorPos);
            const textAfter = this.value.substring(cursorPos);

            this.value = textBefore + placeholder + textAfter;
            this.selectionStart = this.selectionEnd = cursorPos + placeholder.length;
            this.focus();

            // Trigger change event to update SMS counter if needed
            this.dispatchEvent(new Event('input'));
        });
    });

    // Initialize SMS character counters
    smsTextareas.forEach(textarea => {
        updateSMSCount(textarea);
        textarea.addEventListener('input', function() {
            updateSMSCount(this);
        });
    });

    // Store Quill editors globally for access in test modal
    window.quillEditors = quillEditors;
});

function updateSMSCount(textarea) {
    const id = textarea.id.replace('content_', '');
    const counter = document.getElementById('sms_count_' + id);
    if (counter) {
        const length = textarea.value.length;
        const messages = Math.ceil(length / 160);
        const remaining = (messages * 160) - length;
        counter.textContent = `${length} chars | ${messages} message${messages !== 1 ? 's' : ''} | ${remaining} remaining`;
        counter.style.color = messages > 1 ? '#f59e0b' : '#666';
    }
}

// Credit Repair Cloud Test Function
let lastCreatedRecordId = null;

function testCrcApi() {
    const resultDiv = document.getElementById('crc-test-result');
    const btn = event.target;

    btn.disabled = true;
    btn.textContent = 'Testing...';

    resultDiv.style.display = 'block';
    resultDiv.innerHTML = '<div style="padding: 1rem; background: #f0f9ff; border: 1px solid #0ea5e9; border-radius: 8px; color: #0369a1;">Testing API connection...</div>';

    fetch('test_crc_api.php')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                lastCreatedRecordId = data.record_id;
                // Show memo test button if we have a valid record ID
                if (lastCreatedRecordId && lastCreatedRecordId !== 'created' && lastCreatedRecordId !== 'no-id-returned') {
                    document.getElementById('memoTestBtn').style.display = 'inline-block';
                }
                let debugHtml = '';
                if (data.raw_response) {
                    debugHtml = `
                        <details style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #10b981;">
                            <summary style="cursor: pointer; font-weight: 600;">🔍 insertRecord API Response</summary>
                            <pre style="background: #f9fafb; padding: 0.5rem; border-radius: 4px; overflow: auto; font-size: 11px; margin-top: 0.5rem;">${data.raw_response.replace(/</g, '&lt;').replace(/>/g, '&gt;')}</pre>
                        </details>
                    `;
                }
                resultDiv.innerHTML = `
                    <div style="padding: 1rem; background: #d1fae5; border: 1px solid #10b981; border-radius: 8px; color: #065f46;">
                        <strong>✓ Success!</strong> ${data.message}<br>
                        <strong>Record ID:</strong> ${data.record_id}<br>
                        <strong>Test Lead:</strong> ${data.test_data.first_name} ${data.test_data.last_name}<br>
                        <strong>Email:</strong> ${data.test_data.email}<br>
                        <strong>Phone:</strong> ${data.test_data.phone}<br>
                        <small style="display: block; margin-top: 0.5rem; opacity: 0.8;">Check your CreditRepairCloud account for this test lead.</small>
                        ${debugHtml}
                        <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #10b981;">
                            <button type="button" class="btn btn-secondary" onclick="convertToClient('${data.record_id}')" style="width: 100%;">
                                Convert to Client (Test Update)
                            </button>
                        </div>
                    </div>
                `;
            } else {
                let detailsHtml = '';
                if (data.details) {
                    detailsHtml += `<br><strong>Details:</strong> ${data.details}`;
                }
                if (data.recent_logs) {
                    detailsHtml += `<br><details style="margin-top: 0.5rem;"><summary>Recent Logs</summary><pre style="font-size: 11px; margin-top: 0.5rem; overflow: auto; max-height: 200px;">${data.recent_logs}</pre></details>`;
                }
                if (data.credentials_check) {
                    detailsHtml += `<br><small>Auth Key Length: ${data.credentials_check.auth_key_length} | Secret Key Length: ${data.credentials_check.secret_key_length}</small>`;
                }

                resultDiv.innerHTML = `
                    <div style="padding: 1rem; background: #fee2e2; border: 1px solid #ef4444; border-radius: 8px; color: #991b1b;">
                        <strong>✗ Error:</strong> ${data.error}${detailsHtml}
                    </div>
                `;
            }
        })
        .catch(error => {
            resultDiv.innerHTML = `
                <div style="padding: 1rem; background: #fee2e2; border: 1px solid #ef4444; border-radius: 8px; color: #991b1b;">
                    <strong>✗ Request Failed:</strong> ${error.message}
                </div>
            `;
        })
        .finally(() => {
            btn.disabled = false;
            btn.textContent = 'Test API Connection';
        });
}

// Zoho Books Test Function
function testZohoApi() {
    const resultDiv = document.getElementById('zoho-test-result');
    const btn = event.target;

    btn.disabled = true;
    btn.textContent = 'Testing...';

    resultDiv.style.display = 'block';
    resultDiv.innerHTML = '<div style="padding: 1rem; background: #f0f9ff; border: 1px solid #0ea5e9; border-radius: 8px; color: #0369a1;">Testing Zoho Books API connection...</div>';

    fetch('test_zoho_api.php')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                let debugHtml = '';
                if (data.raw_response) {
                    debugHtml = `
                        <details style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #10b981;">
                            <summary style="cursor: pointer; font-weight: 600;">🔍 API Response</summary>
                            <pre style="background: #f9fafb; padding: 0.5rem; border-radius: 4px; overflow: auto; font-size: 11px; margin-top: 0.5rem;">${JSON.stringify(data.raw_response, null, 2)}</pre>
                        </details>
                    `;
                }

                resultDiv.innerHTML = `
                    <div style="padding: 1rem; background: #ecfdf5; border: 1px solid #10b981; border-radius: 8px; color: #065f46;">
                        <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem;">
                            <svg width="20" height="20" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                            </svg>
                            <strong>Success!</strong>
                        </div>
                        <p style="margin: 0.5rem 0;">${data.message}</p>
                        ${data.contact_id ? `<p style="margin: 0.5rem 0;"><strong>Contact ID:</strong> ${data.contact_id}</p>` : ''}
                        ${data.test_data ? `
                            <p style="margin: 0.5rem 0;"><strong>Test Data:</strong> ${data.test_data.first_name} ${data.test_data.last_name} (${data.test_data.email})</p>
                        ` : ''}
                        ${debugHtml}
                        <small style="display: block; margin-top: 0.5rem; opacity: 0.8;">Check your Zoho Books account for this test contact.</small>
                    </div>
                `;
            } else {
                let debugHtml = '';
                if (data.credentials_check) {
                    debugHtml += `
                        <details style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #ef4444;">
                            <summary style="cursor: pointer; font-weight: 600;">🔧 Credentials Check</summary>
                            <pre style="background: #f9fafb; padding: 0.5rem; border-radius: 4px; overflow: auto; font-size: 11px; margin-top: 0.5rem;">${JSON.stringify(data.credentials_check, null, 2)}</pre>
                        </details>
                    `;
                }
                if (data.recent_logs) {
                    debugHtml += `
                        <details style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #ef4444;">
                            <summary style="cursor: pointer; font-weight: 600;">📋 Recent Logs</summary>
                            <pre style="background: #f9fafb; padding: 0.5rem; border-radius: 4px; overflow: auto; font-size: 11px; margin-top: 0.5rem; max-height: 200px;">${data.recent_logs}</pre>
                        </details>
                    `;
                }

                resultDiv.innerHTML = `
                    <div style="padding: 1rem; background: #fef2f2; border: 1px solid #ef4444; border-radius: 8px; color: #991b1b;">
                        <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem;">
                            <svg width="20" height="20" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                            </svg>
                            <strong>Error</strong>
                        </div>
                        <p style="margin: 0.5rem 0;">${data.error}</p>
                        ${data.details ? `<p style="margin: 0.5rem 0; font-size: 13px;">${data.details}</p>` : ''}
                        ${debugHtml}
                    </div>
                `;
            }
        })
        .catch(error => {
            resultDiv.innerHTML = `
                <div style="padding: 1rem; background: #fef2f2; border: 1px solid #ef4444; border-radius: 8px; color: #991b1b;">
                    <strong>Request failed:</strong> ${error.message}
                </div>
            `;
        })
        .finally(() => {
            btn.disabled = false;
            btn.textContent = 'Test Zoho Books';
        });
}

let lastCreatedSystemeContactId = null;

function testSystemeApi() {
    const resultDiv = document.getElementById('systeme-test-result');
    const btn = event.target;

    btn.disabled = true;
    btn.textContent = 'Testing...';

    resultDiv.style.display = 'block';
    resultDiv.innerHTML = '<div style="padding: 1rem; background: #f0f9ff; border: 1px solid #0ea5e9; border-radius: 8px; color: #0369a1;">Testing Systeme.io API connection...</div>';

    fetch('test_systeme_api.php')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                lastCreatedSystemeContactId = data.contact_id;
                // Show update button if we have a valid contact ID
                if (lastCreatedSystemeContactId && lastCreatedSystemeContactId !== 'no-id-returned') {
                    document.getElementById('systemeUpdateBtn').style.display = 'inline-block';
                }
                let debugHtml = '';
                if (data.raw_response) {
                    debugHtml = `
                        <details style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #10b981;">
                            <summary style="cursor: pointer; font-weight: 600;">🔍 API Response</summary>
                            <pre style="background: #f9fafb; padding: 0.5rem; border-radius: 4px; overflow: auto; font-size: 11px; margin-top: 0.5rem;">${JSON.stringify(data.raw_response, null, 2)}</pre>
                        </details>
                    `;
                }
                resultDiv.innerHTML = `
                    <div style="padding: 1rem; background: #d1fae5; border: 1px solid #10b981; border-radius: 8px; color: #065f46;">
                        <strong>✓ Success!</strong> ${data.message}<br>
                        <strong>Contact ID:</strong> ${data.contact_id || 'N/A'}<br>
                        <strong>Test Contact:</strong> ${data.test_data.first_name} ${data.test_data.last_name}<br>
                        <strong>Email:</strong> ${data.test_data.email}<br>
                        <strong>Phone:</strong> ${data.test_data.phone || 'N/A'}<br>
                        <strong>Address:</strong> ${data.test_data.address_line1 || 'N/A'}, ${data.test_data.city || 'N/A'}, ${data.test_data.state || 'N/A'} ${data.test_data.zip_code || 'N/A'}<br>
                        <small style="display: block; margin-top: 0.5rem; opacity: 0.8;">Check your Systeme.io account for this test contact.</small>
                        ${debugHtml}
                    </div>
                `;
            } else {
                let detailsHtml = '';
                if (data.details) {
                    detailsHtml += `<br><strong>Details:</strong> ${data.details}`;
                }
                if (data.recent_logs) {
                    detailsHtml += `<br><details style="margin-top: 0.5rem;"><summary>Recent Logs</summary><pre style="font-size: 11px; margin-top: 0.5rem; overflow: auto; max-height: 200px;">${data.recent_logs}</pre></details>`;
                }

                resultDiv.innerHTML = `
                    <div style="padding: 1rem; background: #fee2e2; border: 1px solid #ef4444; border-radius: 8px; color: #991b1b;">
                        <strong>✗ Error:</strong> ${data.error}${detailsHtml}
                    </div>
                `;
            }
        })
        .catch(error => {
            resultDiv.innerHTML = `
                <div style="padding: 1rem; background: #fee2e2; border: 1px solid #ef4444; border-radius: 8px; color: #991b1b;">
                    <strong>✗ Request Failed:</strong> ${error.message}
                </div>
            `;
        })
        .finally(() => {
            btn.disabled = false;
            btn.textContent = 'Test Systeme.io';
        });
}

function openSystemeUpdateModal() {
    document.getElementById('systeme_contact_id').value = lastCreatedSystemeContactId;
    document.getElementById('systeme_first_name').value = 'Updated';
    document.getElementById('systeme_last_name').value = 'TestContact';
    document.getElementById('systeme_phone').value = '555-999-8888';
    document.getElementById('systeme_address').value = '789 New Avenue';
    document.getElementById('systeme_city').value = 'Houston';
    document.getElementById('systeme_state').value = 'TX';
    document.getElementById('systeme_zip').value = '77001';
    document.getElementById('systemeUpdateResult').innerHTML = '';
    document.getElementById('systemeUpdateModal').style.display = 'flex';
}

function closeSystemeUpdateModal() {
    document.getElementById('systemeUpdateModal').style.display = 'none';
}

function submitSystemeUpdate() {
    const contactId = document.getElementById('systeme_contact_id').value;
    const resultDiv = document.getElementById('systemeUpdateResult');

    const updateData = {
        contact_id: contactId,
        first_name: document.getElementById('systeme_first_name').value,
        last_name: document.getElementById('systeme_last_name').value,
        phone: document.getElementById('systeme_phone').value,
        address_line1: document.getElementById('systeme_address').value,
        city: document.getElementById('systeme_city').value,
        state: document.getElementById('systeme_state').value,
        zip_code: document.getElementById('systeme_zip').value
    };

    resultDiv.innerHTML = '<div style="padding: 0.75rem; background: #f0f9ff; border: 1px solid #0ea5e9; border-radius: 8px; color: #0369a1; font-size: 14px;">Updating contact...</div>';

    fetch('test_systeme_update.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify(updateData)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            resultDiv.innerHTML = `
                <div style="padding: 0.75rem; background: #d1fae5; border: 1px solid #10b981; border-radius: 8px; color: #065f46; font-size: 14px;">
                    <strong>✓ Success!</strong> Contact updated successfully.<br>
                    <small style="opacity: 0.8;">Check your Systeme.io account to verify the changes.</small>
                </div>
            `;
            setTimeout(() => {
                closeSystemeUpdateModal();
            }, 2000);
        } else {
            resultDiv.innerHTML = `
                <div style="padding: 0.75rem; background: #fee2e2; border: 1px solid #ef4444; border-radius: 8px; color: #991b1b; font-size: 14px;">
                    <strong>✗ Error:</strong> ${data.error || 'Update failed'}
                </div>
            `;
        }
    })
    .catch(error => {
        resultDiv.innerHTML = `
            <div style="padding: 0.75rem; background: #fee2e2; border: 1px solid #ef4444; border-radius: 8px; color: #991b1b; font-size: 14px;">
                <strong>✗ Request Failed:</strong> ${error.message}
            </div>
        `;
    });
}

function convertToClient(recordId) {
    const resultDiv = document.getElementById('crc-test-result');

    resultDiv.innerHTML = '<div style="padding: 1rem; background: #f0f9ff; border: 1px solid #0ea5e9; border-radius: 8px; color: #0369a1;">Converting Lead to Client...</div>';

    const formData = new FormData();
    formData.append('record_id', recordId);

    fetch('test_crc_update.php', {
        method: 'POST',
        body: formData
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                let debugHtml = '';
                if (data.raw_response || data.xml_sent) {
                    debugHtml = `
                        <details style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #10b981;">
                            <summary style="cursor: pointer; font-weight: 600;">🔍 Debug Info (Click to expand)</summary>
                            <div style="margin-top: 0.5rem;">
                                ${data.xml_sent ? `<div style="margin-bottom: 0.5rem;"><strong>XML Sent:</strong><pre style="background: #f9fafb; padding: 0.5rem; border-radius: 4px; overflow: auto; font-size: 11px;">${data.xml_sent.replace(/</g, '&lt;').replace(/>/g, '&gt;')}</pre></div>` : ''}
                                ${data.raw_response ? `<div><strong>API Response:</strong><pre style="background: #f9fafb; padding: 0.5rem; border-radius: 4px; overflow: auto; font-size: 11px;">${data.raw_response.replace(/</g, '&lt;').replace(/>/g, '&gt;')}</pre></div>` : ''}
                            </div>
                        </details>
                    `;
                }
                resultDiv.innerHTML = `
                    <div style="padding: 1rem; background: #d1fae5; border: 1px solid #10b981; border-radius: 8px; color: #065f46;">
                        <strong>✓ Success!</strong> ${data.message}<br>
                        <strong>Record ID Used:</strong> ${data.record_id}<br>
                        <strong>HTTP Code:</strong> ${data.http_code || '200'}<br>
                        <strong>Updated Name:</strong> ${data.update_data.first_name} ${data.update_data.last_name}<br>
                        <strong>Email:</strong> ${data.update_data.email}<br>
                        <strong>Phone:</strong> ${data.update_data.phone}<br>
                        <strong>Address:</strong> ${data.update_data.address_line1}<br>
                        <strong>City/State/Zip:</strong> ${data.update_data.city}, ${data.update_data.state} ${data.update_data.zip_code}<br>
                        <small style="display: block; margin-top: 0.5rem; opacity: 0.8;">Check your CreditRepairCloud account - the lead should now be a CLIENT!</small>
                        ${debugHtml}
                    </div>
                `;
            } else {
                let detailsHtml = '';
                if (data.details) {
                    detailsHtml += `<br><strong>Details:</strong> ${data.details}`;
                }
                if (data.recent_logs) {
                    detailsHtml += `<br><details style="margin-top: 0.5rem;"><summary>Recent Logs</summary><pre style="font-size: 11px; margin-top: 0.5rem; overflow: auto; max-height: 200px;">${data.recent_logs}</pre></details>`;
                }

                resultDiv.innerHTML = `
                    <div style="padding: 1rem; background: #fee2e2; border: 1px solid #ef4444; border-radius: 8px; color: #991b1b;">
                        <strong>✗ Error:</strong> ${data.error}${detailsHtml}
                    </div>
                `;
            }
        })
        .catch(error => {
            resultDiv.innerHTML = `
                <div style="padding: 1rem; background: #fee2e2; border: 1px solid #ef4444; border-radius: 8px; color: #991b1b;">
                    <strong>✗ Request Failed:</strong> ${error.message}
                </div>
            `;
        });
}

function openMemoTestModal() {
    if (!lastCreatedRecordId || lastCreatedRecordId === 'created' || lastCreatedRecordId === 'no-id-returned') {
        alert('Please create a test lead first with a valid record ID');
        return;
    }
    document.getElementById('memo_record_id').value = lastCreatedRecordId;
    document.getElementById('memoTestModal').style.display = 'flex';
}

function closeMemoTestModal() {
    document.getElementById('memoTestModal').style.display = 'none';
    document.getElementById('memo_text').value = '';
    document.getElementById('memoTestResult').innerHTML = '';
}

function submitMemoTest() {
    const recordId = document.getElementById('memo_record_id').value;
    const memoText = document.getElementById('memo_text').value;
    const resultDiv = document.getElementById('memoTestResult');

    resultDiv.innerHTML = '<div style="padding: 1rem; background: #f0f9ff; border: 1px solid #0ea5e9; border-radius: 8px; color: #0369a1;">Updating memo...</div>';

    const formData = new FormData();
    formData.append('record_id', recordId);
    formData.append('memo_text', memoText);

    fetch('test_crc_memo.php', {
        method: 'POST',
        body: formData
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                let debugHtml = '';
                if (data.raw_response || data.xml_sent) {
                    debugHtml = `
                        <details style="margin-top: 0.5rem;">
                            <summary style="cursor: pointer; font-weight: 600; color: #065f46;">🔍 Debug Info</summary>
                            <div style="margin-top: 0.5rem;">
                                ${data.xml_sent ? `<div style="margin-bottom: 0.5rem;"><strong>XML Sent:</strong><pre style="background: #f9fafb; padding: 0.5rem; border-radius: 4px; overflow: auto; font-size: 11px;">${data.xml_sent.replace(/</g, '&lt;').replace(/>/g, '&gt;')}</pre></div>` : ''}
                                ${data.raw_response ? `<div><strong>API Response:</strong><pre style="background: #f9fafb; padding: 0.5rem; border-radius: 4px; overflow: auto; font-size: 11px;">${data.raw_response.replace(/</g, '&lt;').replace(/>/g, '&gt;')}</pre></div>` : ''}
                            </div>
                        </details>
                    `;
                }
                resultDiv.innerHTML = `
                    <div style="padding: 1rem; background: #d1fae5; border: 1px solid #10b981; border-radius: 8px; color: #065f46;">
                        <strong>✓ ${data.message}</strong><br>
                        <strong>Record ID:</strong> ${data.record_id}<br>
                        <strong>HTTP Code:</strong> ${data.http_code}<br>
                        <strong>API Success:</strong> ${data.api_success}<br>
                        <small style="display: block; margin-top: 0.5rem; opacity: 0.8;">Check CRC to see the memo field updated!</small>
                        ${debugHtml}
                    </div>
                `;
            } else {
                let errorDetails = '';
                if (data.xml_sent) {
                    errorDetails += `<br><details style="margin-top: 0.5rem;"><summary>XML Sent</summary><pre style="font-size: 11px; overflow: auto; max-height: 150px;">${data.xml_sent.replace(/</g, '&lt;').replace(/>/g, '&gt;')}</pre></details>`;
                }
                if (data.raw_response) {
                    errorDetails += `<br><details style="margin-top: 0.5rem;"><summary>Raw Response</summary><pre style="font-size: 11px; overflow: auto; max-height: 150px;">${data.raw_response}</pre></details>`;
                }
                resultDiv.innerHTML = `
                    <div style="padding: 1rem; background: #fee2e2; border: 1px solid #ef4444; border-radius: 8px; color: #991b1b;">
                        <strong>✗ Error:</strong> ${data.error || 'Failed to update memo'}${errorDetails}
                    </div>
                `;
            }
        })
        .catch(error => {
            resultDiv.innerHTML = `
                <div style="padding: 1rem; background: #fee2e2; border: 1px solid #ef4444; border-radius: 8px; color: #991b1b;">
                    <strong>✗ Request Failed:</strong> ${error.message}
                </div>
            `;
        });
}

// VoIP.ms SMS Functions
function copyWebhookUrl() {
    const urlInput = document.getElementById('webhook_url');
    urlInput.select();
    document.execCommand('copy');
    alert('Webhook URL copied to clipboard!');
}

function openTestSmsModal() {
    document.getElementById('testSmsModal').style.display = 'block';
}

function closeTestSmsModal() {
    document.getElementById('testSmsModal').style.display = 'none';
    document.getElementById('testSmsForm').reset();
    document.getElementById('testSmsResult').innerHTML = '';
}

function sendTestSms() {
    const to = document.getElementById('test_sms_to').value;
    const message = document.getElementById('test_sms_message').value;
    const resultDiv = document.getElementById('testSmsResult');
    const sendBtn = document.querySelector('#testSmsModal .btn-primary');

    if (!to || !message) {
        resultDiv.innerHTML = '<div class="alert alert-danger">Please fill in all fields</div>';
        return;
    }

    sendBtn.disabled = true;
    sendBtn.textContent = 'Sending...';
    resultDiv.innerHTML = '<div class="alert" style="background: #e3f2fd; color: #1976d2;">Sending SMS...</div>';

    fetch('/src/outbound_sms.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({to: to, message: message})
    })
    .then(res => res.json())
    .then(data => {
        sendBtn.disabled = false;
        sendBtn.textContent = 'Send Test SMS';

        if (data.status === 'success') {
            resultDiv.innerHTML = '<div class="alert alert-success">SMS sent successfully! Total chunks: ' + data.total_chunks + '</div>';
            setTimeout(() => closeTestSmsModal(), 2000);
        } else {
            let errorMsg = data.message || 'Unknown error';

            // Show API response details if available
            if (data.results && data.results.length > 0) {
                const firstError = data.results[0].result;
                if (firstError && firstError.message) {
                    errorMsg = firstError.message;
                }
                if (firstError && firstError.api_response) {
                    errorMsg += '<br><small>API Response: ' + JSON.stringify(firstError.api_response) + '</small>';
                }
            }

            resultDiv.innerHTML = '<div class="alert alert-danger">Failed to send SMS: ' + errorMsg + '</div>';
        }
    })
    .catch(err => {
        sendBtn.disabled = false;
        sendBtn.textContent = 'Send Test SMS';
        resultDiv.innerHTML = '<div class="alert alert-danger">Network error: ' + err.message + '</div>';
    });
}

function openViewMessagesModal() {
    document.getElementById('viewMessagesModal').style.display = 'block';
    loadInboundMessages();
}

function closeViewMessagesModal() {
    document.getElementById('viewMessagesModal').style.display = 'none';
}

function loadInboundMessages() {
    const tbody = document.getElementById('inboundMessagesTable');
    tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;">Loading...</td></tr>';

    fetch('/src/get_inbound_sms.php')
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success' && data.messages.length > 0) {
            tbody.innerHTML = data.messages.map(msg => `
                <tr>
                    <td>${msg.created_at}</td>
                    <td>${msg.from_number}</td>
                    <td>${msg.to_number}</td>
                    <td>${escapeHtml(msg.message)}</td>
                </tr>
            `).join('');
        } else {
            tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;">No inbound messages found</td></tr>';
        }
    })
    .catch(err => {
        tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; color: red;">Error loading messages</td></tr>';
    });
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function openWebhookLogsModal() {
    document.getElementById('webhookLogsModal').style.display = 'block';
    loadWebhookLogs();
}

function closeWebhookLogsModal() {
    document.getElementById('webhookLogsModal').style.display = 'none';
}

function loadWebhookLogs() {
    const logsDiv = document.getElementById('webhookLogsContent');
    logsDiv.innerHTML = '<p style="text-align:center;">Loading...</p>';

    fetch('/src/get_webhook_logs.php')
    .then(res => res.text())
    .then(data => {
        if (data.trim()) {
            logsDiv.innerHTML = '<pre style="background:#f5f5f5;padding:1rem;border-radius:4px;overflow-x:auto;max-height:500px;overflow-y:auto;font-size:12px;">' + escapeHtml(data) + '</pre>';
        } else {
            logsDiv.innerHTML = '<p style="text-align:center;color:#666;">No webhook logs found. The log will populate when VoIP.ms sends an SMS to your webhook.</p>';
        }
    })
    .catch(err => {
        logsDiv.innerHTML = '<p style="text-align:center;color:red;">Error loading logs: ' + err.message + '</p>';
    });
}

function clearWebhookLogs() {
    if (!confirm('Are you sure you want to clear the webhook logs?')) return;

    fetch('/src/clear_webhook_logs.php', {method: 'POST'})
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            loadWebhookLogs();
        } else {
            alert('Failed to clear logs: ' + data.message);
        }
    })
    .catch(err => {
        alert('Error: ' + err.message);
    });
}

// Toggle edit form for questions
function toggleEditForm(questionId) {
    const editRow = document.getElementById('edit-form-' + questionId);
    if (editRow.style.display === 'none') {
        editRow.style.display = 'table-row';
    } else {
        editRow.style.display = 'none';
    }
}

// Toggle options field based on question type
function toggleOptionsField(questionId, type) {
    const optionsField = document.getElementById('options-field-' + questionId);
    if (type === 'multiple_choice') {
        optionsField.style.display = 'block';
    } else {
        optionsField.style.display = 'none';
    }
}

</script>

<style>
.settings-tabs {
    display: flex;
    gap: var(--spacing-sm);
    border-bottom: 2px solid #ddd;
    margin-bottom: var(--spacing-lg);
    overflow-x: auto;
}
.settings-tabs a {
    padding: var(--spacing-sm) var(--spacing-md);
    text-decoration: none;
    color: #666;
    border-bottom: 3px solid transparent;
    margin-bottom: -2px;
    white-space: nowrap;
    transition: all var(--transition-speed);
    font-weight: 500;
}
.settings-tabs a:hover {
    color: var(--color-primary);
}
.settings-tabs a.active {
    color: var(--color-primary);
    border-bottom-color: var(--color-primary);
    font-weight: 600;
}
.btn-danger {background-color:var(--color-danger);color:#fff;}
.btn-danger:hover {background-color:#c82333;}
.btn-success {background-color:#28a745;color:#fff;border:none;}
.btn-success:hover {background-color:#218838;}
.btn-sm {padding:0.4rem 0.8rem;font-size:13px;}
.badge {display:inline-block;padding:0.25rem 0.6rem;font-size:11px;font-weight:600;border-radius:3px;text-transform:uppercase;}
.badge-success {background-color:#28a745;color:#fff;}
.badge-danger {background-color:var(--color-danger);color:#fff;}

/* Modal Styles */
.modal {display:none;position:fixed;z-index:1000;left:0;top:0;width:100%;height:100%;background-color:rgba(0,0,0,0.5);}
.modal-content {background-color:#fff;margin:5% auto;padding:var(--spacing-lg);border-radius:var(--border-radius);width:90%;max-width:600px;box-shadow:0 4px 6px rgba(0,0,0,0.1);}
.modal-header {display:flex;justify-content:space-between;align-items:center;margin-bottom:var(--spacing-lg);}
.modal-header h3 {margin:0;color:var(--color-primary);}
.modal-close {font-size:28px;font-weight:bold;color:#aaa;cursor:pointer;background:none;border:none;padding:0;line-height:1;}
.modal-close:hover {color:#000;}
.modal-table {width:100%;border-collapse:collapse;margin-top:var(--spacing-md);}
.modal-table th, .modal-table td {padding:var(--spacing-sm);text-align:left;border-bottom:1px solid var(--color-light-2);}
.modal-table th {background-color:var(--color-light-1);font-weight:600;color:var(--color-primary);}
.modal-table tr:hover {background-color:var(--color-light-1);}
</style>

<!-- Test SMS Modal -->
<div id="testSmsModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Send Test SMS</h3>
            <button class="modal-close" onclick="closeTestSmsModal()">&times;</button>
        </div>
        <form id="testSmsForm" onsubmit="event.preventDefault(); sendTestSms();">
            <div class="form-group">
                <label for="test_sms_to" class="form-label">To Phone Number</label>
                <input type="tel" id="test_sms_to" class="form-control" placeholder="10-digit phone number" required>
                <small style="color:#666;">Enter 10-digit number (e.g., 5551234567)</small>
            </div>
            <div class="form-group">
                <label for="test_sms_message" class="form-label">Message</label>
                <textarea id="test_sms_message" class="form-control" rows="4" maxlength="500" required></textarea>
                <small style="color:#666;">Messages over 140 characters will be automatically split. URLs are sent separately.</small>
            </div>
            <div id="testSmsResult" style="margin-top:var(--spacing-md);"></div>
            <div style="display:flex;gap:var(--spacing-sm);margin-top:var(--spacing-md);">
                <button type="submit" class="btn btn-primary">Send Test SMS</button>
                <button type="button" class="btn btn-secondary" onclick="closeTestSmsModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- View Inbound Messages Modal -->
<div id="viewMessagesModal" class="modal">
    <div class="modal-content" style="max-width:900px;">
        <div class="modal-header">
            <h3>Inbound SMS Messages</h3>
            <button class="modal-close" onclick="closeViewMessagesModal()">&times;</button>
        </div>
        <div style="margin-bottom:var(--spacing-md);">
            <button type="button" class="btn btn-secondary btn-sm" onclick="loadInboundMessages()">Refresh</button>
        </div>
        <div style="overflow-x:auto;">
            <table class="modal-table">
                <thead>
                    <tr>
                        <th>Date/Time</th>
                        <th>From</th>
                        <th>To</th>
                        <th>Message</th>
                    </tr>
                </thead>
                <tbody id="inboundMessagesTable">
                    <tr><td colspan="4" style="text-align:center;">Loading...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Webhook Logs Modal -->
<div id="webhookLogsModal" class="modal">
    <div class="modal-content" style="max-width:900px;">
        <div class="modal-header">
            <h3>VoIP.ms Webhook Logs</h3>
            <button class="modal-close" onclick="closeWebhookLogsModal()">&times;</button>
        </div>
        <div style="margin-bottom:var(--spacing-md);display:flex;gap:var(--spacing-sm);">
            <button type="button" class="btn btn-secondary btn-sm" onclick="loadWebhookLogs()">Refresh</button>
            <button type="button" class="btn btn-danger btn-sm" onclick="clearWebhookLogs()">Clear Logs</button>
        </div>
        <div id="webhookLogsContent">
            <p style="text-align:center;">Loading...</p>
        </div>
    </div>
</div>

<script>
function togglePassword(button) {
    const input = button.previousElementSibling;
    if (input.type === 'password') {
        input.type = 'text';
        button.textContent = '🙈';
        button.title = 'Hide';
    } else {
        input.type = 'password';
        button.textContent = '👁️';
        button.title = 'Show';
    }
}

function copyToClipboard(elementId, buttonElement) {
    const element = document.getElementById(elementId);
    element.select();
    element.setSelectionRange(0, 99999); // For mobile

    navigator.clipboard.writeText(element.value).then(() => {
        // Show success feedback
        const originalText = buttonElement.textContent;
        const originalBg = buttonElement.style.background;
        const originalColor = buttonElement.style.color;

        buttonElement.textContent = 'Copied!';
        buttonElement.style.background = '#10b981';
        buttonElement.style.color = 'white';

        setTimeout(() => {
            buttonElement.textContent = originalText;
            buttonElement.style.background = originalBg;
            buttonElement.style.color = originalColor;
        }, 2000);
    }).catch(err => {
        console.error('Failed to copy:', err);
        alert('Failed to copy to clipboard');
    });
}
</script>

<?php include __DIR__ . '/../src/footer.php'; ?>
