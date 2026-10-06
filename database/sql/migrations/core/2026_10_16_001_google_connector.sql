-- A person's own Google account (Gmail + Calendar). Tokens are encrypted with the application key.
CREATE TABLE IF NOT EXISTS google_connections (
    user_id        BIGINT PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    google_email   VARCHAR(190),
    access_token   TEXT,
    refresh_token  TEXT,
    expires_at     TIMESTAMPTZ,
    scopes         TEXT,
    last_error     VARCHAR(255),
    connected_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at     TIMESTAMPTZ NOT NULL DEFAULT now()
);
