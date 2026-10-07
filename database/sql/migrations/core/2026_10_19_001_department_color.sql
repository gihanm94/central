-- A colour per department (control room charts, department chips). NULL = a colour from the standard palette.
ALTER TABLE departments ADD COLUMN IF NOT EXISTS color VARCHAR(9);
UPDATE departments SET color = (ARRAY['#2563eb','#059669','#d97706','#7c3aed','#db2777','#0891b2','#65a30d','#ea580c','#475569','#be123c'])[1 + ((id - 1) % 10)] WHERE color IS NULL;
