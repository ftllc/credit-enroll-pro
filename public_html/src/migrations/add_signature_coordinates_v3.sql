-- Add signature coordinate configuration directly to the documents table
-- This way each document can have its own signature placement rules
ALTER TABLE state_contract_documents
ADD COLUMN IF NOT EXISTS signature_config JSON DEFAULT NULL COMMENT 'JSON array of signature placement configs';

-- Set default signature configs for existing documents
UPDATE state_contract_documents
SET signature_config = '[{"signature_type":"client","label":"Client Signature","page":2,"x1":84.0,"y1":145.0,"x2":327.0,"y2":167.0}]'
WHERE contract_type = 'croa_disclosure' AND (signature_config IS NULL OR signature_config = 'null');

UPDATE state_contract_documents
SET signature_config = '[{"signature_type":"client","label":"Client Signature","page":"last","x1":96.0,"y1":457.0,"x2":283.0,"y2":480.0},{"signature_type":"countersign","label":"Company Representative","page":"last","x1":182.0,"y1":399.0,"x2":374.0,"y2":422.0}]'
WHERE contract_type = 'client_agreement' AND (signature_config IS NULL OR signature_config = 'null');

UPDATE state_contract_documents
SET signature_config = '[]'
WHERE contract_type = 'notice_of_cancellation' AND (signature_config IS NULL OR signature_config = 'null');
