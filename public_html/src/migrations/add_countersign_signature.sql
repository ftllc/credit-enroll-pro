-- ============================================================================
-- Add Countersign Signature to State Contract Packages
-- ============================================================================

-- Add countersign_signature column to store the company's signature
ALTER TABLE state_contract_packages
ADD COLUMN countersign_signature MEDIUMBLOB NULL COMMENT 'Company countersignature image (PNG format)',
ADD COLUMN countersign_filename VARCHAR(255) NULL COMMENT 'Original filename of countersignature',
ADD COLUMN countersign_uploaded_at TIMESTAMP NULL COMMENT 'When countersignature was uploaded',
ADD COLUMN countersign_uploaded_by INT NULL COMMENT 'Staff member who uploaded the countersignature',
ADD FOREIGN KEY (countersign_uploaded_by) REFERENCES staff(id) ON DELETE SET NULL;
