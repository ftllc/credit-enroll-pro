-- Migration: Add trusted_browsers table for "Save Browser for 7 Days" feature
-- Created: 2025-01-14

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
