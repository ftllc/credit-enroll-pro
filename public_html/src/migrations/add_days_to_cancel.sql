-- Add days_to_cancel field to state_contract_packages table
ALTER TABLE state_contract_packages
ADD COLUMN days_to_cancel INT DEFAULT 5
COMMENT 'Number of days allowed for contract cancellation' AFTER is_default;

-- Update existing packages to have default value
UPDATE state_contract_packages SET days_to_cancel = 5 WHERE days_to_cancel IS NULL;
