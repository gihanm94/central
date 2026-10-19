-- Sign in with a username (plus password). Every account gets one: when none is given, it is made from the e-mail
-- address (the part before @, lower case, only a–z 0–9 . _ -), with a number added if that name is taken.
ALTER TABLE users ADD COLUMN IF NOT EXISTS username VARCHAR(60);

CREATE OR REPLACE FUNCTION users_default_username() RETURNS trigger AS $$
DECLARE base TEXT; cand TEXT; n INT := 1;
BEGIN
    IF NEW.username IS NULL OR btrim(NEW.username) = '' THEN
        base := lower(regexp_replace(split_part(coalesce(NEW.email, ''), '@', 1), '[^a-zA-Z0-9._-]', '', 'g'));
        IF base = '' THEN base := 'user'; END IF;
        base := left(base, 50);
        cand := base;
        WHILE EXISTS (SELECT 1 FROM users u WHERE lower(u.username) = cand AND u.id IS DISTINCT FROM NEW.id) LOOP
            n := n + 1;
            cand := base || n;
        END LOOP;
        NEW.username := cand;
    ELSE
        NEW.username := lower(btrim(NEW.username));
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS users_username ON users;
CREATE TRIGGER users_username BEFORE INSERT OR UPDATE OF username ON users FOR EACH ROW EXECUTE FUNCTION users_default_username();

-- Existing accounts, oldest first (so the first person keeps the plain name)
DO $$ DECLARE r RECORD; BEGIN
    FOR r IN SELECT id FROM users WHERE username IS NULL ORDER BY id LOOP
        UPDATE users SET username = NULL WHERE id = r.id;
    END LOOP;
END $$;

CREATE UNIQUE INDEX IF NOT EXISTS users_username_unique ON users (lower(username));
