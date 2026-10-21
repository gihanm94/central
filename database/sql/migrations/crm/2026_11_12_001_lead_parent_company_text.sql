-- Parent company is typed in the lead form. Leads already linked to a parent get its name;
-- company_id stays the link when the typed name is one of the leads.
ALTER TABLE leads ADD COLUMN IF NOT EXISTS parent_company VARCHAR(200);
UPDATE leads l SET parent_company = p.name_en FROM leads p WHERE p.id = l.company_id AND l.parent_company IS NULL;
