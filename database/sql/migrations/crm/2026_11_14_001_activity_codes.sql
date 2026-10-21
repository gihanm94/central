-- Activities saved from the form had no code (the save hook skipped CrmController's); give them the usual one.
UPDATE activities SET code = 'AC-' || lpad(id::text, 5, '0') WHERE (code IS NULL OR code = '') AND deleted_at IS NULL;
