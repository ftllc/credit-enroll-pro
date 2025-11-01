<?php
/**
 * XactoSign Certificate Generator - v1.1
 * Enhanced with blue/green color theme and canvas signature support
 */

require_once __DIR__ . '/fpdf186/fpdf.php';

class PDF_Certificate extends FPDF
{
    private $extgstates = [];
    private $angle = 0;

    function drawWatermark()
    {
        // Light blue/green gradient backgrounds
        $this->SetFillColor(240, 248, 255);
        $this->Rect(0, 0, 216, 35, 'F');
        $this->SetFillColor(245, 255, 250);
        $this->Rect(0, 35, 216, 15, 'F');

        // Decorative circles
        $this->SetAlpha(0.03);
        $this->SetFillColor(30, 144, 255);
        $this->Circle(20, 20, 40, 'F');
        $this->Circle(196, 20, 40, 'F');
        $this->SetFillColor(34, 139, 34);
        $this->Circle(20, 260, 40, 'F');
        $this->Circle(196, 260, 40, 'F');

        // Watermark text
        $this->SetAlpha(0.04);
        $this->SetFont('Arial', 'B', 70);
        $this->SetTextColor(30, 144, 255);
        $this->RotatedText(50, 150, 'XACTOSIGN', 45);

        // Corner brackets
        $this->SetAlpha(1);
        $this->SetDrawColor(34, 139, 34);
        $this->SetLineWidth(1.5);
        $this->Line(15, 15, 30, 15);
        $this->Line(15, 15, 15, 30);
        $this->Line(186, 15, 201, 15);
        $this->Line(201, 15, 201, 30);
        $this->Line(15, 264, 30, 264);
        $this->Line(15, 249, 15, 264);
        $this->Line(186, 264, 201, 264);
        $this->Line(201, 249, 201, 264);
    }

    function SetAlpha($alpha) { $this->SetExtGState(['ca' => $alpha, 'CA' => $alpha]); }
    function SetExtGState($params) { $this->_out(sprintf('/GS%d gs', $this->_getExtGState($params))); }
    function _getExtGState($params) { $n = count($this->extgstates) + 1; $this->extgstates[$n] = ['params' => $params]; return $n; }
    
    function _putextgstates()
    {
        for ($i = 1; $i <= count($this->extgstates); $i++) {
            $this->_newobj();
            $this->extgstates[$i]['n'] = $this->n;
            $this->_put('<</Type /ExtGState');
            foreach ($this->extgstates[$i]['params'] as $k => $v) $this->_put('/' . $k . ' ' . $v);
            $this->_put('>>');
            $this->_put('endobj');
        }
    }

    function _putresourcedict()
    {
        parent::_putresourcedict();
        $this->_put('/ExtGState <<');
        foreach ($this->extgstates as $k => $extgstate) $this->_put('/GS' . $k . ' ' . $extgstate['n'] . ' 0 R');
        $this->_put('>>');
    }

    function _putresources() { if (!empty($this->extgstates)) $this->_putextgstates(); parent::_putresources(); }

    function Circle($x, $y, $r, $style = 'D') { $this->Ellipse($x, $y, $r, $r, $style); }

    function Ellipse($x, $y, $rx, $ry, $style = 'D')
    {
        $op = ($style == 'F') ? 'f' : (($style == 'FD' || $style == 'DF') ? 'B' : 'S');
        $lx = 4/3 * (M_SQRT2 - 1) * $rx;
        $ly = 4/3 * (M_SQRT2 - 1) * $ry;
        $k = $this->k;
        $h = $this->h;
        $this->_out(sprintf('%.2F %.2F m %.2F %.2F %.2F %.2F %.2F %.2F c',
            ($x + $rx) * $k, ($h - $y) * $k, ($x + $rx) * $k, ($h - ($y - $ly)) * $k,
            ($x + $lx) * $k, ($h - ($y - $ry)) * $k, $x * $k, ($h - ($y - $ry)) * $k));
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c',
            ($x - $lx) * $k, ($h - ($y - $ry)) * $k, ($x - $rx) * $k, ($h - ($y - $ly)) * $k,
            ($x - $rx) * $k, ($h - $y) * $k));
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c',
            ($x - $rx) * $k, ($h - ($y + $ly)) * $k, ($x - $lx) * $k, ($h - ($y + $ry)) * $k,
            $x * $k, ($h - ($y + $ry)) * $k));
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c %s',
            ($x + $lx) * $k, ($h - ($y + $ry)) * $k, ($x + $rx) * $k, ($h - ($y + $ly)) * $k,
            ($x + $rx) * $k, ($h - $y) * $k, $op));
    }

    function RotatedText($x, $y, $txt, $angle) { $this->Rotate($angle, $x, $y); $this->Text($x, $y, $txt); $this->Rotate(0); }

    function Rotate($angle, $x = -1, $y = -1)
    {
        if ($x == -1) $x = $this->x;
        if ($y == -1) $y = $this->y;
        if ($this->angle != 0) $this->_out('Q');
        $this->angle = $angle;
        if ($angle != 0) {
            $angle *= M_PI / 180;
            $c = cos($angle);
            $s = sin($angle);
            $cx = $x * $this->k;
            $cy = ($this->h - $y) * $this->k;
            $this->_out(sprintf('q %.5F %.5F %.5F %.5F %.2F %.2F cm 1 0 0 1 %.2F %.2F cm', $c, $s, -$s, $c, $cx, $cy, -$cx, -$cy));
        }
    }
}

function generateCertificate($data)
{
    $pdf = new PDF_Certificate('P', 'mm', 'Letter');
    $pdf->SetAutoPageBreak(false);
    $pdf->AddPage();
    $pdf->drawWatermark();

    // Colors
    $blue = [30, 144, 255];
    $green = [34, 139, 34];
    $lightBlue = [135, 206, 250];
    $lightGreen = [144, 238, 144];
    $dark = [45, 45, 45];
    $gray = [85, 85, 85];

    // Border
    $pdf->SetDrawColor($blue[0], $blue[1], $blue[2]);
    $pdf->SetLineWidth(2);
    $pdf->Rect(10, 10, 195.58, 259.41);
    $pdf->SetDrawColor($green[0], $green[1], $green[2]);
    $pdf->SetLineWidth(0.5);
    $pdf->Rect(12, 12, 191.58, 255.41);

    // Header
    $pdf->SetFont('Arial', 'B', 26);
    $pdf->SetTextColor($blue[0], $blue[1], $blue[2]);
    $pdf->SetXY(15, 22);
    $pdf->Cell(185.58, 10, $data['company_name'] ?? 'TransXacto Networks', 0, 1, 'C');
    $pdf->SetFont('Arial', 'B', 19);
    $pdf->SetTextColor($green[0], $green[1], $green[2]);
    $pdf->SetXY(15, 34);
    $pdf->Cell(185.58, 8, 'XactoSign Certificate of Signature', 0, 1, 'C');

    // Lines
    $pdf->SetDrawColor($blue[0], $blue[1], $blue[2]);
    $pdf->SetLineWidth(0.8);
    $pdf->Line(35, 47, 181, 47);
    $pdf->SetDrawColor($green[0], $green[1], $green[2]);
    $pdf->SetLineWidth(0.4);
    $pdf->Line(40, 49, 176, 49);

    // Body
    $pdf->SetFont('Arial', '', 10);
    $pdf->SetTextColor($dark[0], $dark[1], $dark[2]);
    $pdf->SetXY(20, 55);
    $pdf->MultiCell(175.58, 5, 'This certificate verifies that the electronic signature(s) listed below were captured and authenticated using the XactoSign digital signature platform. All signature events have been cryptographically sealed and timestamped.', 0, 'C');

    $y = 72;

    // Document Info
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->SetTextColor($blue[0], $blue[1], $blue[2]);
    $pdf->SetXY(20, $y);
    $pdf->Cell(0, 7, 'DOCUMENT INFORMATION', 0, 1);
    $y += 9;

    $pdf->SetFillColor($lightBlue[0], $lightBlue[1], $lightBlue[2]);
    $pdf->SetAlpha(0.1);
    $pdf->Rect(18, $y - 2, 179.58, 28, 'F');
    $pdf->SetAlpha(1);

    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetTextColor($dark[0], $dark[1], $dark[2]);
    $pdf->SetXY(22, $y);
    $pdf->Cell(48, 5, 'Company Name:', 0, 0);
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(0, 5, $data['company_name'], 0, 1);
    $y += 6;

    if (!empty($data['document_title'])) {
        $pdf->SetXY(22, $y);
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell(48, 5, 'Document Title:', 0, 0);
        $pdf->SetFont('Arial', '', 9);
        $pdf->Cell(0, 5, $data['document_title'], 0, 1);
        $y += 6;
    }

    $pdf->SetXY(22, $y);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(48, 5, 'Certificate ID:', 0, 0);
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(0, 5, $data['certificate_id'], 0, 1);
    $y += 6;

    $pdf->SetXY(22, $y);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(48, 5, 'Generated:', 0, 0);
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(0, 5, $data['generated_date'], 0, 1);
    $y += 12;

    // Signatures
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->SetTextColor($green[0], $green[1], $green[2]);
    $pdf->SetXY(20, $y);
    $pdf->Cell(0, 7, 'SIGNATURE EVENTS', 0, 1);
    $y += 9;

    foreach ($data['signatures'] as $idx => $sig) {
        if ($y > 220) {
            $pdf->AddPage();
            $pdf->drawWatermark();
            $y = 20;
        }

        $sigNum = $idx + 1;
        $blockHeight = !empty($sig['signature_image_data']) ? 70 : 48;

        $pdf->SetFillColor($lightGreen[0], $lightGreen[1], $lightGreen[2]);
        $pdf->SetAlpha(0.1);
        $pdf->Rect(18, $y - 2, 179.58, $blockHeight, 'F');
        $pdf->SetAlpha(1);

        $pdf->SetFont('Arial', 'B', 10);
        $pdf->SetTextColor($green[0], $green[1], $green[2]);
        $pdf->SetXY(22, $y);
        $sig_header = "Signature #{$sigNum}: {$sig['signer_name']}";
        if (!empty($sig['signature_id'])) {
            $sig_header .= " ({$sig['signature_id']})";
        }
        $pdf->Cell(0, 5, $sig_header, 0, 1);
        $y += 7;

        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetTextColor($gray[0], $gray[1], $gray[2]);
        $pdf->SetXY(26, $y);
        $pdf->Cell(40, 4, 'Signed Date & Time:', 0, 0);
        $pdf->SetFont('Arial', '', 8);
        $pdf->Cell(0, 4, $sig['signed_datetime'], 0, 1);
        $y += 4.5;

        $pdf->SetXY(26, $y);
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->Cell(40, 4, 'Timezone:', 0, 0);
        $pdf->SetFont('Arial', '', 8);
        $pdf->Cell(0, 4, $sig['timezone'], 0, 1);
        $y += 4.5;

        $pdf->SetXY(26, $y);
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->Cell(40, 4, 'Capture Method:', 0, 0);
        $pdf->SetFont('Arial', '', 8);
        $pdf->Cell(0, 4, $sig['capture_method'], 0, 1);
        $y += 4.5;

        $pdf->SetXY(26, $y);
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->Cell(40, 4, 'Email:', 0, 0);
        $pdf->SetFont('Arial', '', 8);
        $pdf->Cell(0, 4, $sig['email'], 0, 1);
        $y += 4.5;

        $pdf->SetXY(26, $y);
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->Cell(40, 4, 'IP Address:', 0, 0);
        $pdf->SetFont('Arial', '', 8);
        $pdf->Cell(0, 4, $sig['ip_address'], 0, 1);
        $y += 4.5;

        if (!empty($sig['mac_address'])) {
            $pdf->SetXY(26, $y);
            $pdf->SetFont('Arial', 'B', 8);
            $pdf->Cell(40, 4, 'MAC Address:', 0, 0);
            $pdf->SetFont('Arial', '', 8);
            $pdf->Cell(0, 4, $sig['mac_address'], 0, 1);
            $y += 4.5;
        }

        $pdf->SetXY(26, $y);
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->Cell(40, 4, 'User Agent:', 0, 0);
        $pdf->SetFont('Arial', '', 8);
        $pdf->SetXY(66, $y);
        $pdf->MultiCell(125, 4, $sig['user_agent'], 0, 'L');
        $y = $pdf->GetY() + 1;

        // Canvas signature image
        if (!empty($sig['signature_image_data'])) {
            $pdf->SetXY(26, $y);
            $pdf->SetFont('Arial', 'B', 8);
            $pdf->Cell(0, 4, 'Digital Signature (Canvas):', 0, 1);
            $y += 5;

            $imageData = $sig['signature_image_data'];
            if (strpos($imageData, 'base64,') !== false) {
                $imageData = explode('base64,', $imageData)[1];
            }
            $imageData = base64_decode($imageData);

            $tempFile = sys_get_temp_dir() . '/sig_' . uniqid() . '.png';
            file_put_contents($tempFile, $imageData);

            $pdf->SetDrawColor($blue[0], $blue[1], $blue[2]);
            $pdf->SetLineWidth(0.3);
            $pdf->Rect(26, $y, 80, 20);
            $pdf->Image($tempFile, 27, $y + 1, 78, 18);

            @unlink($tempFile);
            $y += 22;
        } elseif (!empty($sig['signature_hash'])) {
            $pdf->SetXY(26, $y);
            $pdf->SetFont('Arial', 'B', 8);
            $pdf->Cell(40, 4, 'Digital Signature:', 0, 0);
            $pdf->SetFont('Courier', '', 7);
            $pdf->Cell(0, 4, substr($sig['signature_hash'], 0, 45) . '...', 0, 1);
            $y += 5;
        }

        $y += 6;
    }

    // Security
    if ($y > 215) {
        $pdf->AddPage();
        $pdf->drawWatermark();
        $y = 20;
    }

    $y += 3;
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->SetTextColor($blue[0], $blue[1], $blue[2]);
    $pdf->SetXY(20, $y);
    $pdf->Cell(0, 7, 'SECURITY & VERIFICATION', 0, 1);
    $y += 9;

    $pdf->SetFillColor($lightBlue[0], $lightBlue[1], $lightBlue[2]);
    $pdf->SetAlpha(0.1);
    $pdf->Rect(18, $y - 2, 179.58, 20, 'F');
    $pdf->SetAlpha(1);

    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetTextColor($dark[0], $dark[1], $dark[2]);
    $pdf->SetXY(22, $y);
    $pdf->Cell(40, 5, 'Security Method:', 0, 0);
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(0, 5, $data['security_method'], 0, 1);
    $y += 6;

    if (!empty($data['document_hash'])) {
        $pdf->SetXY(22, $y);
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell(40, 5, 'Document Hash:', 0, 0);
        $pdf->SetFont('Courier', '', 7);
        $pdf->Cell(0, 5, $data['document_hash'], 0, 1);
        $y += 6;
    }

    // Verification box
    $y += 5;
    $pdf->SetFillColor($green[0], $green[1], $green[2]);
    $pdf->SetAlpha(0.1);
    $pdf->Rect(18, $y, 179.58, 18, 'F');
    $pdf->SetAlpha(1);

    $pdf->SetXY(22, $y + 2);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetTextColor($green[0], $green[1], $green[2]);
    $pdf->Cell(0, 5, 'Verify This Certificate:', 0, 1);

    $pdf->SetXY(22, $y + 9);
    $pdf->SetFont('Arial', '', 8);
    $pdf->SetTextColor($gray[0], $gray[1], $gray[2]);
    $pdf->MultiCell(170, 4, 'Visit xs.transxacto.net/verify and enter the Certificate ID above to authenticate this document and verify all signature details.', 0, 'L');

    // Footer
    $pdf->SetY(-18);
    $pdf->SetFont('Arial', 'I', 7);
    $pdf->SetTextColor($gray[0], $gray[1], $gray[2]);
    $pdf->Cell(0, 4, 'This certificate was generated by XactoSign, a product of TransXacto Networks', 0, 1, 'C');
    $pdf->Cell(0, 4, 'For questions, contact support@transxacto.net', 0, 1, 'C');

    $pdf->Output('I', 'XactoSign_Certificate_' . $data['certificate_id'] . '.pdf');
}

function getTestData()
{
    // Create sample signature image
    $img = imagecreatetruecolor(300, 80);
    $white = imagecolorallocate($img, 255, 255, 255);
    $blue = imagecolorallocate($img, 30, 144, 255);
    imagefill($img, 0, 0, $white);
    imagesetthickness($img, 3);

    for ($x = 10; $x < 290; $x += 5) {
        $y1 = 40 + sin($x / 20) * 15;
        $y2 = 40 + sin(($x + 5) / 20) * 15;
        imageline($img, $x, $y1, $x + 5, $y2, $blue);
    }

    ob_start();
    imagepng($img);
    $imageData = ob_get_clean();
    imagedestroy($img);
    $base64Sig = 'data:image/png;base64,' . base64_encode($imageData);

    return [
        'company_name' => 'Acme Credit Repair Services LLC',
        'document_title' => 'Client Service Agreement & Authorization Forms',
        'certificate_id' => 'XACT-' . strtoupper(substr(md5(time()), 0, 12)),
        'generated_date' => date('F j, Y \a\t g:i A T'),
        'security_method' => 'SHA-256 Cryptographic Hash with Timestamp',
        'document_hash' => strtoupper(substr(hash('sha256', 'test_document_' . time()), 0, 64)),
        'signatures' => [
            [
                'signer_name' => 'John Michael Smith',
                'email' => 'john.smith@example.com',
                'signed_datetime' => date('F j, Y \a\t g:i:s A', strtotime('-2 hours')),
                'timezone' => 'America/New_York (EST)',
                'capture_method' => 'Electronic Signature (Canvas Drawn)',
                'ip_address' => '192.168.1.45',
                'mac_address' => 'A4:83:E7:2F:91:3C',
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36',
                'signature_image_data' => $base64Sig,
                'signature_hash' => hash('sha256', 'signature1_' . time())
            ],
            [
                'signer_name' => 'Sarah Elizabeth Johnson',
                'email' => 'sarah.johnson@example.com',
                'signed_datetime' => date('F j, Y \a\t g:i:s A', strtotime('-1 hour')),
                'timezone' => 'America/New_York (EST)',
                'capture_method' => 'Electronic Signature (Typed)',
                'ip_address' => '192.168.1.45',
                'mac_address' => 'A4:83:E7:2F:91:3C',
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36',
                'signature_hash' => hash('sha256', 'signature2_' . time())
            ]
        ]
    ];
}

if ($_GET['mode'] ?? '' === 'test') {
    generateCertificate(getTestData());
} else {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['certificate_data'])) {
        $data = json_decode($_POST['certificate_data'], true);
        if ($data) {
            generateCertificate($data);
        } else {
            header('HTTP/1.1 400 Bad Request');
            echo 'Invalid certificate data';
        }
    } else {
        header('HTTP/1.1 400 Bad Request');
        echo 'No certificate data provided. Use ?mode=test for a demonstration.';
    }
}
