-- Account report: one amount per account, department, month and type — filled mainly by import
-- (Accounting → Management → Account report) and summed on Management → Account report.
-- The same account / department / year / month / income-expense / type is kept once:
-- importing it again skips the line.
CREATE TABLE IF NOT EXISTS account_reports (
    id              BIGSERIAL PRIMARY KEY,
    account         VARCHAR(60)  NOT NULL,
    name            VARCHAR(200) NOT NULL,
    amount          NUMERIC(16,2) NOT NULL DEFAULT 0,
    department      VARCHAR(120) NOT NULL,
    kind            VARCHAR(10)  NOT NULL CHECK (kind IN ('income', 'expense')),
    year            SMALLINT     NOT NULL CHECK (year BETWEEN 2000 AND 2100),
    month           SMALLINT     NOT NULL CHECK (month BETWEEN 1 AND 12),
    type            VARCHAR(30)  NOT NULL,
    created_by      BIGINT,
    created_by_name VARCHAR(190),
    updated_by      BIGINT,
    updated_by_name VARCHAR(190),
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT now(),
    deleted_at      TIMESTAMPTZ
);
CREATE UNIQUE INDEX IF NOT EXISTS account_reports_line ON account_reports (account, department, year, month, kind, type) WHERE deleted_at IS NULL;
CREATE INDEX IF NOT EXISTS account_reports_year ON account_reports (year, department) WHERE deleted_at IS NULL;
