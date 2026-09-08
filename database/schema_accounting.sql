-- =====================================================================
-- MODULE COMPTABILITE (façon Sage Comptabilité i7)
-- PostgreSQL — s'applique à la base waste_platform existante
-- Exécuter : psql -d waste_platform -f database/schema_accounting.sql
-- =====================================================================

-- ============================================================
-- T1. EXERCICES COMPTABLES
-- ============================================================
CREATE TABLE IF NOT EXISTS acc_periods (
    id          SERIAL PRIMARY KEY,
    code        VARCHAR(9)  UNIQUE NOT NULL,          -- ex '2026'
    label       VARCHAR(80) NOT NULL,
    start_date  DATE NOT NULL,
    end_date    DATE NOT NULL,
    status      VARCHAR(10) NOT NULL DEFAULT 'open', -- open / closed
    created_at  TIMESTAMP DEFAULT NOW(),
    CONSTRAINT acc_periods_dates CHECK (end_date > start_date)
);

-- ============================================================
-- T2. PLAN COMPTABLE (PCG classes 1 à 7 imputables + 8/9 analytiques)
-- ============================================================
CREATE TABLE IF NOT EXISTS acc_accounts (
    id            SERIAL PRIMARY KEY,
    number        VARCHAR(20) UNIQUE NOT NULL,        -- '411000'
    label         VARCHAR(160) NOT NULL,
    class         SMALLINT NOT NULL CHECK (class BETWEEN 1 AND 9),
    type          VARCHAR(16) NOT NULL DEFAULT 'general',
                  -- general/client/supplier/bank/cash/other
    parent_number VARCHAR(20),                        -- compte rubrique parent
    is_header     BOOLEAN NOT NULL DEFAULT FALSE,     -- rubrique non imputable
    is_active     BOOLEAN NOT NULL DEFAULT TRUE,
    created_at    TIMESTAMP DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_acc_accounts_class   ON acc_accounts(class);
CREATE INDEX IF NOT EXISTS idx_acc_accounts_number  ON acc_accounts(number);

-- ============================================================
-- T3. JOURNAUX (ACH, VTE, BQ, CS, OD ...)
-- ============================================================
CREATE TABLE IF NOT EXISTS acc_journals (
    id                     SERIAL PRIMARY KEY,
    code                   VARCHAR(5)  UNIQUE NOT NULL, -- 'VTE'
    label                  VARCHAR(90) NOT NULL,
    type                   VARCHAR(12) NOT NULL DEFAULT 'divers',
                           -- ventes/achats/banque/caisse/divers
    default_debit_account  INTEGER REFERENCES acc_accounts(id),
    default_credit_account INTEGER REFERENCES acc_accounts(id),
    is_active              BOOLEAN NOT NULL DEFAULT TRUE,
    created_at             TIMESTAMP DEFAULT NOW(),
    CONSTRAINT acc_journals_type_chk CHECK (
        type IN ('ventes','achats','banque','caisse','divers'))
);

-- ============================================================
-- T4. TIERS (clients / fournisseurs)
-- ============================================================
CREATE TABLE IF NOT EXISTS acc_third_parties (
    id               SERIAL PRIMARY KEY,
    code             VARCHAR(20) UNIQUE NOT NULL,      -- 'C0001' / 'F0001'
    name             VARCHAR(160) NOT NULL,
    type             VARCHAR(10) NOT NULL DEFAULT 'client',
                     -- client / supplier / both
    account_id       INTEGER REFERENCES acc_accounts(id), -- compte collectif 411/401
    vat_number       VARCHAR(30),
    phone            VARCHAR(25),
    email            VARCHAR(150),
    address          TEXT,
    city             VARCHAR(80),
    country          VARCHAR(60) DEFAULT 'Burundi',
    payment_terms    SMALLINT NOT NULL DEFAULT 30,     -- délai de règlement en jours
    is_active        BOOLEAN NOT NULL DEFAULT TRUE,
    created_at       TIMESTAMP DEFAULT NOW(),
    CONSTRAINT acc_tp_type_chk CHECK (type IN ('client','supplier','both'))
);

-- ============================================================
-- T5. ECRITURES (en-tête de pièce)
-- ============================================================
CREATE TABLE IF NOT EXISTS acc_entries (
    id              SERIAL PRIMARY KEY,
    period_id       INTEGER NOT NULL REFERENCES acc_periods(id),
    journal_id      INTEGER NOT NULL REFERENCES acc_journals(id),
    entry_number    VARCHAR(30) NOT NULL,              -- n° de pièce
    entry_date      DATE NOT NULL,
    reference       VARCHAR(60),
    third_party_id  INTEGER REFERENCES acc_third_parties(id),
    label           VARCHAR(200) NOT NULL,
    status          VARCHAR(10) NOT NULL DEFAULT 'draft', -- draft/validated
    created_by      INTEGER REFERENCES users(id),
    created_at      TIMESTAMP DEFAULT NOW(),
    updated_at      TIMESTAMP DEFAULT NOW(),
    CONSTRAINT acc_entries_uq UNIQUE (period_id, journal_id, entry_number),
    CONSTRAINT acc_entries_status_chk CHECK (status IN ('draft','validated'))
);
CREATE INDEX IF NOT EXISTS idx_acc_entries_date   ON acc_entries(entry_date);
CREATE INDEX IF NOT EXISTS idx_acc_entries_period ON acc_entries(period_id);
CREATE INDEX IF NOT EXISTS idx_acc_entries_journal ON acc_entries(journal_id);

-- ============================================================
-- T6. LIGNES D'ECRITURE (partie double obligatoire)
-- ============================================================
CREATE TABLE IF NOT EXISTS acc_entry_lines (
    id             SERIAL PRIMARY KEY,
    entry_id       INTEGER NOT NULL REFERENCES acc_entries(id) ON DELETE CASCADE,
    line_no        SMALLINT NOT NULL DEFAULT 1,
    account_id     INTEGER NOT NULL REFERENCES acc_accounts(id),
    third_party_id INTEGER REFERENCES acc_third_parties(id),
    label          VARCHAR(200),
    debit          NUMERIC(14,2) NOT NULL DEFAULT 0 CHECK (debit  >= 0),
    credit         NUMERIC(14,2) NOT NULL DEFAULT 0 CHECK (credit >= 0),
    due_date       DATE,
    lettering      VARCHAR(12),                         -- code de lettrage
    lettered_at    DATE,
    CONSTRAINT acc_lines_one_side CHECK ((debit = 0 OR credit = 0)
                                     AND (debit > 0 OR credit > 0)),
    CONSTRAINT acc_lines_uq UNIQUE (entry_id, line_no)
);
CREATE INDEX IF NOT EXISTS idx_acc_lines_entry   ON acc_entry_lines(entry_id);
CREATE INDEX IF NOT EXISTS idx_acc_lines_account ON acc_entry_lines(account_id);
CREATE INDEX IF NOT EXISTS idx_acc_lines_letter  ON acc_entry_lines(lettering);

-- ============================================================
-- SEED : exercice courant (année système)
-- ============================================================
INSERT INTO acc_periods (code, label, start_date, end_date, status)
VALUES (
    EXTRACT(YEAR FROM CURRENT_DATE)::TEXT,
    'Exercice ' || EXTRACT(YEAR FROM CURRENT_DATE)::TEXT,
    make_date(EXTRACT(YEAR FROM CURRENT_DATE)::INT, 1, 1),
    make_date(EXTRACT(YEAR FROM CURRENT_DATE)::INT, 12, 31),
    'open'
) ON CONFLICT (code) DO NOTHING;

-- ============================================================
-- SEED : journaux par défaut (comme Sage i7)
-- ============================================================
INSERT INTO acc_journals (code, label, type) VALUES
    ('ACH', 'Journal des achats',        'achats'),
    ('VTE', 'Journal des ventes',        'ventes'),
    ('BQ',  'Journal de banque',         'banque'),
    ('CS',  'Journal de caisse',         'caisse'),
    ('OD',  'Opérations diverses',       'divers')
ON CONFLICT (code) DO NOTHING;

-- ============================================================
-- SEED : plan comptable général (extraits PCG essentiels)
-- ============================================================
INSERT INTO acc_accounts (number, label, class, type, parent_number, is_header, is_active) VALUES
-- Classe 1 : comptes de capitaux
('101000','Capital social',1,'general',NULL,FALSE,TRUE),
('104000','Primes liées au capital social',1,'general',NULL,FALSE,TRUE),
('106000','Réserves',1,'general',NULL,TRUE,FALSE),
('106100','Réserve légale',1,'general','106000',FALSE,TRUE),
('110000','Report à nouveau créditeur',1,'general',NULL,FALSE,TRUE),
('119000','Report à nouveau débiteur',1,'general',NULL,FALSE,TRUE),
('120000','Résultat net de l''exercice (bénéfice)',1,'general',NULL,FALSE,TRUE),
('129000','Résultat net de l''exercice (perte)',1,'general',NULL,FALSE,TRUE),
('130000','Résultat en instance d''affectation',1,'general',NULL,FALSE,TRUE),
('164000','Emprunts auprès des établissements de crédit',1,'general',NULL,FALSE,TRUE),
('168800','Intérêts courus sur emprunts',1,'general',NULL,FALSE,TRUE),
-- Classe 2 : immobilisations
('201000','Frais d''établissement',2,'general',NULL,FALSE,TRUE),
('210000','Investissements sur immobilisations non terminées',2,'general',NULL,FALSE,TRUE),
('211000','Terrains',2,'general',NULL,FALSE,TRUE),
('212000','Constructions',2,'general',NULL,FALSE,TRUE),
('213000','Constructions sur terrains appartenant à l''entité',2,'general','212000',FALSE,TRUE),
('215000','Installations techniques, matériel et outillage',2,'general',NULL,FALSE,TRUE),
('218000','Autres immobilisations corporelles',2,'general',NULL,FALSE,TRUE),
('218100','Installations générales, agencements, aménagements',2,'general','218000',FALSE,TRUE),
('218200','Matériel de transport',2,'general','218000',FALSE,TRUE),
('218300','Matériel de bureau et informatique',2,'general','218000',FALSE,TRUE),
('218400','Mobilier',2,'general','218000',FALSE,TRUE),
('230000','Immobilisations en cours',2,'general',NULL,FALSE,TRUE),
('280000','Amortissements des immobilisations',2,'general',NULL,TRUE,FALSE),
('281800','Amortissements des autres immobilisations corporelles',2,'general','280000',FALSE,TRUE),
('290000','Dépréciations des immobilisations',2,'general',NULL,FALSE,TRUE),
-- Classe 3 : stocks
('301000','Matières premières',3,'general',NULL,FALSE,TRUE),
('310000','En-cours de production de biens',3,'general',NULL,FALSE,TRUE),
('355000','Produits finis',3,'general',NULL,FALSE,TRUE),
('370000','Stocks de marchandises',3,'general',NULL,FALSE,TRUE),
('397000','Dépréciations des stocks de marchandises',3,'general',NULL,FALSE,TRUE),
-- Classe 4 : tiers
('400000','Fournisseurs et comptes rattachés',4,'supplier',NULL,TRUE,FALSE),
('401000','Fournisseurs',4,'supplier','400000',FALSE,TRUE),
('408000','Fournisseurs - factures non parvenues',4,'supplier','400000',FALSE,TRUE),
('409000','Fournisseurs débiteurs',4,'supplier','400000',FALSE,TRUE),
('410000','Clients et comptes rattachés',4,'client',NULL,TRUE,FALSE),
('411000','Clients',4,'client','410000',FALSE,TRUE),
('413000','Clients - effets à recevoir',4,'client','410000',FALSE,TRUE),
('418000','Clients - produits non encore facturés',4,'client','410000',FALSE,TRUE),
('421000','Personnel - rémunérations dues',4,'general',NULL,FALSE,TRUE),
('424000','Personnel - avances et acomptes',4,'general',NULL,FALSE,TRUE),
('431000','Sécurité sociale',4,'general',NULL,FALSE,TRUE),
('437000','Autres organismes sociaux',4,'general',NULL,FALSE,TRUE),
('438000','Organismes sociaux - charges à payer et produits à recevoir',4,'general',NULL,FALSE,TRUE),
('442000','État - impôts et taxes recouvrables sur des tiers',4,'general',NULL,FALSE,TRUE),
('443000','État - opérations particulières',4,'general',NULL,FALSE,TRUE),
('445000','État - taxes sur le chiffre d''affaires',4,'general',NULL,TRUE,FALSE),
('445510','TVA collectée',4,'general','445000',FALSE,TRUE),
('445660','TVA déductible sur autres biens et services',4,'general','445000',FALSE,TRUE),
('445710','TVA à décaisser',4,'general','445000',FALSE,TRUE),
('455100','Associés - comptes courants',4,'general',NULL,FALSE,TRUE),
('467000','Autres comptes débiteurs ou créditeurs',4,'general',NULL,FALSE,TRUE),
('471000','Comptes d''attente',4,'other',NULL,FALSE,TRUE),
('472000','Comptes de régularisation actif',4,'general',NULL,FALSE,TRUE),
('477000','Comptes transitoires ou d''attente - passif',4,'general',NULL,FALSE,TRUE),
('491000','Dépréciations des comptes de clients',4,'general',NULL,FALSE,TRUE),
-- Classe 5 : financiers
('512000','Banques',5,'bank',NULL,TRUE,FALSE),
('512100','Banque principale (BANCOBU...)',5,'bank','512000',FALSE,TRUE),
('512200','Banque secondaire (BANQUE DE CRÉDIT...)',5,'bank','512000',FALSE,TRUE),
('514000','Chèques postaux',5,'bank',NULL,FALSE,TRUE),
('515000','« Dépôts » de chèques remis à l''encaissement',5,'bank',NULL,FALSE,TRUE),
('530000','Caisse',5,'cash',NULL,FALSE,TRUE),
('532000','Caisse siège social',5,'cash',NULL,FALSE,TRUE),
('582000','Virements internes',5,'other',NULL,FALSE,TRUE),
('590000','Dépréciations des comptes financiers',5,'general',NULL,FALSE,TRUE),
-- Classe 6 : charges
('601000','Achats stockés - matières premières',6,'general',NULL,FALSE,TRUE),
('602000','Achats stockés - autres approvisionnements',6,'general',NULL,FALSE,TRUE),
('607000','Achats de marchandises',6,'general',NULL,FALSE,TRUE),
('608000','Frais accessoires incorporés aux achats',6,'general',NULL,FALSE,TRUE),
('609000','Rabais, remises et ristournes obtenus sur achats',6,'general',NULL,FALSE,TRUE),
('613000','Locations',6,'general',NULL,FALSE,TRUE),
('614000','Charges locatives et de copropriété',6,'general',NULL,FALSE,TRUE),
('615000','Entretien et réparations',6,'general',NULL,FALSE,TRUE),
('616000','Primes d''assurance',6,'general',NULL,FALSE,TRUE),
('622000','Rémunérations d''intermédiaires et honoraires',6,'general',NULL,FALSE,TRUE),
('623000','Publicité, publications, relations publiques',6,'general',NULL,FALSE,TRUE),
('626000','Frais postaux et télécommunications',6,'general',NULL,FALSE,TRUE),
('627000','Services bancaires',6,'general',NULL,FALSE,TRUE),
('628000','Divers',6,'general',NULL,FALSE,TRUE),
('631000','Impôts directs sur les résultats',6,'general',NULL,FALSE,TRUE),
('635000','Autres impôts, taxes et versements assimilés',6,'general',NULL,FALSE,TRUE),
('641000','Rémunérations du personnel',6,'general',NULL,FALSE,TRUE),
('645000','Charges de sécurité sociale et de prévoyance',6,'general',NULL,FALSE,TRUE),
('661000','Charges d''intérêt',6,'general',NULL,FALSE,TRUE),
('664000','Pertes sur créances irrécouvrables',6,'general',NULL,FALSE,TRUE),
('667000','Charges exceptionnelles diverses',6,'general',NULL,FALSE,TRUE),
('681000','Dotations aux amortissements',6,'general',NULL,FALSE,TRUE),
('687000','Dotations aux dépréciations exceptionnelles',6,'general',NULL,FALSE,TRUE),
-- Classe 7 : produits
('701000','Ventes de produits finis',7,'general',NULL,FALSE,TRUE),
('706000','Prestations de services',7,'general',NULL,FALSE,TRUE),
('707000','Ventes de marchandises',7,'general',NULL,FALSE,TRUE),
('708500','Ports et frais accessoires facturés',7,'general',NULL,FALSE,TRUE),
('709000','Rabais, remises et ristournes accordés',7,'general',NULL,FALSE,TRUE),
('752000','Revenus des immeubles non affectés à des activités professionnelles',7,'general',NULL,FALSE,TRUE),
('761000','Produits de participations',7,'general',NULL,FALSE,TRUE),
('765000','Escomptes obtenus',7,'general',NULL,FALSE,TRUE),
('766000','Gains de change',7,'general',NULL,FALSE,TRUE),
('771000','Produits exceptionnels sur opérations de gestion',7,'general',NULL,FALSE,TRUE),
('781000','Reprises sur amortissements et provisions',7,'general',NULL,FALSE,TRUE)
ON CONFLICT (number) DO NOTHING;
