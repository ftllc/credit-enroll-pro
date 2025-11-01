-- ============================================================================
-- Test Packages Table - Store generated contract packages
-- ============================================================================

CREATE TABLE IF NOT EXISTS test_packages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    package_id INT NOT NULL COMMENT 'Reference to state_contract_packages',
    xactosign_package_id VARCHAR(255) NOT NULL UNIQUE COMMENT 'XACT-XXXXXXXXXXXX format',
    status ENUM('processing', 'completed', 'failed') DEFAULT 'processing',
    pdf_data LONGBLOB NULL COMMENT 'Complete merged PDF with all documents',
    file_size INT NULL COMMENT 'Size of PDF in bytes',
    total_pages INT NULL COMMENT 'Total pages in merged PDF',
    error_message TEXT NULL COMMENT 'Error message if failed',
    croa_signature_data TEXT NULL COMMENT 'Base64 signature for CROA',
    agreement_signature_data TEXT NULL COMMENT 'Base64 signature for Agreement',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT NULL COMMENT 'Staff member who generated',
    completed_at TIMESTAMP NULL COMMENT 'When processing completed',
    INDEX idx_package_id (package_id),
    INDEX idx_xactosign_id (xactosign_package_id),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at),
    FOREIGN KEY (package_id) REFERENCES state_contract_packages(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES staff(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
