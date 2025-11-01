-- ============================================================================
-- State Contracts System Migration
-- ============================================================================

-- Table to store contract packages (sets of 3 contracts)
CREATE TABLE IF NOT EXISTS state_contract_packages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    package_name VARCHAR(255) NOT NULL,
    is_default TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT,
    INDEX idx_is_default (is_default),
    FOREIGN KEY (created_by) REFERENCES staff(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES staff(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table to store the actual contract PDFs for each package
CREATE TABLE IF NOT EXISTS state_contract_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    package_id INT NOT NULL,
    contract_type ENUM('croa_disclosure', 'client_agreement', 'notice_of_cancellation') NOT NULL,
    contract_pdf LONGBLOB NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_size INT NOT NULL,
    mime_type VARCHAR(100) DEFAULT 'application/pdf',
    pdf_hash VARCHAR(64) NOT NULL COMMENT 'SHA-256 hash for integrity verification',
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    uploaded_by INT,
    INDEX idx_package_type (package_id, contract_type),
    UNIQUE KEY unique_package_contract (package_id, contract_type),
    FOREIGN KEY (package_id) REFERENCES state_contract_packages(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES staff(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table to map contract packages to states
CREATE TABLE IF NOT EXISTS state_contract_mappings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    package_id INT NOT NULL,
    state_code CHAR(2) NOT NULL COMMENT 'Two-letter state code (e.g., TX, CA)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT,
    INDEX idx_state (state_code),
    INDEX idx_package (package_id),
    UNIQUE KEY unique_package_state (package_id, state_code),
    FOREIGN KEY (package_id) REFERENCES state_contract_packages(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES staff(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default package if none exists
INSERT INTO state_contract_packages (package_name, is_default, created_by)
SELECT 'Default Contract Package', 1, 1
WHERE NOT EXISTS (SELECT 1 FROM state_contract_packages WHERE is_default = 1);
