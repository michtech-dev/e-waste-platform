-- ============================================
-- SCHEMA : Plateforme de signalement des déchets
-- Base de données : PostgreSQL
-- ============================================
-- Donne tous les droits à waste_app sur toutes les tables existantes
GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO waste_app;

-- Idem pour les séquences (nécessaire pour les colonnes SERIAL/auto-incrémentées)
GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public TO waste_app;

-- Pour que les FUTURES tables créées par postgres héritent aussi de ces droits
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON TABLES TO waste_app;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON SEQUENCES TO waste_app;



CREATE EXTENSION IF NOT EXISTS pgcrypto;

-- ============================================
-- 1. UTILISATEURS (citoyens + agents des autorités)
-- ============================================
CREATE TABLE users (
    id              SERIAL PRIMARY KEY,
    full_name       VARCHAR(150),
    phone           VARCHAR(20) UNIQUE NOT NULL,
    email           VARCHAR(150) UNIQUE,
    password_hash   TEXT NOT NULL,
    role            VARCHAR(20) NOT NULL DEFAULT 'citizen', -- 'citizen' / 'authority_agent' / 'admin'
    authority_id    INTEGER,                     -- rempli si role = authority_agent
    is_anonymous    BOOLEAN DEFAULT FALSE,
    points          INTEGER DEFAULT 0,
    status          VARCHAR(20) DEFAULT 'active', -- active / suspended
    created_at      TIMESTAMP DEFAULT NOW()
);

-- ============================================
-- 2. AUTORITÉS / SERVICES DESTINATAIRES
-- ============================================
CREATE TABLE authorities (
    id              SERIAL PRIMARY KEY,
    name            VARCHAR(150) NOT NULL,
    type            VARCHAR(50) NOT NULL,        -- 'municipal' / 'police' / 'ong'
    zone_covered    VARCHAR(150),
    contact_email   VARCHAR(150),
    contact_phone   VARCHAR(20)
);

ALTER TABLE users ADD CONSTRAINT fk_users_authority
    FOREIGN KEY (authority_id) REFERENCES authorities(id);

-- ============================================
-- 3. TOKENS DE SESSION (authentification API)
-- ============================================
CREATE TABLE auth_tokens (
    id              SERIAL PRIMARY KEY,
    user_id         INTEGER REFERENCES users(id) ON DELETE CASCADE,
    token           VARCHAR(128) UNIQUE NOT NULL,
    device_info     VARCHAR(200),
    expires_at      TIMESTAMP NOT NULL,
    created_at      TIMESTAMP DEFAULT NOW()
);
CREATE INDEX idx_auth_tokens_token ON auth_tokens(token);

-- ============================================
-- 4. SIGNALEMENTS
-- ============================================
CREATE TABLE reports (
    id              SERIAL PRIMARY KEY,
    user_id         INTEGER REFERENCES users(id) ON DELETE SET NULL,
    authority_id    INTEGER REFERENCES authorities(id),
    category        VARCHAR(40) NOT NULL DEFAULT 'decharge_sauvage',
    description     TEXT,
    latitude        DOUBLE PRECISION NOT NULL,
    longitude       DOUBLE PRECISION NOT NULL,
    severity        VARCHAR(20) DEFAULT 'medium',
    status          VARCHAR(20) DEFAULT 'pending', -- pending/in_progress/resolved/rejected
    is_anonymous    BOOLEAN DEFAULT FALSE,
    created_at      TIMESTAMP DEFAULT NOW(),
    resolved_at     TIMESTAMP
);
CREATE INDEX idx_reports_status ON reports(status);
CREATE INDEX idx_reports_category ON reports(category);
CREATE INDEX idx_reports_location ON reports(latitude, longitude);
CREATE INDEX idx_reports_user ON reports(user_id);
CREATE INDEX idx_reports_authority ON reports(authority_id);

-- ============================================
-- 5. PREUVES (photos/vidéos)
-- ============================================
CREATE TABLE report_media (
    id              SERIAL PRIMARY KEY,
    report_id       INTEGER REFERENCES reports(id) ON DELETE CASCADE,
    media_url       TEXT NOT NULL,
    media_type      VARCHAR(10) NOT NULL, -- photo / video
    captured_at     TIMESTAMP,             -- horodatage réel de la prise
    uploaded_at     TIMESTAMP DEFAULT NOW()
);

-- ============================================
-- 6. HISTORIQUE DES STATUTS
-- ============================================
CREATE TABLE report_status_history (
    id              SERIAL PRIMARY KEY,
    report_id       INTEGER REFERENCES reports(id) ON DELETE CASCADE,
    old_status      VARCHAR(20),
    new_status      VARCHAR(20),
    changed_by      INTEGER REFERENCES users(id),
    note            TEXT,
    changed_at      TIMESTAMP DEFAULT NOW()
);

-- ============================================
-- 7. ZONES À RISQUE ("points noirs")
-- ============================================
CREATE TABLE hotspots (
    id              SERIAL PRIMARY KEY,
    name            VARCHAR(150),
    latitude        DOUBLE PRECISION NOT NULL,
    longitude       DOUBLE PRECISION NOT NULL,
    report_count    INTEGER DEFAULT 0,
    last_updated    TIMESTAMP DEFAULT NOW()
);

-- ============================================
-- 8. NOTIFICATIONS
-- ============================================
CREATE TABLE notifications (
    id              SERIAL PRIMARY KEY,
    user_id         INTEGER REFERENCES users(id) ON DELETE CASCADE,
    report_id       INTEGER REFERENCES reports(id) ON DELETE CASCADE,
    message         TEXT,
    is_read         BOOLEAN DEFAULT FALSE,
    created_at      TIMESTAMP DEFAULT NOW()
);
CREATE INDEX idx_notifications_user ON notifications(user_id, is_read);

-- ============================================
-- 9. DONNÉES DE DÉPART (optionnel)
-- ============================================
INSERT INTO authorities (name, type, zone_covered, contact_email)
VALUES ('Mairie - Service Hygiène', 'municipal', 'Centre-ville', 'hygiene@mairie.example');
