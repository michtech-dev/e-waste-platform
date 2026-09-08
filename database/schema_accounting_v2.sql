-- =====================================================================
-- MODULE COMPTABILITE V2 — BUDGETS & IMMOBILISATIONS (façon Sage i7)
-- PostgreSQL — s'applique à la base waste_platform existante
-- Exécuter (superuser) :
--   psql -U postgres -d waste_platform -f database/schema_accounting_v2.sql
-- =====================================================================

-- ============================================================
-- B1. BUDGETS PRÉVISIONNELS
-- ============================================================
CREATE TABLE IF NOT EXISTS acc_budgets (
    id         SERIAL PRIMARY KEY,
    period_id  INTEGER NOT NULL REFERENCES acc_periods(id) ON DELETE CASCADE,
    label      VARCHAR(150) NOT NULL,
    created_by INTEGER REFERENCES users(id),
    created_at TIMESTAMP DEFAULT NOW(),
    CONSTRAINT acc_budgets_uq UNIQUE (period_id, label)
);
CREATE INDEX IF NOT EXISTS idx_acc_budgets_period ON acc_budgets(period_id);

-- Une ligne budgétaire = un compte + 12 dotations mensuelles
CREATE TABLE IF NOT EXISTS acc_budget_lines (
    id         SERIAL PRIMARY KEY,
    budget_id  INTEGER NOT NULL REFERENCES acc_budgets(id) ON DELETE CASCADE,
    account_id INTEGER NOT NULL REFERENCES acc_accounts(id),
    m1  NUMERIC(14,2) NOT NULL DEFAULT 0,
    m2  NUMERIC(14,2) NOT NULL DEFAULT 0,
    m3  NUMERIC(14,2) NOT NULL DEFAULT 0,
    m4  NUMERIC(14,2) NOT NULL DEFAULT 0,
    m5  NUMERIC(14,2) NOT NULL DEFAULT 0,
    m6  NUMERIC(14,2) NOT NULL DEFAULT 0,
    m7  NUMERIC(14,2) NOT NULL DEFAULT 0,
    m8  NUMERIC(14,2) NOT NULL DEFAULT 0,
    m9  NUMERIC(14,2) NOT NULL DEFAULT 0,
    m10 NUMERIC(14,2) NOT NULL DEFAULT 0,
    m11 NUMERIC(14,2) NOT NULL DEFAULT 0,
    m12 NUMERIC(14,2) NOT NULL DEFAULT 0,
    CONSTRAINT acc_budget_lines_uq UNIQUE (budget_id, account_id)
);
CREATE INDEX IF NOT EXISTS idx_acc_blines_budget  ON acc_budget_lines(budget_id);
CREATE INDEX IF NOT EXISTS idx_acc_blines_account ON acc_budget_lines(account_id);

-- ============================================================
-- B2. IMMOBILISATIONS & AMORTISSEMENTS
-- ============================================================
CREATE TABLE IF NOT EXISTS acc_assets (
    id                SERIAL PRIMARY KEY,
    asset_number      VARCHAR(20) UNIQUE NOT NULL,          -- 'IMM-0001'
    label             VARCHAR(200) NOT NULL,
    account_id        INTEGER NOT NULL REFERENCES acc_accounts(id), -- compte 21x-27x
    third_party_id    INTEGER REFERENCES acc_third_parties(id),
    acquisition_date  DATE NOT NULL,
    acquisition_value NUMERIC(14,2) NOT NULL CHECK (acquisition_value > 0),
    method            VARCHAR(12) NOT NULL DEFAULT 'linear'
                      CHECK (method IN ('linear','degressive')),
    duration_years    SMALLINT NOT NULL CHECK (duration_years BETWEEN 1 AND 50),
    residual_value    NUMERIC(14,2) NOT NULL DEFAULT 0 CHECK (residual_value >= 0),
    status            VARCHAR(12) NOT NULL DEFAULT 'active'
                      CHECK (status IN ('active','disposed')),
    disposal_date     DATE,
    disposal_value    NUMERIC(14,2),
    disposal_entry_id INTEGER REFERENCES acc_entries(id),
    created_by        INTEGER REFERENCES users(id),
    created_at        TIMESTAMP DEFAULT NOW(),
    CONSTRAINT acc_assets_disposal_chk
        CHECK ((status = 'disposed') = (disposal_date IS NOT NULL))
);
CREATE INDEX IF NOT EXISTS idx_acc_assets_status ON acc_assets(status);

-- Amortissements comptabilisés par exercice (traçabilité + anti-doublon)
CREATE TABLE IF NOT EXISTS acc_asset_amortizations (
    id         SERIAL PRIMARY KEY,
    asset_id   INTEGER NOT NULL REFERENCES acc_assets(id) ON DELETE CASCADE,
    fiscal_year SMALLINT NOT NULL,
    amount     NUMERIC(14,2) NOT NULL CHECK (amount >= 0),
    entry_id   INTEGER REFERENCES acc_entries(id) ON DELETE SET NULL,
    created_at TIMESTAMP DEFAULT NOW(),
    CONSTRAINT acc_amort_uq UNIQUE (asset_id, fiscal_year)
);
CREATE INDEX IF NOT EXISTS idx_acc_amort_asset ON acc_asset_amortizations(asset_id);

-- ============================================================
-- SEED : comptes complémentaires (dotations / cessions d'immobilisations)
-- ============================================================
INSERT INTO acc_accounts (number, label, class, type, parent_number, is_header, is_active) VALUES
    ('462000','Créances sur cessions d''immobilisations',4,'general',NULL,FALSE,TRUE),
    ('675000','Valeurs comptables des immobilisations cédées',6,'general',NULL,FALSE,TRUE),
    ('775000','Produits des cessions d''éléments d''actif',7,'general',NULL,FALSE,TRUE),
    ('681120','Dotations aux amortissements des immobilisations corporelles',6,'general',NULL,FALSE,TRUE)
ON CONFLICT (number) DO NOTHING;
