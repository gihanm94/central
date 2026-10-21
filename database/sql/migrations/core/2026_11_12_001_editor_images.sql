-- Pictures put into the rich-text editor are files (public/uploads/editor/…); the text only holds their link.
-- editor_images: every upload; editor_image_uses: which record field shows it. A picture taken out of every
-- record, or never saved into one (form closed) within a day, is deleted with its file.
CREATE TABLE IF NOT EXISTS editor_images (
    file        VARCHAR(80) PRIMARY KEY,               -- editor/<24 hex>.<ext>, as Upload::image returned it
    created_by  BIGINT,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE TABLE IF NOT EXISTS editor_image_uses (
    file        VARCHAR(80) NOT NULL REFERENCES editor_images(file) ON DELETE CASCADE,
    ref         VARCHAR(160) NOT NULL,                 -- connection.table.id.field, e.g. crm.leads.42.description
    PRIMARY KEY (file, ref)
);
CREATE INDEX IF NOT EXISTS editor_image_uses_ref_idx ON editor_image_uses (ref);
