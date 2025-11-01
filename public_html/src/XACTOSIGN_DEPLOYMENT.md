# XactoSign Certificate Generator - Deployment Guide

## Overview
XactoSign Certificate Generator is a standalone PHP module that creates professional, DocuSign-style certificates for e-signed documents. This module can be deployed in any PHP application with minimal dependencies.

## Features
- **Professional Certificate Design**: Brand-colored certificates with watermarks and decorative elements
- **Comprehensive Signature Data**: Captures all relevant signing information (timestamps, IP addresses, user agents, etc.)
- **Standalone Module**: Works independently - just copy and use
- **PDF Generation**: Uses FPDF library for reliable PDF creation
- **DocuSign-Compatible Fields**: Includes all standard fields found in DocuSign certificates

## Requirements
- PHP 7.4 or higher
- FPDF library (included in fpdf186 folder)
- No database required for certificate generation
- No composer dependencies

## Files Included
```
src/
├── xactosign_verify.php         # Main certificate generator
├── fpdf186/                      # FPDF library folder
│   ├── fpdf.php                 # Core FPDF library
│   ├── font/                    # Font files
│   └── ...
└── XACTOSIGN_DEPLOYMENT.md      # This file
```

## Installation in Other Applications

### Method 1: Direct Copy (Recommended)
1. Copy the entire `src/fpdf186/` folder to your application
2. Copy `src/xactosign_verify.php` to your application
3. Ensure both are in the same directory OR update the path in line 18 of xactosign_verify.php

### Method 2: Custom Location
1. Place files wherever you want in your application
2. Update the FPDF include path in xactosign_verify.php:
   ```php
   require_once __DIR__ . '/path/to/fpdf186/fpdf.php';
   ```

## Usage

### Test Mode
Generate a sample certificate with fake data:
```
https://your-domain.com/path/to/xactosign_verify.php?mode=test
```

### Production Mode (Called from Your Application)

#### Option 1: Direct PHP Function Call
```php
<?php
// Include the certificate generator
require_once 'path/to/xactosign_verify.php';

// Prepare certificate data
$certificateData = [
    'company_name' => 'Your Company Name',
    'document_title' => 'Service Agreement',
    'certificate_id' => 'XACT-' . strtoupper(uniqid()),
    'generated_date' => date('F j, Y \a\t g:i A T'),
    'security_method' => 'SHA-256 Cryptographic Hash with Timestamp',
    'document_hash' => hash('sha256', $documentContent),
    'signatures' => [
        [
            'signer_name' => 'John Doe',
            'email' => 'john@example.com',
            'signed_datetime' => date('F j, Y \a\t g:i:s A'),
            'timezone' => 'America/New_York (EST)',
            'capture_method' => 'Electronic Signature (Typed)',
            'ip_address' => $_SERVER['REMOTE_ADDR'],
            'mac_address' => '', // Optional
            'user_agent' => $_SERVER['HTTP_USER_AGENT'],
            'signature_hash' => hash('sha256', $signatureData)
        ]
        // Add more signatures as needed
    ]
];

// Generate and output the certificate
generateCertificate($certificateData);
```

#### Option 2: HTTP POST Request
```php
<?php
$certificateData = [ /* same structure as above */ ];

$ch = curl_init('https://your-domain.com/path/to/xactosign_verify.php');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, [
    'certificate_data' => json_encode($certificateData)
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$pdfContent = curl_exec($ch);
curl_close($ch);

// Save or output the PDF
file_put_contents('certificate.pdf', $pdfContent);
```

## Data Structure Reference

### Complete Certificate Data Array
```php
$certificateData = [
    // Document Information
    'company_name' => 'string',          // Required: Company/organization name
    'document_title' => 'string',        // Optional: Title of signed document(s)
    'certificate_id' => 'string',        // Required: Unique certificate identifier
    'generated_date' => 'string',        // Required: Certificate generation timestamp

    // Security Information
    'security_method' => 'string',       // Required: Hashing/security method used
    'document_hash' => 'string',         // Optional: SHA-256 hash of document

    // Signature Events (array of signature objects)
    'signatures' => [
        [
            'signer_name' => 'string',       // Required: Full name of signer
            'email' => 'string',             // Required: Email address
            'signed_datetime' => 'string',   // Required: Date and time of signature
            'timezone' => 'string',          // Required: Timezone of signature
            'capture_method' => 'string',    // Required: How signature was captured
            'ip_address' => 'string',        // Required: IP address of signer
            'mac_address' => 'string',       // Optional: MAC address (if available)
            'user_agent' => 'string',        // Required: Browser/device info
            'signature_hash' => 'string'     // Optional: Cryptographic signature hash
        ],
        // Additional signatures...
    ]
];
```

### Field Examples

**Capture Methods:**
- "Electronic Signature (Typed)"
- "Electronic Signature (Drawn)"
- "Click to Sign"
- "SMS Verification"
- "Email Verification"

**Date Formats:**
- `generated_date`: "October 15, 2025 at 2:30 PM EDT"
- `signed_datetime`: "October 15, 2025 at 2:25:14 PM"

**Security Methods:**
- "SHA-256 Cryptographic Hash with Timestamp"
- "SHA-512 Cryptographic Hash with Timestamp"
- "RSA Digital Signature"

## Customization

### Branding Colors
Edit lines 178-181 in xactosign_verify.php:
```php
$brandBrown = [156, 96, 70];      // Primary brand color
$brandTan = [220, 216, 212];       // Secondary/background color
$darkGray = [51, 51, 51];          // Text color
$lightGray = [102, 102, 102];      // Secondary text
```

### Company Header
Edit line 195 to change company name:
```php
$pdf->Cell(185.58, 10, 'Your Company Name', 0, 1, 'C');
```

### Verification URL
Edit line 393 to update verification URL:
```php
$pdf->MultiCell(165.58, 5, 'Visit your-domain.com/verify and enter...', 0, 'L');
```

### Footer Contact
Edit lines 399-400:
```php
$pdf->Cell(0, 5, 'Generated by Your Company Name', 0, 1, 'C');
$pdf->Cell(0, 5, 'Contact: support@your-domain.com', 0, 1, 'C');
```

## Integration Examples

### Example 1: E-Signature Enrollment System
```php
<?php
// After user completes enrollment and signs documents
require_once 'xactosign_verify.php';

$certificateData = [
    'company_name' => $enrollmentData['company_name'],
    'document_title' => 'Credit Repair Service Agreement & Authorization',
    'certificate_id' => 'XACT-' . strtoupper(substr(md5($enrollmentId . time()), 0, 12)),
    'generated_date' => date('F j, Y \a\t g:i A T'),
    'security_method' => 'SHA-256 Cryptographic Hash with Timestamp',
    'document_hash' => hash('sha256', $signedDocumentPdf),
    'signatures' => []
];

// Add primary applicant signature
$certificateData['signatures'][] = [
    'signer_name' => $applicant['first_name'] . ' ' . $applicant['last_name'],
    'email' => $applicant['email'],
    'signed_datetime' => date('F j, Y \a\t g:i:s A', strtotime($applicant['signed_at'])),
    'timezone' => 'America/New_York (EST)',
    'capture_method' => 'Electronic Signature (Typed)',
    'ip_address' => $applicant['ip_address'],
    'mac_address' => '',
    'user_agent' => $applicant['user_agent'],
    'signature_hash' => hash('sha256', $applicant['signature_data'])
];

// Add co-applicant signature if exists
if (!empty($coapplicant)) {
    $certificateData['signatures'][] = [
        'signer_name' => $coapplicant['first_name'] . ' ' . $coapplicant['last_name'],
        'email' => $coapplicant['email'],
        'signed_datetime' => date('F j, Y \a\t g:i:s A', strtotime($coapplicant['signed_at'])),
        'timezone' => 'America/New_York (EST)',
        'capture_method' => 'Electronic Signature (Typed)',
        'ip_address' => $coapplicant['ip_address'],
        'mac_address' => '',
        'user_agent' => $coapplicant['user_agent'],
        'signature_hash' => hash('sha256', $coapplicant['signature_data'])
    ];
}

// Generate certificate
generateCertificate($certificateData);
```

### Example 2: Microservice Architecture
Create a dedicated endpoint:
```php
<?php
// certificate-service.php
require_once 'xactosign_verify.php';

// Validate API key
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
if ($apiKey !== 'your-secret-api-key') {
    http_response_code(401);
    die('Unauthorized');
}

// Get POST data
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    http_response_code(400);
    die('Invalid JSON');
}

// Generate certificate
generateCertificate($data);
```

Call from any application:
```php
<?php
$ch = curl_init('https://certificate-service.com/certificate-service.php');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'X-API-Key: your-secret-api-key'
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($certificateData));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$pdf = curl_exec($ch);
curl_close($ch);
```

## Storing Certificates

### Database Storage Example
```sql
CREATE TABLE signature_certificates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    certificate_id VARCHAR(50) UNIQUE NOT NULL,
    enrollment_id INT,
    pdf_content LONGBLOB,
    certificate_data JSON,
    generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cert_id (certificate_id),
    INDEX idx_enrollment (enrollment_id)
);
```

```php
<?php
// Generate certificate to variable instead of browser
ob_start();
generateCertificate($certificateData);
$pdfContent = ob_get_clean();

// Store in database
$stmt = $pdo->prepare("
    INSERT INTO signature_certificates
    (certificate_id, enrollment_id, pdf_content, certificate_data)
    VALUES (?, ?, ?, ?)
");
$stmt->execute([
    $certificateData['certificate_id'],
    $enrollmentId,
    $pdfContent,
    json_encode($certificateData)
]);
```

### File System Storage Example
```php
<?php
// Generate certificate to file
ob_start();
generateCertificate($certificateData);
$pdfContent = ob_get_clean();

// Create directory structure
$year = date('Y');
$month = date('m');
$dir = "certificates/$year/$month/";
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
}

// Save file
$filename = $certificateData['certificate_id'] . '.pdf';
file_put_contents($dir . $filename, $pdfContent);
```

## Security Considerations

1. **Access Control**: Protect the xactosign_verify.php file from unauthorized access
2. **Input Validation**: Always validate and sanitize certificate data before generation
3. **Rate Limiting**: Implement rate limiting to prevent abuse
4. **API Authentication**: Use API keys or tokens for remote calls
5. **Storage Security**: Encrypt sensitive certificate data in databases
6. **Audit Trail**: Log all certificate generation events

## Troubleshooting

### Common Issues

**Issue: "Call to undefined function imagecreate()"**
- Solution: FPDF doesn't require GD library. This error shouldn't occur.

**Issue: PDF generation fails silently**
- Check PHP error logs
- Ensure write permissions if saving to file
- Verify FPDF library path is correct

**Issue: Memory exhaustion**
- Increase PHP memory_limit in php.ini
- Avoid generating too many certificates in a single request

**Issue: Characters not displaying correctly**
- FPDF uses CP1252 encoding by default
- For UTF-8 support, consider using tFPDF or TCPDF

## Support & Maintenance

For questions or issues with XactoSign Certificate Generator:
- Email: support@transxacto.net
- Documentation: xs.transxacto.net/docs

## License

Copyright (c) 2025 TransXacto Networks
Proprietary and confidential.

## Version History

**v1.0** (October 2025)
- Initial release
- DocuSign-style certificate generation
- Multiple signature support
- Brand customization
- Test mode for development
