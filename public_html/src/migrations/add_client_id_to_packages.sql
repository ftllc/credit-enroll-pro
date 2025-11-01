-- ============================================================================
-- Add XactoSign Client ID to State Contract Packages
-- ============================================================================

ALTER TABLE state_contract_packages
ADD COLUMN xactosign_client_id VARCHAR(50) NULL COMMENT 'Client ID for XactoSign certificate generation' AFTER package_name;
