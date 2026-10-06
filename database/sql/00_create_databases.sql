-- Run ONCE as a PostgreSQL superuser (e.g. `psql postgres`) if the installer
-- says the user "acme" cannot create databases. install.php does the rest.

DO $$
BEGIN
   IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'acme') THEN
      CREATE ROLE acme LOGIN PASSWORD 'acmeinter123' CREATEDB;
   ELSE
      ALTER ROLE acme WITH LOGIN PASSWORD 'acmeinter123' CREATEDB;
   END IF;
END $$;

-- Optional: create the databases yourself instead of letting install.php do it.
-- CREATE DATABASE user_db      OWNER acme;
-- CREATE DATABASE crm_db       OWNER acme;
-- CREATE DATABASE account_db   OWNER acme;
-- CREATE DATABASE inventory_db OWNER acme;
-- CREATE DATABASE machine_db   OWNER acme;
-- CREATE DATABASE hr_db        OWNER acme;
