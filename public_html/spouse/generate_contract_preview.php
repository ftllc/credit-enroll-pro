<?php
/**
 * Generate Contract Preview PDFs for Spouse Enrollment
 * This generates preview PDFs with spouse data and "SIGNATURE PENDING" watermarks
 */

define('SNS_ENROLLMENT', true);
require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/fpdf186/fpdf.php';
require_once __DIR__ . '/../src/fpdi/src/autoload.php';

session_start();

// Check session
if (!isset($_SESSION['spouse_enrollment_session_id'])) {
    http_response_code(403);
    die('Access denied');
}

$contract_type = $_GET['type'] ?? '';
if (!in_array($contract_type, ['croa', 'agreement'])) {
    http_response_code(400);
    die('Invalid contract type');
}

try {
    // Get spouse enrollment data
    $stmt = $pdo->prepare("SELECT * FROM spouse_enrollments WHERE session_id = ?");
    $stmt->execute([$_SESSION['spouse_enrollment_session_id']]);
    $spouse_enrollment = $stmt->fetch();

    if (!$spouse_enrollment) {
        http_response_code(404);
        die('Spouse enrollment not found');
    }

    // Get primary enrollment data
    $stmt = $pdo->prepare("SELECT * FROM enrollment_users WHERE id = ?");
    $stmt->execute([$spouse_enrollment['primary_enrollment_id']]);
    $enrollment = $stmt->fetch();

    if (!$enrollment) {
        http_response_code(404);
        die('Primary enrollment not found');
    }

    // Get plan data
    $plan = null;
    if ($enrollment['plan_id']) {
        $stmt = $pdo->prepare("SELECT * FROM plans WHERE id = ?");
        $stmt->execute([$enrollment['plan_id']]);
        $plan = $stmt->fetch();
    }

    // Determine which package to use based on primary enrollment's state
    $package_id = null;
    $package = null;

    // First try to find state-specific package
    if (!empty($enrollment['state'])) {
        $stmt = $pdo->prepare("
            SELECT scp.*
            FROM state_contract_packages scp
            INNER JOIN state_contract_mappings scm ON scp.id = scm.package_id
            WHERE scm.state_code = ?
            LIMIT 1
        ");
        $stmt->execute([$enrollment['state']]);
        $package = $stmt->fetch();
    }

    // If no state-specific package, use default
    if (!$package) {
        $stmt = $pdo->prepare("SELECT * FROM state_contract_packages WHERE is_default = 1 LIMIT 1");
        $stmt->execute();
        $package = $stmt->fetch();
    }

    if (!$package) {
        throw new Exception("No contract package found");
    }

    // Get the appropriate document(s)
    $documents_to_process = [];

    if ($contract_type === 'croa') {
        $stmt = $pdo->prepare("SELECT * FROM state_contract_documents WHERE package_id = ? AND contract_type = 'croa_disclosure'");
        $stmt->execute([$package['id']]);
        $document = $stmt->fetch();

        if (!$document) {
            throw new Exception("CROA document not found");
        }
        $documents_to_process[] = $document;

    } else { // agreement - include both client_agreement and notice_of_cancellation
        $stmt = $pdo->prepare("SELECT * FROM state_contract_documents WHERE package_id = ? AND contract_type IN ('client_agreement', 'notice_of_cancellation') ORDER BY FIELD(contract_type, 'client_agreement', 'notice_of_cancellation')");
        $stmt->execute([$package['id']]);
        $documents_to_process = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($documents_to_process) < 2) {
            throw new Exception("Client agreement or notice of cancellation not found");
        }
    }

    // Process each document and collect filled PDFs
    $filled_pdfs = [];

    foreach ($documents_to_process as $document) {
        $form_data = [];

        // Use SPOUSE information for prefilling
        $spouse_full_name = $enrollment['spouse_first_name'] . ' ' . $enrollment['spouse_last_name'];
        $spouse_address = trim(
            $enrollment['address_line1'] .
            ($enrollment['address_line2'] ? ', ' . $enrollment['address_line2'] : '') .
            ', ' . $enrollment['city'] . ', ' . $enrollment['state'] . ' ' . $enrollment['zip_code']
        );

        // Prepare form data based on contract type
        if ($document['contract_type'] === 'croa_disclosure') {
            $form_data['client_name'] = $spouse_full_name;
            $form_data['enrollment_date'] = date('m/d/Y');

        } elseif ($document['contract_type'] === 'client_agreement') {
            $form_data['client_name'] = $spouse_full_name;
            $form_data['client_address'] = $spouse_address;
            $form_data['client_phone'] = format_phone($enrollment['spouse_phone']);
            $form_data['client_email'] = $enrollment['spouse_email'];
            $form_data['enrollment_date'] = date('m/d/Y');
            $form_data['cs_title'] = 'President';

        } elseif ($document['contract_type'] === 'notice_of_cancellation') {
            $days_to_cancel = intval($package['days_to_cancel'] ?? 5);
            $notice_date = date('m/d/Y', strtotime("+{$days_to_cancel} days"));
            $form_data['notice_date'] = $notice_date;
        }

        // Save PDF to temp file
        $temp_pdf = tempnam(sys_get_temp_dir(), 'contract_') . '.pdf';
        file_put_contents($temp_pdf, $document['contract_pdf']);

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
            throw new Exception("Failed to fill PDF form for " . $document['contract_type']);
        }

        $filled_pdfs[] = [
            'path' => $text_filled_pdf,
            'type' => $document['contract_type']
        ];
    }

    // Now merge all PDFs and add signature watermarks
    $final_pdf_obj = new \setasign\Fpdi\Fpdi();
    $page_height_points = 792.0;
    $global_page_number = 1;

    foreach ($filled_pdfs as $filled_pdf_info) {
        $pdf_path = $filled_pdf_info['path'];
        $pdf_type = $filled_pdf_info['type'];

        $pageCount = $final_pdf_obj->setSourceFile($pdf_path);

        // Determine signature fields for this document type
        $signature_fields = [];
        if ($pdf_type === 'croa_disclosure') {
            $signature_fields[] = [
                'page' => 2,
                'x1' => 84.0,
                'y1' => 145.0,
                'x2' => 327.0,
                'y2' => 167.0,
                'text' => 'SIGNATURE PENDING'
            ];
        } elseif ($pdf_type === 'client_agreement') {
            // Add signatures on last page of client agreement
            $signature_fields[] = [
                'page' => $pageCount,
                'x1' => 96.0,
                'y1' => 457.0,
                'x2' => 283.0,
                'y2' => 480.0,
                'text' => 'SIGNATURE PENDING'
            ];
            $signature_fields[] = [
                'page' => $pageCount,
                'x1' => 182.0,
                'y1' => 399.0,
                'x2' => 374.0,
                'y2' => 422.0,
                'text' => 'SIGNATURE PENDING'
            ];
        }
        // notice_of_cancellation has no signatures

        // Process each page
        for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
            $tplId = $final_pdf_obj->importPage($pageNo);
            $size = $final_pdf_obj->getTemplateSize($tplId);
            $final_pdf_obj->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $final_pdf_obj->useTemplate($tplId);

            // Add signature pending watermarks for this page
            foreach ($signature_fields as $sig) {
                if ($pageNo == $sig['page']) {
                    $sig_width_mm = ($sig['x2'] - $sig['x1']) * 0.352778;
                    $sig_height_mm = ($sig['y2'] - $sig['y1']) * 0.352778;
                    $sig_x_mm = $sig['x1'] * 0.352778;
                    $sig_y_mm = ($page_height_points - $sig['y2']) * 0.352778;

                    // Draw rectangle background
                    $final_pdf_obj->SetFillColor(255, 255, 200); // Light yellow
                    $final_pdf_obj->Rect($sig_x_mm, $sig_y_mm, $sig_width_mm, $sig_height_mm, 'F');

                    // Draw border
                    $final_pdf_obj->SetDrawColor(200, 200, 0);
                    $final_pdf_obj->SetLineWidth(0.5);
                    $final_pdf_obj->Rect($sig_x_mm, $sig_y_mm, $sig_width_mm, $sig_height_mm);

                    // Add text
                    $final_pdf_obj->SetFont('Arial', 'B', 10);
                    $final_pdf_obj->SetTextColor(150, 150, 0);
                    $text_y = $sig_y_mm + ($sig_height_mm / 2) - 2;
                    $final_pdf_obj->SetXY($sig_x_mm, $text_y);
                    $final_pdf_obj->Cell($sig_width_mm, 5, $sig['text'], 0, 0, 'C');
                }
            }

            $global_page_number++;
        }
    }

    // Output merged PDF
    $final_pdf_path = tempnam(sys_get_temp_dir(), 'preview_') . '.pdf';
    $final_pdf_obj->Output('F', $final_pdf_path);

    // Clean up temp files
    foreach ($filled_pdfs as $filled_pdf_info) {
        @unlink($filled_pdf_info['path']);
    }

    if (!file_exists($final_pdf_path)) {
        throw new Exception("Failed to generate preview PDF");
    }

    // Output to browser
    $pdf_content = file_get_contents($final_pdf_path);
    unlink($final_pdf_path);

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $contract_type . '_preview.pdf"');
    header('Content-Length: ' . strlen($pdf_content));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo $pdf_content;
    exit;

} catch (Exception $e) {
    error_log("Error generating spouse contract preview: " . $e->getMessage());
    http_response_code(500);
    die('Error generating preview: ' . htmlspecialchars($e->getMessage()));
}
