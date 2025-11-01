# Contract Autofill System Guide

## Overview

The State Contracts system now includes an autofill testing feature that allows you to preview how contract PDFs will be filled with actual enrollment data. This helps you verify that your PDF form fields are correctly named and configured.

## New Features

### 1. Days to Cancel

Each contract package now has a **"Days to Cancel"** setting that specifies how many days clients have to cancel their contract. This value is used to automatically calculate the cancellation deadline date.

**Default value:** 5 days
**Valid range:** 1-365 days

#### Setting Days to Cancel

1. Go to **Settings > State Contracts**
2. Find the package you want to modify
3. Update the "Days to Cancel" field
4. Click **Update**

The days to cancel value is used when filling the `notice_date` field in the Notice of Cancellation document.

### 2. Expected PDF Field Mapping

Each contract type now displays the **expected PDF form field names** that should exist in your uploaded PDFs:

#### CROA Disclosure
- `client_name` - Client's full name
- `enrollment_date` - Date the client enrolled
- `client_signature` - Client's signature (image field)

#### Client Agreement
- `client_name` - Client's full name
- `client_address` - Client's full address
- `monthly_fee` - Monthly service fee amount
- `initial_fee` - Initial/setup fee amount
- `start_date` - Service start date
- `signature_date` - Date the document is signed

#### Notice of Cancellation
- `notice_date` - Date by which client must cancel (calculated as today + days_to_cancel)

### 3. Test Autofill Button

For contracts with autofill support, a **"Test Autofill"** button appears next to each uploaded PDF. This allows you to:

- Test that your PDF form fields are correctly named
- Preview how the filled contract will look
- Verify the calculated dates are correct
- Ensure the autofill system works before going live

#### Currently Supported

✅ **CROA Disclosure** - Full autofill support
- Fills `client_name` with test name: "Spongebob Squarepants"
- Fills `enrollment_date` with today's date
- Generates and inserts canvas-style signature image in `client_signature` field

✅ **Notice of Cancellation** - Full autofill support
- Automatically fills `notice_date` with calculated cancellation deadline

#### Coming Soon

⏳ **Client Agreement** - Autofill support planned

## How to Use Test Autofill

### Step 1: Prepare Your PDF

Your PDF must be a **fillable PDF form** with named form fields. The field names must match exactly what's shown in the "Expected PDF Fields" section.

**Creating fillable PDFs:**
- Use Adobe Acrobat Pro
- Use free tools like PDFescape or JotForm PDF Editor
- Ensure field names match exactly (case-sensitive)

### Step 2: Upload the PDF

1. Go to **Settings > State Contracts**
2. Select your contract package
3. Upload your PDF for the desired contract type
4. The system validates it's a PDF and stores it securely

### Step 3: Test Autofill

1. After uploading, click the **"Test Autofill"** button (green button)
2. A new tab opens showing the filled PDF
3. Verify the fields were filled correctly
4. Check that dates are formatted properly

**For Notice of Cancellation:**
- The `notice_date` field will be filled with: Today's Date + Days to Cancel
- Example: If today is 10/15/2025 and Days to Cancel = 5, notice_date = 10/20/2025
- Date format: MM/DD/YYYY

### Step 4: Troubleshooting

If autofill doesn't work:

1. **Check field names** - Must match exactly (case-sensitive)
   - ✅ Correct: `notice_date`
   - ❌ Wrong: `Notice_Date`, `noticeDate`, `notice date`

2. **Verify PDF is fillable**
   - Open in Adobe Acrobat and try typing in fields manually
   - If you can't type in Adobe, the PDF isn't fillable

3. **Check field types**
   - Use text fields, not static text
   - Fields must be editable form fields

4. **Test in Adobe Acrobat**
   - Open your PDF in Adobe Acrobat Pro
   - Go to Tools > Prepare Form
   - Verify field names are correct

## Technical Details

### PDF Form Filling Technology

The system uses **PDFtk (PDF Toolkit)** to fill form fields:
- Industry-standard PDF manipulation tool
- Installed on the server at `/usr/bin/pdftk`
- Fills fields without altering PDF structure
- Supports all standard PDF form field types

### How Autofill Works

1. System retrieves the uploaded PDF from database
2. Calculates field values based on package settings:
   - `notice_date` = Current Date + `days_to_cancel`
3. Creates an FDF (Forms Data Format) file with the values
4. Uses PDFtk to merge the FDF data into the PDF
5. Returns the filled PDF for preview
6. Original PDF remains unchanged

### Date Calculation

For Notice of Cancellation:
```php
$days_to_cancel = 5; // From package settings
$notice_date = date('m/d/Y', strtotime("+{$days_to_cancel} days"));
// Result: 10/20/2025 (if today is 10/15/2025)
```

### Security

- Only admins can access test autofill
- Original PDFs are never modified
- Filled PDFs are generated in memory
- No temporary files remain after preview
- SHA-256 integrity checking on all PDFs

## Integration with Enrollment System

When a client enrolls, the system will:

1. Detect the client's state
2. Retrieve the appropriate contract package
3. Autofill all contracts with client data:
   - Name, address from enrollment form
   - Fees from selected plan
   - Calculated dates based on package settings
4. Present filled contracts to client for e-signature
5. Store signed contracts in database

## Example: CROA Disclosure Setup with Signature

### PDF Form Field Setup

In Adobe Acrobat Pro:

1. **Add Text Fields**
   - Add a text field for `client_name`
   - Add a text field for `enrollment_date`
   - Set field names exactly as shown (case-sensitive)

2. **Add Signature Image Field**
   - Add an **Image Field** (not text field) named `client_signature`
   - In Adobe Acrobat: Tools > Prepare Form > Add Image Field
   - Position and size it where the signature should appear
   - Set properties:
     - Field Name: `client_signature`
     - Field Type: Image/Button field
     - Icon: None initially
   - The autofill system will stamp the signature image onto this location

3. **Save the PDF**

### Test Data Used

When you click "Test Autofill" on CROA Disclosure:
- **client_name**: "Spongebob Squarepants"
- **enrollment_date**: Today's date (MM/DD/YYYY format)
- **client_signature**: Auto-generated canvas signature image

### Technical Notes

The signature is generated dynamically:
1. System calls `generate_test_signature.php` to create a PNG image
2. Image shows "Spongebob Squarepants" in italic/cursive style
3. Image is converted to PDF format
4. Signature PDF is stamped onto the main document
5. Final PDF is flattened for viewing

**Requirements for signature autofill:**
- ImageMagick must be installed (for PNG to PDF conversion)
- PDFtk must be installed (for PDF stamping)
- Both are already installed on your server

## Example: Notice of Cancellation Setup

### PDF Form Field Setup

In Adobe Acrobat Pro:
1. Open your Notice of Cancellation PDF
2. Go to Tools > Prepare Form
3. Add a text field where the date should appear
4. Set the field name to: `notice_date`
5. Set field properties:
   - Type: Text Field
   - Format: None (or Date if available)
   - Read Only: Unchecked
6. Save the PDF

### Upload and Test

1. Upload the PDF to your contract package
2. Set "Days to Cancel" to 5 (or your preferred number)
3. Click "Test Autofill"
4. Verify the date appears in the correct location
5. Confirm the date is 5 days in the future

### Expected Result

If today is October 15, 2025, and Days to Cancel = 5:
- The `notice_date` field will show: **10/20/2025**

## Troubleshooting Guide

### "Autofill is only available for Notice of Cancellation"

This is expected. Only Notice of Cancellation supports autofill currently. The other contract types will be added in future updates.

### "Failed to fill PDF form"

**Possible causes:**
1. PDF doesn't contain a form field named `notice_date`
2. PDF is not a fillable form (just a regular PDF)
3. Field name spelling/capitalization is incorrect

**Solution:**
- Open PDF in Adobe Acrobat Pro
- Use "Prepare Form" tool to verify field names
- Ensure field is named exactly: `notice_date` (lowercase, underscore)

### "PDF form filling tool (pdftk) is not installed"

Contact your system administrator. PDFtk needs to be installed:
```bash
sudo apt-get install pdftk
```

### Date appears in wrong format

The system outputs dates in MM/DD/YYYY format. If your PDF field has date formatting:
- Check field properties in Adobe Acrobat
- Set field format to "None" or "Custom"
- Test again with Test Autofill

### Field fills but text is invisible

**Possible causes:**
1. Field text color is set to white
2. Field font size is too large or too small
3. Field is too small for the text

**Solution:**
- In Adobe Acrobat, check field properties
- Set text color to black
- Set appropriate font size (12pt recommended)
- Make field large enough for date text

## Future Enhancements

### Planned Features

1. **CROA Disclosure Autofill**
   - Client name and address
   - Company information
   - Signature date

2. **Client Agreement Autofill**
   - All client information
   - Plan fees
   - Service dates

3. **Custom Field Mapping**
   - Map your own field names
   - Support different PDF formats
   - Field name aliases

4. **Bulk Testing**
   - Test all contracts in a package at once
   - Download filled package as ZIP
   - Compare different packages

## Support

For questions or issues:
1. Check this guide first
2. Verify PDF field names match expected names
3. Test PDF in Adobe Acrobat manually
4. Contact system administrator if problems persist

## Additional Resources

- [Adobe: Creating Fillable PDFs](https://helpx.adobe.com/acrobat/using/creating-fillable-pdfs.html)
- [PDFtk Documentation](https://www.pdflabs.com/docs/pdftk-man-page/)
- [State Contracts System Guide](STATE_CONTRACTS_GUIDE.md)
