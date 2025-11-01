-- Credit Repair Enrollment System Database Schema
-- Generic system that can be branded for any company

-- Staff table
CREATE TABLE IF NOT EXISTS staff (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) UNIQUE NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    xactoauth_user_id VARCHAR(255) NULL,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(255) NOT NULL,
    phone VARCHAR(20),
    role ENUM('admin', 'staff', 'manager') DEFAULT 'staff',
    is_active BOOLEAN DEFAULT TRUE,
    receive_step_notifications BOOLEAN DEFAULT FALSE,
    notify_enrollment_started BOOLEAN DEFAULT FALSE,
    notify_contracts_signed BOOLEAN DEFAULT FALSE,
    notify_enrollment_complete BOOLEAN DEFAULT FALSE,
    notify_ids_uploaded BOOLEAN DEFAULT FALSE,
    notify_spouse_contracted BOOLEAN DEFAULT FALSE,
    notify_spouse_ids_uploaded BOOLEAN DEFAULT FALSE,
    totp_secret VARCHAR(255) NULL,
    totp_enabled BOOLEAN DEFAULT FALSE,
    sms_2fa_enabled BOOLEAN DEFAULT FALSE,
    email_2fa_enabled BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    INDEX idx_username (username),
    INDEX idx_email (email),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Plans table (moved before enrollment_users to fix foreign key issue)
CREATE TABLE IF NOT EXISTS plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    plan_name VARCHAR(100) NOT NULL,
    plan_type ENUM('individual', 'couple') NOT NULL UNIQUE,
    initial_work_fee DECIMAL(10, 2) NOT NULL,
    monthly_fee DECIMAL(10, 2) NOT NULL,
    description TEXT,
    features JSON COMMENT 'Array of plan features',
    is_active BOOLEAN DEFAULT TRUE,
    display_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_plan_type (plan_type),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Affiliates table (moved up to be available for enrollment_users)
CREATE TABLE IF NOT EXISTS affiliates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    affiliate_code VARCHAR(50) UNIQUE NOT NULL,
    affiliate_name VARCHAR(255) NOT NULL,
    contact_email VARCHAR(255),
    contact_phone VARCHAR(20),
    notes TEXT,
    total_referrals INT DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_code (affiliate_code),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- State contract packages table
CREATE TABLE IF NOT EXISTS state_contract_packages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    package_name VARCHAR(255) NOT NULL,
    xactosign_client_id VARCHAR(50) NULL COMMENT 'Client ID for XactoSign certificate generation',
    is_default BOOLEAN DEFAULT FALSE,
    days_to_cancel INT DEFAULT 5 COMMENT 'Number of days allowed for contract cancellation',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT NULL,
    countersign_signature MEDIUMBLOB COMMENT 'Company countersignature image (PNG format)',
    countersign_filename VARCHAR(255) NULL COMMENT 'Original filename of countersignature',
    countersign_uploaded_at TIMESTAMP NULL COMMENT 'When countersignature was uploaded',
    countersign_uploaded_by INT NULL COMMENT 'Staff member who uploaded the countersignature',
    croa_client_sig_page INT DEFAULT 2 COMMENT 'CROA client signature page number',
    croa_client_sig_x1 DECIMAL(10,2) DEFAULT 84.00 COMMENT 'CROA client signature top-left X',
    croa_client_sig_y1 DECIMAL(10,2) DEFAULT 145.00 COMMENT 'CROA client signature top-left Y',
    croa_client_sig_x2 DECIMAL(10,2) DEFAULT 327.00 COMMENT 'CROA client signature bottom-right X',
    croa_client_sig_y2 DECIMAL(10,2) DEFAULT 167.00 COMMENT 'CROA client signature bottom-right Y',
    agreement_client_sig_page VARCHAR(10) DEFAULT 'last' COMMENT 'Client agreement client signature page (number or "last")',
    agreement_client_sig_x1 DECIMAL(10,2) DEFAULT 96.00 COMMENT 'Client agreement client signature top-left X',
    agreement_client_sig_y1 DECIMAL(10,2) DEFAULT 457.00 COMMENT 'Client agreement client signature top-left Y',
    agreement_client_sig_x2 DECIMAL(10,2) DEFAULT 283.00 COMMENT 'Client agreement client signature bottom-right X',
    agreement_client_sig_y2 DECIMAL(10,2) DEFAULT 480.00 COMMENT 'Client agreement client signature bottom-right Y',
    agreement_counter_sig_page VARCHAR(10) DEFAULT 'last' COMMENT 'Client agreement counter signature page (number or "last")',
    agreement_counter_sig_x1 DECIMAL(10,2) DEFAULT 182.00 COMMENT 'Client agreement counter signature top-left X',
    agreement_counter_sig_y1 DECIMAL(10,2) DEFAULT 399.00 COMMENT 'Client agreement counter signature top-left Y',
    agreement_counter_sig_x2 DECIMAL(10,2) DEFAULT 374.00 COMMENT 'Client agreement counter signature bottom-right X',
    agreement_counter_sig_y2 DECIMAL(10,2) DEFAULT 422.00 COMMENT 'Client agreement counter signature bottom-right Y',

    INDEX idx_is_default (is_default),
    FOREIGN KEY (created_by) REFERENCES staff(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES staff(id) ON DELETE SET NULL,
    FOREIGN KEY (countersign_uploaded_by) REFERENCES staff(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Enrollment users table
CREATE TABLE IF NOT EXISTS enrollment_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id VARCHAR(9) UNIQUE NOT NULL COMMENT 'Format: ABCD-1234',
    first_name VARCHAR(100),
    last_name VARCHAR(100),
    email VARCHAR(255),
    phone VARCHAR(20),

    -- Spouse information
    has_spouse BOOLEAN DEFAULT FALSE,
    spouse_first_name VARCHAR(100),
    spouse_last_name VARCHAR(100),
    spouse_email VARCHAR(255),
    spouse_phone VARCHAR(20),
    spouse_enrolled BOOLEAN DEFAULT FALSE COMMENT 'Whether spouse completed their portion',
    dual_enrollment BOOLEAN DEFAULT FALSE COMMENT 'Whether enrolling together or separate',

    -- Address information
    address_line1 VARCHAR(255),
    address_line2 VARCHAR(255),
    city VARCHAR(100),
    state VARCHAR(50),
    zip_code VARCHAR(20),
    country VARCHAR(100) DEFAULT 'United States',

    -- Personal information (collected later in flow)
    date_of_birth DATE,
    ssn_encrypted BLOB COMMENT 'Encrypted SSN',
    spouse_dob DATE,
    spouse_ssn_encrypted BLOB,

    -- Plan and affiliate
    plan_id INT,
    package_id INT,
    xactosign_package_id VARCHAR(50),
    crc_record_id VARCHAR(50),
    systeme_contact_id VARCHAR(50),
    zoho_contact_id VARCHAR(100),
    systeme_spouse_contact_id VARCHAR(50),
    crc_memo TEXT,

    -- Package generation
    package_status ENUM('processing', 'completed', 'failed') NULL,
    complete_package_pdf LONGBLOB,
    package_file_size INT,
    package_total_pages INT,
    package_completed_at TIMESTAMP NULL,
    package_error TEXT,

    -- Tracking information
    affiliate_code VARCHAR(50),
    ip_address VARCHAR(45),
    user_agent TEXT,
    device_type VARCHAR(50),
    referring_domain VARCHAR(255),

    -- Status
    current_step INT DEFAULT 1,
    status ENUM('in_progress', 'completed', 'abandoned', 'cancelled') DEFAULT 'in_progress',
    is_active BOOLEAN DEFAULT TRUE,

    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_session_id (session_id),
    INDEX idx_email (email),
    INDEX idx_phone (phone),
    INDEX idx_status (status),
    INDEX idx_affiliate (affiliate_code),
    INDEX idx_last_activity (last_activity),
    INDEX idx_package_id (package_id),
    INDEX idx_xactosign_package_id (xactosign_package_id),
    INDEX idx_package_status (package_status),
    INDEX idx_crc_record_id (crc_record_id),
    INDEX idx_systeme_contact (systeme_contact_id),
    INDEX idx_systeme_spouse (systeme_spouse_contact_id),
    FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Spouse enrollments table
CREATE TABLE IF NOT EXISTS spouse_enrollments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    primary_enrollment_id INT NOT NULL,
    session_id VARCHAR(9) UNIQUE NOT NULL,
    verification_method ENUM('email', 'phone', 'code') NULL,
    verified BOOLEAN DEFAULT FALSE,
    info_confirmed BOOLEAN DEFAULT FALSE,
    spouse_crc_record_id VARCHAR(50),
    spouse_xactosign_package_id VARCHAR(50),
    package_status ENUM('processing', 'completed', 'failed') NULL,
    complete_package_pdf LONGBLOB,
    package_file_size INT,
    package_total_pages INT,
    package_completed_at TIMESTAMP NULL,
    package_error TEXT,
    current_step INT DEFAULT 1,
    status ENUM('pending', 'in_progress', 'completed', 'abandoned') DEFAULT 'pending',
    ip_address VARCHAR(45),
    user_agent TEXT,
    device_type VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_session_id (session_id),
    INDEX idx_primary_enrollment_id (primary_enrollment_id),
    INDEX idx_status (status),
    INDEX idx_verified (verified),
    FOREIGN KEY (primary_enrollment_id) REFERENCES enrollment_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Enrollment steps tracking
CREATE TABLE IF NOT EXISTS enrollment_steps (
    id INT AUTO_INCREMENT PRIMARY KEY,
    enrollment_id INT NOT NULL,
    step_number INT NOT NULL,
    step_name VARCHAR(100) NOT NULL,
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    time_spent_seconds INT DEFAULT 0 COMMENT 'Total time spent on this step',
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    data JSON COMMENT 'Step-specific data storage',
    notifications_sent BOOLEAN DEFAULT FALSE COMMENT 'Whether staff was notified about delay',

    INDEX idx_enrollment (enrollment_id),
    INDEX idx_step (step_number),
    INDEX idx_last_activity (last_activity),
    FOREIGN KEY (enrollment_id) REFERENCES enrollment_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Contracts table
CREATE TABLE IF NOT EXISTS contracts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    enrollment_id INT NOT NULL,
    contract_type ENUM('croa', 'client_agreement', 'power_of_attorney', 'notice_of_cancellation') NOT NULL,
    contract_pdf LONGBLOB COMMENT 'Encrypted PDF file',
    contract_hash VARCHAR(64) COMMENT 'SHA-256 hash of original file',
    signed BOOLEAN DEFAULT FALSE,
    signature_data TEXT COMMENT 'Base64 encoded signature image',
    signed_at TIMESTAMP NULL,
    signed_by VARCHAR(255) COMMENT 'Name of person who signed',
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_spouse BOOLEAN DEFAULT FALSE,

    INDEX idx_enrollment (enrollment_id),
    INDEX idx_type (contract_type),
    INDEX idx_signed (signed),
    FOREIGN KEY (enrollment_id) REFERENCES enrollment_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- State contract documents table
CREATE TABLE IF NOT EXISTS state_contract_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    package_id INT NOT NULL,
    contract_type ENUM('croa_disclosure', 'client_agreement', 'power_of_attorney', 'notice_of_cancellation') NOT NULL,
    contract_pdf LONGBLOB NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_size INT NOT NULL,
    mime_type VARCHAR(100) DEFAULT 'application/pdf',
    pdf_hash VARCHAR(64) NOT NULL COMMENT 'SHA-256 hash for integrity verification',
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    uploaded_by INT NULL,
    signature_coords JSON COMMENT 'Optional: Custom signature placement coordinates',

    UNIQUE KEY unique_package_contract (package_id, contract_type),
    INDEX idx_package_type (package_id, contract_type),
    FOREIGN KEY (package_id) REFERENCES state_contract_packages(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES staff(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- State contract mappings table
CREATE TABLE IF NOT EXISTS state_contract_mappings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    package_id INT NOT NULL,
    state_code CHAR(2) NOT NULL COMMENT 'Two-letter state code (e.g., TX, CA)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT NULL,

    UNIQUE KEY unique_package_state (package_id, state_code),
    INDEX idx_state (state_code),
    INDEX idx_package (package_id),
    FOREIGN KEY (package_id) REFERENCES state_contract_packages(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES staff(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2FA codes table
CREATE TABLE IF NOT EXISTS `2fa_codes` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(10) NOT NULL,
    identifier VARCHAR(255) NOT NULL COMMENT 'Email or phone number',
    identifier_type ENUM('email', 'phone', 'session_id') NOT NULL,
    purpose ENUM('enrollment_resume', 'staff_login', 'verify_contact') NOT NULL,
    enrollment_id INT NULL,
    staff_id INT NULL,
    expires_at TIMESTAMP NOT NULL,
    verified BOOLEAN DEFAULT FALSE,
    verified_at TIMESTAMP NULL,
    ip_address VARCHAR(45),
    attempts INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_code (code),
    INDEX idx_identifier (identifier),
    INDEX idx_expires (expires_at),
    INDEX idx_verified (verified),
    FOREIGN KEY (enrollment_id) REFERENCES enrollment_users(id) ON DELETE CASCADE,
    FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Trusted browsers table for 2FA bypass
CREATE TABLE IF NOT EXISTS trusted_browsers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    staff_id INT NOT NULL,
    browser_token VARCHAR(64) UNIQUE NOT NULL COMMENT 'Unique browser identifier',
    ip_address VARCHAR(45),
    user_agent TEXT,
    expires_at TIMESTAMP NOT NULL,
    revoked BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_used TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_staff (staff_id),
    INDEX idx_token (browser_token),
    INDEX idx_expires (expires_at),
    INDEX idx_revoked (revoked),
    FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notes table
CREATE TABLE IF NOT EXISTS notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    enrollment_id INT NOT NULL,
    staff_id INT,
    note_type ENUM('general', 'system', 'cancellation', 'admin') DEFAULT 'general',
    note_text TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_enrollment (enrollment_id),
    INDEX idx_staff (staff_id),
    INDEX idx_type (note_type),
    FOREIGN KEY (enrollment_id) REFERENCES enrollment_users(id) ON DELETE CASCADE,
    FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ID documents table
CREATE TABLE IF NOT EXISTS id_docs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    enrollment_id INT NOT NULL,
    document_type ENUM('drivers_license', 'proof_of_address', 'ssn_card', 'spouse_dl', 'spouse_poa', 'spouse_ssn') NOT NULL,
    document_name VARCHAR(255),
    document_data LONGBLOB COMMENT 'Encrypted document file',
    document_hash VARCHAR(64) COMMENT 'SHA-256 hash of original file',
    mime_type VARCHAR(100),
    file_size INT,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45),

    INDEX idx_enrollment (enrollment_id),
    INDEX idx_type (document_type),
    FOREIGN KEY (enrollment_id) REFERENCES enrollment_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- API keys table
-- Supported integrations: recaptcha, voipms, mailersend, credit_repair_cloud, zoho_books, systeme_io, xactoauth, zapier
CREATE TABLE IF NOT EXISTS api_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    service_name VARCHAR(100) UNIQUE NOT NULL,
    api_key_encrypted BLOB,
    api_secret_encrypted BLOB,
    additional_config JSON COMMENT 'Service-specific configuration',
    is_enabled BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_service (service_name),
    INDEX idx_enabled (is_enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Settings table
CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    setting_type ENUM('string', 'boolean', 'integer', 'json') DEFAULT 'string',
    description TEXT,
    category VARCHAR(50) COMMENT 'Group settings by category',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT,

    INDEX idx_key (setting_key),
    INDEX idx_category (category),
    FOREIGN KEY (updated_by) REFERENCES staff(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Enrollment questions table
CREATE TABLE IF NOT EXISTS enrollment_questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    question_text TEXT NOT NULL,
    question_type ENUM('yes_no', 'multiple_choice', 'short_answer', 'long_answer') NOT NULL,
    options JSON COMMENT 'For multiple choice questions',
    is_required BOOLEAN DEFAULT FALSE,
    is_active BOOLEAN DEFAULT TRUE,
    display_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_active (is_active),
    INDEX idx_order (display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Enrollment question responses table
CREATE TABLE IF NOT EXISTS enrollment_question_responses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    enrollment_id INT NOT NULL,
    question_id INT NOT NULL,
    response_text TEXT,
    answered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_enrollment (enrollment_id),
    INDEX idx_question (question_id),
    FOREIGN KEY (enrollment_id) REFERENCES enrollment_users(id) ON DELETE CASCADE,
    FOREIGN KEY (question_id) REFERENCES enrollment_questions(id) ON DELETE CASCADE,
    UNIQUE KEY unique_enrollment_question (enrollment_id, question_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Communication templates table
CREATE TABLE IF NOT EXISTS communication_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_type ENUM('sms', 'email') NOT NULL,
    template_name VARCHAR(100) NOT NULL,
    template_category VARCHAR(50) NOT NULL COMMENT 'staff, affiliate, or client',
    subject VARCHAR(255) NULL COMMENT 'For email templates only',
    content TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT NULL,

    UNIQUE KEY unique_template (template_type, template_name),
    FOREIGN KEY (updated_by) REFERENCES staff(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SMS messages table
CREATE TABLE IF NOT EXISTS sms_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    direction ENUM('inbound', 'outbound') NOT NULL,
    from_number VARCHAR(20) NOT NULL,
    to_number VARCHAR(20) NOT NULL,
    message TEXT NOT NULL,
    status VARCHAR(50) DEFAULT 'received',
    voipms_id VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_from_number (from_number),
    INDEX idx_to_number (to_number),
    INDEX idx_direction (direction),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Test packages table
CREATE TABLE IF NOT EXISTS test_packages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    package_id INT NOT NULL COMMENT 'Reference to state_contract_packages',
    xactosign_package_id VARCHAR(255) NOT NULL COMMENT 'XACT-XXXXXXXXXXXX format',
    status ENUM('processing', 'completed', 'failed') DEFAULT 'processing',
    pdf_data LONGBLOB COMMENT 'Complete merged PDF with all documents',
    file_size INT NULL COMMENT 'Size of PDF in bytes',
    total_pages INT NULL COMMENT 'Total pages in merged PDF',
    error_message TEXT COMMENT 'Error message if failed',
    croa_signature_data TEXT COMMENT 'Base64 signature for CROA',
    agreement_signature_data TEXT COMMENT 'Base64 signature for Agreement',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT NULL COMMENT 'Staff member who generated',
    completed_at TIMESTAMP NULL COMMENT 'When processing completed',

    UNIQUE KEY xactosign_package_id (xactosign_package_id),
    INDEX idx_package_id (package_id),
    INDEX idx_xactosign_id (xactosign_package_id),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at),
    FOREIGN KEY (package_id) REFERENCES state_contract_packages(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES staff(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default plans (use INSERT IGNORE to avoid duplicates)
INSERT IGNORE INTO plans (plan_name, plan_type, initial_work_fee, monthly_fee, description, display_order, is_active) VALUES
('Individual Plan', 'individual', 99.00, 79.00, 'Complete credit repair service for one person', 1, TRUE),
('Couple Plan', 'couple', 149.00, 119.00, 'Complete credit repair service for couples', 2, TRUE);

-- Insert default settings (use INSERT IGNORE to avoid duplicates)
INSERT IGNORE INTO settings (setting_key, setting_value, setting_type, description, category) VALUES
('company_name', 'Your Company Name', 'string', 'Company name', 'general'),
('enrollments_enabled', 'true', 'boolean', 'Enable or disable new enrollments', 'enrollment'),
('enrollment_questions_enabled', 'false', 'boolean', 'Enable enrollment questions step', 'enrollment'),
('step_timeout_minutes', '5', 'integer', 'Minutes before staff notification on inactive step', 'notifications'),
('encryption_algorithm', 'AES-256-CBC', 'string', 'Encryption algorithm for sensitive data', 'security'),
('session_timeout_hours', '72', 'integer', 'Hours before enrollment session expires', 'enrollment');
