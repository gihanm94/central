-- A short stage name for the mobile app (small screens); empty = the normal name.
ALTER TABLE crm_stages ADD COLUMN IF NOT EXISTS mobile_name VARCHAR(24);
UPDATE crm_stages SET mobile_name = v.name FROM (VALUES
  ('QUALIFICATION', 'Qualify'), ('SURVEY_PROPOSAL', 'Proposal'), ('EVALUATION_TESTING', 'Testing'), ('NEGOTIATION', 'Negotiation'),
  ('CLOSED_WON', 'Won'), ('CLOSED_LOST', 'Lost'), ('ON_HOLD', 'On hold'), ('CANCEL', 'Cancelled')
) AS v(code, name) WHERE crm_stages.code = v.code AND crm_stages.mobile_name IS NULL;
