# Countersign Signature Feature

## Overview
This feature allows administrators to upload or draw a countersign signature for each contract package. The signature represents the company's approval and can be applied to all contracts within that package.

## Implementation Summary

### 1. Database Changes
**File:** `/src/migrations/add_countersign_signature.sql`

Added the following columns to `state_contract_packages` table:
- `countersign_signature` (MEDIUMBLOB) - Stores the signature image in PNG format
- `countersign_filename` (VARCHAR) - Original filename
- `countersign_uploaded_at` (TIMESTAMP) - Upload timestamp
- `countersign_uploaded_by` (INT) - Staff member who uploaded it

**To apply the migration:**
```bash
mysql < /path/to/your/project/public_html/src/migrations/add_countersign_signature.sql
```

### 2. Backend Changes
**File:** `/admin/settings.php`

Added three new POST handlers:
- `upload_countersign` - Handles image file uploads (PNG/JPEG)
- `save_countersign_canvas` - Handles canvas-drawn signatures
- `delete_countersign` - Removes countersign signature

### 3. Frontend Changes
**File:** `/admin/settings.php`

#### UI Components Added:
1. **Countersign Signature Section** - Appears at the top of each contract package
   - Shows existing signature if uploaded
   - Displays filename and upload date
   - Provides option to remove signature

2. **Two Input Methods:**
   - **Upload Image** - Upload PNG or JPEG files
   - **Draw Signature** - Opens a canvas modal to draw signature

3. **Countersign Modal** - Canvas-based signature drawing interface
   - 550x150 pixel drawing area
   - Supports mouse and touch events
   - Clear and save functionality

## Features

### Image Upload
- Accepts PNG and JPEG formats
- Validates MIME type on server
- Stores as BLOB in database
- Displays preview of uploaded signature

### Canvas Drawing
- Interactive HTML5 canvas
- Blue (#003399) stroke color
- 2px line width with rounded caps
- Touch-friendly for tablets
- Real-time drawing preview

### Security
- File type validation (server-side)
- MIME type verification
- Staff authentication required
- Audit trail with uploader tracking

## Usage

1. Navigate to **Admin > Settings > State Contracts tab**
2. Find the contract package you want to add a signature to
3. Scroll to the **"Countersign Signature"** section (highlighted in yellow)
4. Choose one of two options:
   - **Upload Image:** Select a PNG or JPEG file and click "Upload Image"
   - **Draw Signature:** Click "Draw Signature" to open the canvas modal
5. If drawing, use mouse or touch to draw, then click "Save Signature"
6. The signature will be saved and displayed in the section
7. To replace, simply upload a new image or draw a new signature
8. To remove, click the "Remove Signature" button

## Integration with Contracts

The countersign signature is stored in the `state_contract_packages` table and is available for:
- PDF contract generation
- Digital signing workflows
- Contract autofill operations

To use the signature in your contract code:
```php
// Example: Fetch package with countersign signature
$stmt = $pdo->prepare("SELECT * FROM state_contract_packages WHERE id = ?");
$stmt->execute([$package_id]);
$package = $stmt->fetch();

if (!empty($package['countersign_signature'])) {
    // Use base64 encoding for embedding in PDFs or web display
    $signature_base64 = base64_encode($package['countersign_signature']);

    // For web display:
    echo '<img src="data:image/png;base64,' . $signature_base64 . '" alt="Countersign">';

    // For PDF embedding (using TCPDF or similar):
    // Save to temp file or use @data
}
```

## Files Modified
- `/admin/settings.php` - Added UI, backend handlers, and JavaScript
- `/src/migrations/add_countersign_signature.sql` - Database schema changes

## Files Created
- `/docs/COUNTERSIGN_SIGNATURE_FEATURE.md` - This documentation

## Testing Checklist
- [ ] Apply database migration
- [ ] Log in as admin user
- [ ] Navigate to Settings > State Contracts
- [ ] Test image upload (PNG)
- [ ] Test image upload (JPEG)
- [ ] Test canvas drawing
- [ ] Test signature removal
- [ ] Verify signature persists after page reload
- [ ] Test on mobile/tablet (touch events)
- [ ] Verify signature data can be retrieved from database

## Future Enhancements
- Add signature to actual contract PDFs automatically
- Support for multiple signature formats per package
- Signature position configuration in PDF
- Signature preview in contract template
- Signature history/versioning
