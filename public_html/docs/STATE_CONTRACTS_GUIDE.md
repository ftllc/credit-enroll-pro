# State Contracts System Guide

## Overview

The State Contracts system allows you to manage different contract packages for different states. Each package contains three required contracts:

1. **CROA Disclosure**
2. **Client Agreement**
3. **Notice of Cancellation**

## Features

### 1. Contract Packages
- Create multiple contract packages with unique names
- Each package can contain up to 3 contract documents (PDFs)
- One package must always be set as the **Default** package
- Default package is used when no state-specific package is assigned

### 2. State Assignment
- Assign contract packages to one or more states
- A single package can be assigned to multiple states
- States without specific assignments will use the default package

### 3. PDF Storage
- All PDFs are stored securely in the database as LONGBLOB
- Each PDF has a SHA-256 hash for integrity verification
- Maximum file size depends on server configuration (typically 16MB+)

## How to Use

### Creating a New Contract Package

1. Go to **Admin > Settings > State Contracts**
2. Fill in the "Create New Contract Package" form:
   - **Package Name**: Give it a descriptive name (e.g., "Texas Contracts")
   - **Set as Default**: Check this only if you want it to be the default
3. Click **Create Package**

### Uploading Contracts

For each package, upload all three required PDFs:

1. Click the **Upload PDF** or **Replace PDF** button for each contract type
2. Select the PDF file from your computer
3. Click the upload button
4. The system validates that the file is a valid PDF

### Assigning States

1. Scroll to the "Assigned States" section of a package
2. Check the boxes for all states that should use this contract package
3. Click **Update State Assignments**
4. The system will save the assignments

### Setting Default Package

- Only one package can be the default at a time
- To change the default, click **Set as Default** on a different package
- The previous default will automatically be unset
- You cannot delete the default package

### Deleting a Package

1. Make sure the package is not set as default
2. Click **Delete Package**
3. Confirm the deletion
4. All associated contracts and state mappings will be removed

## Technical Implementation

### Database Tables

1. **state_contract_packages**: Stores package metadata
2. **state_contract_documents**: Stores the actual PDF files
3. **state_contract_mappings**: Links packages to states

### Using Contracts in Your Application

Include the helper file in your code:

```php
require_once __DIR__ . '/src/state_contracts_helper.php';

// Get all contracts for a specific state
$state_code = 'TX'; // Two-letter state code
$package = get_contracts_for_state($pdo, $state_code);

if ($package) {
    // Access individual contracts
    $croa = $package['documents']['croa_disclosure'] ?? null;
    $agreement = $package['documents']['client_agreement'] ?? null;
    $cancellation = $package['documents']['notice_of_cancellation'] ?? null;
}

// Check if all contracts exist for a state
if (has_all_contracts_for_state($pdo, 'TX')) {
    echo "All contracts available for Texas";
}

// Get a specific contract document
$croa_doc = get_contract_document($pdo, 'CA', 'croa_disclosure');
if ($croa_doc) {
    // Use the PDF content
    $pdf_content = $croa_doc['contract_pdf'];
    $filename = $croa_doc['file_name'];
}
```

### Helper Functions Available

- `get_contracts_for_state($pdo, $state_code)` - Get package and all documents for a state
- `get_contract_document($pdo, $state_code, $contract_type)` - Get specific document
- `has_all_contracts_for_state($pdo, $state_code)` - Check if all 3 contracts exist
- `get_states_with_contracts($pdo)` - Get list of states with assigned contracts
- `verify_contract_integrity($pdf_content, $stored_hash)` - Verify PDF hasn't been corrupted

## Security Features

1. **Access Control**: Only admin staff can manage contracts
2. **PDF Validation**: System validates uploaded files are PDFs
3. **Integrity Checking**: SHA-256 hashes ensure PDFs haven't been corrupted
4. **Secure Storage**: PDFs stored as encrypted BLOBs in database
5. **Session Verification**: All operations require active admin session

## Best Practices

1. **Naming Convention**: Use clear, descriptive names like "Texas & Oklahoma Contracts"
2. **Always Have a Default**: Ensure one package is always set as default
3. **Test After Upload**: View each PDF after uploading to confirm it displays correctly
4. **Regular Backups**: Database backups will include all contract PDFs
5. **File Size**: Keep PDFs optimized (under 5MB recommended for performance)
6. **Update States**: When adding a new package, remember to assign states to it

## Troubleshooting

### "Only PDF files are allowed" Error
- Make sure the file is a valid PDF document
- Check that the file extension is .pdf
- Some image files renamed to .pdf won't work

### PDF Won't Display
- Check the file size isn't too large
- Verify the PDF isn't password-protected
- Try re-uploading the PDF

### Cannot Delete Package
- You cannot delete the default package
- Set another package as default first, then delete

### State Not Getting Correct Contracts
- Check which package is assigned to that state
- If no assignment, it will use the default package
- Verify the package has all three contracts uploaded

## API Reference

### File: `/admin/settings.php?tab=contracts`
Main interface for managing state contracts

### File: `/admin/download_contract.php?doc_id={id}`
Download/view a specific contract PDF (admin only)

### File: `/src/state_contracts_helper.php`
Helper functions for retrieving contracts in your application code

## Database Schema

```sql
-- Contract Packages Table
CREATE TABLE state_contract_packages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    package_name VARCHAR(255) NOT NULL,
    is_default TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT
);

-- Contract Documents Table
CREATE TABLE state_contract_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    package_id INT NOT NULL,
    contract_type ENUM('croa_disclosure', 'client_agreement', 'notice_of_cancellation') NOT NULL,
    contract_pdf LONGBLOB NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_size INT NOT NULL,
    mime_type VARCHAR(100) DEFAULT 'application/pdf',
    pdf_hash VARCHAR(64) NOT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    uploaded_by INT,
    UNIQUE KEY unique_package_contract (package_id, contract_type)
);

-- State Mappings Table
CREATE TABLE state_contract_mappings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    package_id INT NOT NULL,
    state_code CHAR(2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT,
    UNIQUE KEY unique_package_state (package_id, state_code)
);
```

## Support

For technical issues or questions, contact your system administrator.
