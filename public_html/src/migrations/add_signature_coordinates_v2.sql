-- Better approach: Store signature coordinates per document
-- This allows each contract type to have multiple signatures with different coordinates

-- First, check and drop columns from previous migration if they exist
SET @dbname = DATABASE();
SET @tablename = 'state_contract_packages';

-- This will not error if columns don't exist
SET @sql = (SELECT IF(
    COUNT(*) > 0,
    CONCAT('ALTER TABLE ', @tablename, ' ',
           'DROP COLUMN croa_client_sig_page, ',
           'DROP COLUMN croa_client_sig_x1, ',
           'DROP COLUMN croa_client_sig_y1, ',
           'DROP COLUMN croa_client_sig_x2, ',
           'DROP COLUMN croa_client_sig_y2, ',
           'DROP COLUMN agreement_client_sig_page, ',
           'DROP COLUMN agreement_client_sig_x1, ',
           'DROP COLUMN agreement_client_sig_y1, ',
           'DROP COLUMN agreement_client_sig_x2, ',
           'DROP COLUMN agreement_client_sig_y2, ',
           'DROP COLUMN agreement_counter_sig_page, ',
           'DROP COLUMN agreement_counter_sig_x1, ',
           'DROP COLUMN agreement_counter_sig_y1, ',
           'DROP COLUMN agreement_counter_sig_x2, ',
           'DROP COLUMN agreement_counter_sig_y2'),
    'SELECT 1') -- Do nothing if columns don't exist
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @dbname
    AND TABLE_NAME = @tablename
    AND COLUMN_NAME = 'croa_client_sig_page'));

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add signature coordinate configuration directly to the documents table
-- This way each document can have its own signature placement rules
ALTER TABLE state_contract_documents
ADD COLUMN signature_config JSON DEFAULT NULL COMMENT 'JSON array of signature placement configs';

-- Example JSON structure:
-- [
--   {
--     "signature_type": "client",
--     "label": "Client Signature",
--     "page": 2,
--     "x1": 84.0,
--     "y1": 145.0,
--     "x2": 327.0,
--     "y2": 167.0
--   },
--   {
--     "signature_type": "countersign",
--     "label": "Company Representative",
--     "page": "last",
--     "x1": 182.0,
--     "y1": 399.0,
--     "x2": 374.0,
--     "y2": 422.0
--   }
-- ]

-- Set default signature configs for existing documents
UPDATE state_contract_documents
SET signature_config = '[{"signature_type":"client","label":"Client Signature","page":2,"x1":84.0,"y1":145.0,"x2":327.0,"y2":167.0}]'
WHERE contract_type = 'croa_disclosure' AND signature_config IS NULL;

UPDATE state_contract_documents
SET signature_config = '[{"signature_type":"client","label":"Client Signature","page":"last","x1":96.0,"y1":457.0,"x2":283.0,"y2":480.0},{"signature_type":"countersign","label":"Company Representative","page":"last","x1":182.0,"y1":399.0,"x2":374.0,"y2":422.0}]'
WHERE contract_type = 'client_agreement' AND signature_config IS NULL;

UPDATE state_contract_documents
SET signature_config = '[]'
WHERE contract_type = 'notice_of_cancellation' AND signature_config IS NULL;
