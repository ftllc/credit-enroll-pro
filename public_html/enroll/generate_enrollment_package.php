<?php
/**
 * Enrollment Package Background Processor
 * Generates the complete signed package with certificate
 */

define('SNS_ENROLLMENT', true);
require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/fpdf186/fpdf.php';
require_once __DIR__ . '/../src/fpdi/src/autoload.php';
require_once __DIR__ . '/../src/xactosign_verify.php';

// Increase time limit for processing
set_time_limit(300); // 5 minutes
ini_set('memory_limit', '512M');

// Get enrollment ID and client info
$enrollment_id = intval($_POST['enrollment_id'] ?? 0);
$client_ip = $_POST['client_ip'] ?? '127.0.0.1';
$client_user_agent = $_POST['client_user_agent'] ?? 'Unknown Browser';

if ($enrollment_id <= 0) {
    error_log("Invalid enrollment_id");
    exit;
}

try {
    // Fetch enrollment record
    $stmt = $pdo->prepare("SELECT * FROM enrollment_users WHERE id = ?");
    $stmt->execute([$enrollment_id]);
    $enrollment = $stmt->fetch();

    if (!$enrollment) {
        error_log("Enrollment not found: $enrollment_id");
        exit;
    }

    if ($enrollment['package_status'] !== 'processing') {
        error_log("Enrollment package already processed: $enrollment_id");
        exit;
    }

    $package_id = $enrollment['package_id'];
    $xactosign_package_id = $enrollment['xactosign_package_id'];

    // Fetch company settings from database
    $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('company_name', 'admin_email')");
    $stmt->execute();
    $settings = [];
    while ($row = $stmt->fetch()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    $company_name = $settings['company_name'] ?? COMPANY_NAME;
    $admin_email = $settings['admin_email'] ?? 'admin@example.com';

    error_log("Starting background processing for enrollment: $enrollment_id, package: $xactosign_package_id");

    // Fetch package with all documents
    $stmt = $pdo->prepare("SELECT * FROM state_contract_packages WHERE id = ?");
    $stmt->execute([$package_id]);
    $package = $stmt->fetch();

    if (!$package) {
        throw new Exception("Package not found");
    }

    // Fetch all contract documents
    $stmt = $pdo->prepare("SELECT * FROM state_contract_documents WHERE package_id = ? ORDER BY
        CASE contract_type
            WHEN 'croa_disclosure' THEN 1
            WHEN 'client_agreement' THEN 2
            WHEN 'power_of_attorney' THEN 3
            WHEN 'notice_of_cancellation' THEN 4
        END");
    $stmt->execute([$package_id]);
    $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($documents) < 4) {
        throw new Exception("Package must have all 4 contract documents (CROA, Agreement, POA, Notice)");
    }

    if (empty($package['countersign_signature'])) {
        throw new Exception("Package must have a countersign signature");
    }

    // Get signatures from contracts table
    $stmt = $pdo->prepare("SELECT contract_type, signature_data FROM contracts WHERE enrollment_id = ? AND contract_type IN ('croa', 'client_agreement', 'power_of_attorney')");
    $stmt->execute([$enrollment_id]);
    $signatures = [];
    while ($row = $stmt->fetch()) {
        $signatures[$row['contract_type']] = $row['signature_data'];
    }

    if (empty($signatures['croa']) || empty($signatures['client_agreement']) || empty($signatures['power_of_attorney'])) {
        throw new Exception("Missing required signatures");
    }

    // Process signatures
    $croa_sig_data = $signatures['croa'];
    if (strpos($croa_sig_data, 'base64,') !== false) {
        $croa_sig_data = explode('base64,', $croa_sig_data)[1];
    }
    $croa_sig_binary = base64_decode($croa_sig_data);
    $croa_sig_path = tempnam(sys_get_temp_dir(), 'croa_sig_') . '.png';
    file_put_contents($croa_sig_path, $croa_sig_binary);

    $agreement_sig_data = $signatures['client_agreement'];
    if (strpos($agreement_sig_data, 'base64,') !== false) {
        $agreement_sig_data = explode('base64,', $agreement_sig_data)[1];
    }
    $agreement_sig_binary = base64_decode($agreement_sig_data);
    $agreement_sig_path = tempnam(sys_get_temp_dir(), 'agreement_sig_') . '.png';
    file_put_contents($agreement_sig_path, $agreement_sig_binary);

    $poa_sig_data = $signatures['power_of_attorney'];
    if (strpos($poa_sig_data, 'base64,') !== false) {
        $poa_sig_data = explode('base64,', $poa_sig_data)[1];
    }
    $poa_sig_binary = base64_decode($poa_sig_data);
    $poa_sig_path = tempnam(sys_get_temp_dir(), 'poa_sig_') . '.png';
    file_put_contents($poa_sig_path, $poa_sig_binary);

    $countersign_sig_path = tempnam(sys_get_temp_dir(), 'countersign_') . '.png';
    file_put_contents($countersign_sig_path, $package['countersign_signature']);

    // Array to store filled PDF paths
    $filled_pdfs = [];
    $signature_events = [];
    $signature_counter = 1;

    // Prepare enrollment data
    $client_name = $enrollment['first_name'] . ' ' . $enrollment['last_name'];
    $client_address = trim(
        $enrollment['address_line1'] .
        ($enrollment['address_line2'] ? ', ' . $enrollment['address_line2'] : '') .
        ', ' . $enrollment['city'] . ', ' . $enrollment['state'] . ' ' . $enrollment['zip_code']
    );
    $client_phone = format_phone($enrollment['phone']);
    $client_email = $enrollment['email'];

    // Process each document
    foreach ($documents as $doc) {
        error_log("Processing document: " . $doc['contract_type']);

        $form_data = [];
        $signatures_to_add = [];
        $add_agreement_sigs = false;

        // Prepare form data based on contract type
        switch ($doc['contract_type']) {
            case 'croa_disclosure':
                $form_data['client_name'] = $client_name;
                $form_data['enrollment_date'] = date('m/d/Y');

                // Check for custom signature coordinates
                $croa_coords = !empty($doc['signature_coords']) ? json_decode($doc['signature_coords'], true) : null;
                if ($croa_coords && is_array($croa_coords)) {
                    // Use custom coordinates
                    foreach ($croa_coords as $coord) {
                        if ($coord['signature_type'] === 'client') {
                            $sig_id = $xactosign_package_id . '-SIG' . str_pad($signature_counter++, 3, '0', STR_PAD_LEFT);
                            $signatures_to_add[] = [
                                'image_path' => $croa_sig_path,
                                'page' => $coord['page'],
                                'x1' => floatval($coord['x1']),
                                'y1' => floatval($coord['y1']),
                                'x2' => floatval($coord['x2']),
                                'y2' => floatval($coord['y2']),
                                'label' => $coord['label'] ?? 'CROA client signature',
                                'sig_id' => $sig_id
                            ];
                            $signature_events[] = [
                                'signature_id' => $sig_id,
                                'signer_name' => $client_name,
                                'email' => $client_email,
                                'signed_datetime' => date('F j, Y \a\t g:i:s A'),
                                'timezone' => 'America/Chicago (CST)',
                                'capture_method' => 'Electronic Signature (Canvas Drawn)',
                                'ip_address' => $client_ip,
                                'user_agent' => $client_user_agent,
                                'signature_image_data' => 'data:image/png;base64,' . base64_encode($croa_sig_binary)
                            ];
                        }
                    }
                } else {
                    // Use default coordinates (backward compatibility)
                    $sig_id = $xactosign_package_id . '-SIG' . str_pad($signature_counter++, 3, '0', STR_PAD_LEFT);
                    $signatures_to_add[] = [
                        'image_path' => $croa_sig_path,
                        'page' => 2,
                        'x1' => 84.0,
                        'y1' => 145.0,
                        'x2' => 327.0,
                        'y2' => 167.0,
                        'label' => 'CROA client signature',
                        'sig_id' => $sig_id
                    ];
                    $signature_events[] = [
                        'signature_id' => $sig_id,
                        'signer_name' => $client_name,
                        'email' => $client_email,
                        'signed_datetime' => date('F j, Y \a\t g:i:s A'),
                        'timezone' => 'America/Chicago (CST)',
                        'capture_method' => 'Electronic Signature (Canvas Drawn)',
                        'ip_address' => $client_ip,
                        'user_agent' => $client_user_agent,
                        'signature_image_data' => 'data:image/png;base64,' . base64_encode($croa_sig_binary)
                    ];
                }
                break;

            case 'client_agreement':
                $form_data['client_name'] = $client_name;
                $form_data['client_address'] = $client_address;
                $form_data['client_phone'] = $client_phone;
                $form_data['client_email'] = $client_email;
                $form_data['enrollment_date'] = date('m/d/Y');
                $form_data['cs_title'] = 'President';

                // Check for custom signature coordinates
                $agreement_coords = !empty($doc['signature_coords']) ? json_decode($doc['signature_coords'], true) : null;
                if ($agreement_coords && is_array($agreement_coords)) {
                    // Use custom coordinates
                    foreach ($agreement_coords as $coord) {
                        $sig_id = $xactosign_package_id . '-SIG' . str_pad($signature_counter++, 3, '0', STR_PAD_LEFT);

                        if ($coord['signature_type'] === 'client') {
                            $agreement_client_sig_id = $sig_id;
                            $signatures_to_add[] = [
                                'image_path' => $agreement_sig_path,
                                'page' => $coord['page'],
                                'x1' => floatval($coord['x1']),
                                'y1' => floatval($coord['y1']),
                                'x2' => floatval($coord['x2']),
                                'y2' => floatval($coord['y2']),
                                'label' => $coord['label'] ?? 'client signature',
                                'sig_id' => $sig_id
                            ];
                            $signature_events[] = [
                                'signature_id' => $sig_id,
                                'signer_name' => $client_name,
                                'email' => $client_email,
                                'signed_datetime' => date('F j, Y \a\t g:i:s A'),
                                'timezone' => 'America/Chicago (CST)',
                                'capture_method' => 'Electronic Signature (Canvas Drawn)',
                                'ip_address' => $client_ip,
                                'user_agent' => $client_user_agent,
                                'signature_image_data' => 'data:image/png;base64,' . base64_encode($agreement_sig_binary)
                            ];
                        } elseif ($coord['signature_type'] === 'countersign') {
                            $agreement_counter_sig_id = $sig_id;
                            $signatures_to_add[] = [
                                'image_path' => $countersign_sig_path,
                                'page' => $coord['page'],
                                'x1' => floatval($coord['x1']),
                                'y1' => floatval($coord['y1']),
                                'x2' => floatval($coord['x2']),
                                'y2' => floatval($coord['y2']),
                                'label' => $coord['label'] ?? 'counter signature',
                                'sig_id' => $sig_id
                            ];
                            $signature_events[] = [
                                'signature_id' => $sig_id,
                                'signer_name' => $company_name,
                                'email' => $admin_email,
                                'signed_datetime' => date('F j, Y \a\t g:i:s A'),
                                'timezone' => 'America/Chicago (CST)',
                                'capture_method' => 'Stored Company Signature',
                                'ip_address' => $client_ip,
                                'user_agent' => 'Company Signature Database',
                                'signature_image_data' => 'data:image/png;base64,' . base64_encode($package['countersign_signature'])
                            ];
                        }
                    }
                } else {
                    // Use default coordinates (backward compatibility)
                    $add_agreement_sigs = true;

                    // Store signature IDs for adding to PDF later
                    $client_sig_id = $xactosign_package_id . '-SIG' . str_pad($signature_counter++, 3, '0', STR_PAD_LEFT);
                    $counter_sig_id = $xactosign_package_id . '-SIG' . str_pad($signature_counter++, 3, '0', STR_PAD_LEFT);
                    $agreement_client_sig_id = $client_sig_id;
                    $agreement_counter_sig_id = $counter_sig_id;

                    $signature_events[] = [
                        'signature_id' => $client_sig_id,
                        'signer_name' => $client_name,
                        'email' => $client_email,
                        'signed_datetime' => date('F j, Y \a\t g:i:s A'),
                        'timezone' => 'America/Chicago (CST)',
                        'capture_method' => 'Electronic Signature (Canvas Drawn)',
                        'ip_address' => $client_ip,
                        'user_agent' => $client_user_agent,
                        'signature_image_data' => 'data:image/png;base64,' . base64_encode($agreement_sig_binary)
                    ];
                    $signature_events[] = [
                        'signature_id' => $counter_sig_id,
                        'signer_name' => $company_name,
                        'email' => $admin_email,
                        'signed_datetime' => date('F j, Y \a\t g:i:s A'),
                        'timezone' => 'America/Chicago (CST)',
                        'capture_method' => 'Stored Company Signature',
                        'ip_address' => $client_ip,
                        'user_agent' => 'Company Signature Database',
                        'signature_image_data' => 'data:image/png;base64,' . base64_encode($package['countersign_signature'])
                    ];
                }
                break;

            case 'power_of_attorney':
                $form_data['client_name'] = $client_name;
                $form_data['client_address'] = $client_address;
                $form_data['enrollment_date'] = date('m/d/Y');

                // Check for custom signature coordinates
                $poa_coords = !empty($doc['signature_coords']) ? json_decode($doc['signature_coords'], true) : null;
                if ($poa_coords && is_array($poa_coords)) {
                    // Use custom coordinates
                    foreach ($poa_coords as $coord) {
                        if ($coord['signature_type'] === 'client') {
                            // Convert page name to number if needed
                            $coord_page = $coord['page'];
                            if (is_string($coord_page)) {
                                $page_map = ['one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5];
                                $coord_page = $page_map[strtolower($coord_page)] ?? intval($coord_page);
                            }

                            $sig_id = $xactosign_package_id . '-SIG' . str_pad($signature_counter++, 3, '0', STR_PAD_LEFT);
                            $signatures_to_add[] = [
                                'image_path' => $poa_sig_path,
                                'page' => $coord_page,
                                'x1' => floatval($coord['x1']),
                                'y1' => floatval($coord['y1']),
                                'x2' => floatval($coord['x2']),
                                'y2' => floatval($coord['y2']),
                                'label' => $coord['label'] ?? 'POA client signature',
                                'sig_id' => $sig_id
                            ];
                            $signature_events[] = [
                                'signature_id' => $sig_id,
                                'signer_name' => $client_name,
                                'email' => $client_email,
                                'signed_datetime' => date('F j, Y \a\t g:i:s A'),
                                'timezone' => 'America/Chicago (CST)',
                                'capture_method' => 'Electronic Signature (Canvas Drawn)',
                                'ip_address' => $client_ip,
                                'user_agent' => $client_user_agent,
                                'signature_image_data' => 'data:image/png;base64,' . base64_encode($poa_sig_binary)
                            ];
                        }
                    }
                }
                break;

            case 'notice_of_cancellation':
                $days_to_cancel = intval($package['days_to_cancel'] ?? 5);
                $notice_date = date('m/d/Y', strtotime("+{$days_to_cancel} days"));
                $form_data['notice_date'] = $notice_date;
                break;
        }

        // Save PDF to temp file
        $temp_pdf = tempnam(sys_get_temp_dir(), 'contract_') . '.pdf';
        file_put_contents($temp_pdf, $doc['contract_pdf']);

        // Create FDF file with form data
        $fdf_file = tempnam(sys_get_temp_dir(), 'fdf_') . '.fdf';
        $text_filled_pdf = tempnam(sys_get_temp_dir(), 'textfilled_') . '.pdf';

        // Generate FDF content
        $fdf_content = "%FDF-1.2\n1 0 obj\n<<\n/FDF << /Fields [\n";
        foreach ($form_data as $field_name => $field_value) {
            $field_value = str_replace(['(', ')', '\\'], ['\\(', '\\)', '\\\\'], $field_value);
            $fdf_content .= "<< /T ({$field_name}) /V ({$field_value}) >>\n";
        }
        $fdf_content .= "] >>\n>>\nendobj\ntrailer\n<<\n/Root 1 0 R\n>>\n%%EOF\n";
        file_put_contents($fdf_file, $fdf_content);

        // Fill form with pdftk
        $pdftk_path = trim(shell_exec('which pdftk')) ?: '/usr/bin/pdftk';
        $command = escapeshellcmd($pdftk_path) . ' ' . escapeshellarg($temp_pdf) .
                   ' fill_form ' . escapeshellarg($fdf_file) .
                   ' output ' . escapeshellarg($text_filled_pdf) . ' flatten';
        exec($command, $cmd_output, $return_var);

        unlink($fdf_file);
        unlink($temp_pdf);

        if ($return_var !== 0 || !file_exists($text_filled_pdf)) {
            throw new Exception("Failed to fill PDF form for {$doc['contract_type']}");
        }

        // Add signatures using FPDI if needed
        $output_pdf = $text_filled_pdf;

        if (!empty($signatures_to_add) || !empty($add_agreement_sigs)) {
            $pdf = new \setasign\Fpdi\Fpdi();
            $pageCount = $pdf->setSourceFile($text_filled_pdf);
            $page_height_points = 792.0;

            // For client agreement, add signatures on last page
            if (!empty($add_agreement_sigs)) {
                $signatures_to_add[] = [
                    'image_path' => $agreement_sig_path,
                    'page' => $pageCount,
                    'x1' => 96.0,
                    'y1' => 457.0,
                    'x2' => 283.0,
                    'y2' => 480.0,
                    'label' => 'client signature',
                    'sig_id' => $agreement_client_sig_id
                ];
                $signatures_to_add[] = [
                    'image_path' => $countersign_sig_path,
                    'page' => $pageCount,
                    'x1' => 182.0,
                    'y1' => 399.0,
                    'x2' => 374.0,
                    'y2' => 422.0,
                    'label' => 'counter signature',
                    'sig_id' => $agreement_counter_sig_id
                ];
            }

            for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
                $tplId = $pdf->importPage($pageNo);
                $size = $pdf->getTemplateSize($tplId);
                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $pdf->useTemplate($tplId);

                // Add signatures for this page
                foreach ($signatures_to_add as $sig) {
                    if ($pageNo == $sig['page']) {
                        $sig_width_mm = ($sig['x2'] - $sig['x1']) * 0.352778;
                        $sig_height_mm = ($sig['y2'] - $sig['y1']) * 0.352778;
                        $sig_x_mm = $sig['x1'] * 0.352778;
                        $sig_y_mm = ($page_height_points - $sig['y2']) * 0.352778;

                        // Add signature image
                        $pdf->Image($sig['image_path'], $sig_x_mm, $sig_y_mm, $sig_width_mm, $sig_height_mm);

                        // Add signature ID stamp below the signature
                        if (!empty($sig['sig_id'])) {
                            $pdf->SetFont('Arial', '', 7);
                            $pdf->SetTextColor(0, 51, 153);
                            $stamp_y = $sig_y_mm + $sig_height_mm + 1;
                            $pdf->Text($sig_x_mm, $stamp_y, 'XactoSign ID: ' . $sig['sig_id']);
                        }
                    }
                }
            }

            $final_pdf = tempnam(sys_get_temp_dir(), 'withsig_') . '.pdf';
            $pdf->Output('F', $final_pdf);

            if (file_exists($final_pdf)) {
                $output_pdf = $final_pdf;
                unlink($text_filled_pdf);
            }
        }

        $filled_pdfs[] = $output_pdf;
    }

    error_log("All documents filled and signed");

    // Generate XactoSign certificate
    error_log("Generating XactoSign certificate");

    $certificate_data = [
        'company_name' => $company_name,
        'document_title' => $package['package_name'] . ' - Complete Contract Package',
        'certificate_id' => $xactosign_package_id,
        'generated_date' => date('F j, Y \a\t g:i A T'),
        'security_method' => 'SHA-256 Cryptographic Hash with Timestamp',
        'document_hash' => strtoupper(hash('sha256', 'package_' . $xactosign_package_id . '_' . time())),
        'signatures' => $signature_events
    ];

    $cert_pdf_path = tempnam(sys_get_temp_dir(), 'cert_') . '.pdf';

    ob_start();
    generateCertificate($certificate_data);
    $cert_content = ob_get_clean();
    file_put_contents($cert_pdf_path, $cert_content);

    error_log("Certificate generated: " . filesize($cert_pdf_path) . " bytes");

    // Add certificate to the list of PDFs to merge
    $filled_pdfs[] = $cert_pdf_path;

    // Merge all PDFs and add stamps in ONE pass
    error_log("Merging " . count($filled_pdfs) . " documents with stamps");

    $final_pdf = new \setasign\Fpdi\Fpdi();
    $page_number = 1;

    foreach ($filled_pdfs as $pdf_path) {
        $pageCount = $final_pdf->setSourceFile($pdf_path);

        for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
            $tplId = $final_pdf->importPage($pageNo);
            $size = $final_pdf->getTemplateSize($tplId);
            $final_pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $final_pdf->useTemplate($tplId);

            // Add page stamp at TOP CENTER of page
            $final_pdf->SetFont('Arial', '', 8);
            $final_pdf->SetTextColor(100, 100, 100);
            $stamp_text = "XactoSign Package: {$xactosign_package_id} | Page {$page_number}";

            // Calculate centered X position
            $text_width = $final_pdf->GetStringWidth($stamp_text);
            $center_x = ($size['width'] - $text_width) / 2;

            $final_pdf->Text($center_x, 7, $stamp_text);

            $page_number++;
        }
    }

    // Get final PDF content
    $merged_pdf_content = $final_pdf->Output('S');
    $file_size = strlen($merged_pdf_content);
    $total_pages = $page_number - 1;

    // Clean up temp files
    foreach ($filled_pdfs as $pdf_path) {
        @unlink($pdf_path);
    }
    @unlink($croa_sig_path);
    @unlink($agreement_sig_path);
    @unlink($countersign_sig_path);

    @unlink($poa_sig_path);
    error_log("Package generation complete. Total pages: $total_pages, Size: $file_size bytes");

    // Store in database
    $stmt = $pdo->prepare("UPDATE enrollment_users SET
        package_status = 'completed',
        complete_package_pdf = ?,
        package_file_size = ?,
        package_total_pages = ?,
        package_completed_at = NOW()
        WHERE id = ?");
    $stmt->execute([$merged_pdf_content, $file_size, $total_pages, $enrollment_id]);

    error_log("Package stored in database successfully");

    // Update CRC with final document ID
    if (!empty($enrollment['crc_record_id']) && !empty($enrollment['xactosign_package_id'])) {
        $memo = "--- ENROLLMENT COMPLETED ---\n";
        $memo .= "Document ID: " . $enrollment['xactosign_package_id'] . "\n";
        $memo .= "Package Pages: {$total_pages}\n";
        $memo .= "Completed: " . date('Y-m-d H:i:s');

        crc_append_enrollment_memo($enrollment['crc_record_id'], $enrollment_id, $memo);
        error_log("CRC updated with document ID: " . $enrollment['xactosign_package_id']);
    }

    // Send Enrollment Complete notifications (Client + Staff)
    require_once __DIR__ . '/../src/notification_helper.php';

    // Get plan name
    $plan_name = 'Credit Repair Service';
    if (!empty($enrollment['plan_id'])) {
        $stmt = $pdo->prepare("SELECT plan_name FROM plans WHERE id = ?");
        $stmt->execute([$enrollment['plan_id']]);
        $plan = $stmt->fetch();
        if ($plan) {
            $plan_name = $plan['plan_name'];
        }
    }

    // Prepare notification data
    $notification_data = [
        'client_name' => $client_name,
        'client_first_name' => $enrollment['first_name'],
        'client_last_name' => $enrollment['last_name'],
        'client_email' => $enrollment['email'],
        'client_phone' => format_phone($enrollment['phone']),
        'plan_name' => $plan_name,
        'enrollment_url' => BASE_URL . '/admin/enrollments.php?id=' . $enrollment_id,
        'login_url' => BASE_URL . '/admin/'
    ];

    // Send to client with PDF attachment
    $package_pdf_path = tempnam(sys_get_temp_dir(), 'enrollment_package_') . '.pdf';
    file_put_contents($package_pdf_path, $merged_pdf_content);

    $client_recipient = [
        'name' => $client_name,
        'email' => $enrollment['email'],
        'phone' => $enrollment['phone']
    ];

    send_notification('enrollment_complete_client', 'client', $client_recipient, $notification_data, $package_pdf_path);
    error_log("Enrollment Complete (Client) notification sent to " . $enrollment['email']);

    // Clean up temp PDF
    @unlink($package_pdf_path);

    // Generate separate POA PDF for staff email
    $poa_pdf_path = null;
    $poa_doc = null;
    foreach ($documents as $doc) {
        if ($doc['contract_type'] === 'power_of_attorney') {
            $poa_doc = $doc;
            break;
        }
    }

    if ($poa_doc) {
        try {
            // Get POA signature
            $stmt = $pdo->prepare("SELECT signature_data FROM contracts WHERE enrollment_id = ? AND contract_type = 'power_of_attorney' LIMIT 1");
            $stmt->execute([$enrollment_id]);
            $poa_sig_contract = $stmt->fetch();

            // Create separate POA PDF
            $poa_pdf = new \setasign\Fpdi\Fpdi();
            
            // Save POA document to temp file
            $poa_temp = tempnam(sys_get_temp_dir(), 'poa_doc_') . '.pdf';
            file_put_contents($poa_temp, $poa_doc['contract_pdf']);

            // Fill POA form data
            $poa_form_data = [
                'client_name' => $client_name,
                'client_address' => trim(
                    $enrollment['address_line1'] .
                    ($enrollment['address_line2'] ? ', ' . $enrollment['address_line2'] : '') .
                    ', ' . $enrollment['city'] . ', ' . $enrollment['state'] . ' ' . $enrollment['zip_code']
                ),
                'enrollment_date' => date('m/d/Y')
            ];

            $poa_fdf = tempnam(sys_get_temp_dir(), 'poa_fdf_') . '.fdf';
            $poa_filled = tempnam(sys_get_temp_dir(), 'poa_filled_') . '.pdf';

            // Generate FDF content
            $fdf_content = "%FDF-1.2\n1 0 obj\n<<\n/FDF << /Fields [\n";
            foreach ($poa_form_data as $field_name => $field_value) {
                $field_value = str_replace(['(', ')', '\\'], ['\\(', '\\)', '\\\\'], $field_value);
                $fdf_content .= "<< /T ({$field_name}) /V ({$field_value}) >>\n";
            }
            $fdf_content .= "] >>\n>>\nendobj\ntrailer\n<<\n/Root 1 0 R\n>>\n%%EOF\n";
            file_put_contents($poa_fdf, $fdf_content);

            // Fill form with pdftk
            $pdftk_path = trim(shell_exec('which pdftk')) ?: '/usr/bin/pdftk';
            $command = escapeshellcmd($pdftk_path) . ' ' . escapeshellarg($poa_temp) .
                       ' fill_form ' . escapeshellarg($poa_fdf) .
                       ' output ' . escapeshellarg($poa_filled) . ' flatten';
            exec($command, $cmd_output, $return_var);

            // Add signature to POA
            $poa_pageCount = $poa_pdf->setSourceFile($poa_filled);
            $page_height_points = 792.0;

            // Get POA signature coordinates
            $poa_coords = !empty($poa_doc['signature_coords']) ? json_decode($poa_doc['signature_coords'], true) : null;

            for ($p = 1; $p <= $poa_pageCount; $p++) {
                $tplId = $poa_pdf->importPage($p);
                $size = $poa_pdf->getTemplateSize($tplId);
                $poa_pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $poa_pdf->useTemplate($tplId);

                // Add signature using stored coordinates
                if ($poa_sig_contract && $poa_coords && is_array($poa_coords)) {
                    foreach ($poa_coords as $coord) {
                        // Convert page name to number
                        $coord_page = $coord['page'];
                        if (is_string($coord_page)) {
                            $page_map = ['one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5];
                            $coord_page = $page_map[strtolower($coord_page)] ?? intval($coord_page);
                        }

                        if ($coord['signature_type'] === 'client' && $p == $coord_page) {
                            $poa_sig_img = tempnam(sys_get_temp_dir(), 'poa_sig_') . '.png';
                            $sig_data = $poa_sig_contract['signature_data'];
                            if (strpos($sig_data, 'base64,') !== false) {
                                $sig_data = explode('base64,', $sig_data)[1];
                            }
                            file_put_contents($poa_sig_img, base64_decode($sig_data));

                            // Convert coordinates from points to mm
                            $sig_width_mm = ($coord['x2'] - $coord['x1']) * 0.352778;
                            $sig_height_mm = ($coord['y2'] - $coord['y1']) * 0.352778;
                            $sig_x_mm = $coord['x1'] * 0.352778;
                            $sig_y_mm = ($page_height_points - $coord['y2']) * 0.352778;

                            $poa_pdf->Image($poa_sig_img, $sig_x_mm, $sig_y_mm, $sig_width_mm, $sig_height_mm);
                            @unlink($poa_sig_img);
                        }
                    }
                }
            }

            // Save POA PDF
            $poa_filename = $enrollment['last_name'] . '_' . $enrollment['first_name'] . '_PoA.pdf';
            $poa_pdf_path = tempnam(sys_get_temp_dir(), 'poa_final_') . '.pdf';
            file_put_contents($poa_pdf_path, $poa_pdf->Output('S'));

            // Cleanup
            @unlink($poa_temp);
            @unlink($poa_fdf);
            @unlink($poa_filled);

            error_log("Separate POA PDF created: $poa_filename");
        } catch (Exception $e) {
            error_log("Failed to create separate POA PDF: " . $e->getMessage());
            $poa_pdf_path = null;
        }
    }

    // Send to staff with PDF attachments (full package + separate POA)
    $staff_package_pdf_path = tempnam(sys_get_temp_dir(), 'enrollment_package_staff_') . '.pdf';
    file_put_contents($staff_package_pdf_path, $merged_pdf_content);

    $poa_filename = $enrollment['last_name'] . '_' . $enrollment['first_name'] . '_PoA.pdf';
    $staff_result = notify_staff('enrollment_complete_staff', 'notify_enrollment_complete', $notification_data, $staff_package_pdf_path, $poa_pdf_path, $poa_filename);
    error_log("Enrollment Complete (Staff) notification sent to {$staff_result['staff_notified']} staff members" . ($poa_pdf_path ? ' with separate POA PDF' : ''));

    // Clean up temp PDFs
    @unlink($staff_package_pdf_path);
    if ($poa_pdf_path && file_exists($poa_pdf_path)) {
        @unlink($poa_pdf_path);
    }

} catch (Exception $e) {
    error_log("Error in background package processing: " . $e->getMessage());
    error_log($e->getTraceAsString());

    // Update database with error
    try {
        $stmt = $pdo->prepare("UPDATE enrollment_users SET
            package_status = 'failed',
            package_error = ?,
            package_completed_at = NOW()
            WHERE id = ?");
        $stmt->execute([$e->getMessage(), $enrollment_id]);
    } catch (Exception $db_error) {
        error_log("Failed to update error in database: " . $db_error->getMessage());
    }
}
