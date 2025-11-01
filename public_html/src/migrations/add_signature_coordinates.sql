-- Add signature coordinate configuration to state_contract_packages table
-- This allows admin to configure signature placement without editing code

ALTER TABLE state_contract_packages
ADD COLUMN croa_client_sig_page INT DEFAULT 2 COMMENT 'CROA client signature page number',
ADD COLUMN croa_client_sig_x1 DECIMAL(10,2) DEFAULT 84.0 COMMENT 'CROA client signature top-left X',
ADD COLUMN croa_client_sig_y1 DECIMAL(10,2) DEFAULT 145.0 COMMENT 'CROA client signature top-left Y',
ADD COLUMN croa_client_sig_x2 DECIMAL(10,2) DEFAULT 327.0 COMMENT 'CROA client signature bottom-right X',
ADD COLUMN croa_client_sig_y2 DECIMAL(10,2) DEFAULT 167.0 COMMENT 'CROA client signature bottom-right Y',

ADD COLUMN agreement_client_sig_page VARCHAR(10) DEFAULT 'last' COMMENT 'Client agreement client signature page (number or "last")',
ADD COLUMN agreement_client_sig_x1 DECIMAL(10,2) DEFAULT 96.0 COMMENT 'Client agreement client signature top-left X',
ADD COLUMN agreement_client_sig_y1 DECIMAL(10,2) DEFAULT 457.0 COMMENT 'Client agreement client signature top-left Y',
ADD COLUMN agreement_client_sig_x2 DECIMAL(10,2) DEFAULT 283.0 COMMENT 'Client agreement client signature bottom-right X',
ADD COLUMN agreement_client_sig_y2 DECIMAL(10,2) DEFAULT 480.0 COMMENT 'Client agreement client signature bottom-right Y',

ADD COLUMN agreement_counter_sig_page VARCHAR(10) DEFAULT 'last' COMMENT 'Client agreement counter signature page (number or "last")',
ADD COLUMN agreement_counter_sig_x1 DECIMAL(10,2) DEFAULT 182.0 COMMENT 'Client agreement counter signature top-left X',
ADD COLUMN agreement_counter_sig_y1 DECIMAL(10,2) DEFAULT 399.0 COMMENT 'Client agreement counter signature top-left Y',
ADD COLUMN agreement_counter_sig_x2 DECIMAL(10,2) DEFAULT 374.0 COMMENT 'Client agreement counter signature bottom-right X',
ADD COLUMN agreement_counter_sig_y2 DECIMAL(10,2) DEFAULT 422.0 COMMENT 'Client agreement counter signature bottom-right Y';
