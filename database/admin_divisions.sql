-- ============================================
-- DIVISIONS ADMINISTRATIVES DU BURUNDI
-- Basé sur la Loi organique n°1/05 du 16 mars 2023
-- Hiérarchie : Province > Commune > Zone > Colline/Quartier
-- ============================================

CREATE TABLE provinces (
    id      SERIAL PRIMARY KEY,
    name    VARCHAR(100) NOT NULL UNIQUE,
    chef_lieu VARCHAR(100)
);

CREATE TABLE communes (
    id          SERIAL PRIMARY KEY,
    province_id INTEGER NOT NULL REFERENCES provinces(id) ON DELETE CASCADE,
    name        VARCHAR(100) NOT NULL,
    chef_lieu   VARCHAR(100),
    UNIQUE (province_id, name)
);
CREATE INDEX idx_communes_province ON communes(province_id);

CREATE TABLE zones (
    id          SERIAL PRIMARY KEY,
    commune_id  INTEGER NOT NULL REFERENCES communes(id) ON DELETE CASCADE,
    name        VARCHAR(100) NOT NULL,
    UNIQUE (commune_id, name)
);
CREATE INDEX idx_zones_commune ON zones(commune_id);

CREATE TABLE collines (
    id      SERIAL PRIMARY KEY,
    zone_id INTEGER NOT NULL REFERENCES zones(id) ON DELETE CASCADE,
    name    VARCHAR(100) NOT NULL,
    type    VARCHAR(20) NOT NULL DEFAULT 'colline' CHECK (type IN ('colline', 'quartier')),
    UNIQUE (zone_id, name)
);
CREATE INDEX idx_collines_zone ON collines(zone_id);

-- ============================================
-- Rattachement des signalements à la hiérarchie administrative
-- (en complément du couple latitude/longitude déjà utilisé pour la carte)
-- ============================================
ALTER TABLE reports ADD COLUMN IF NOT EXISTS province_id INTEGER REFERENCES provinces(id);
ALTER TABLE reports ADD COLUMN IF NOT EXISTS commune_id  INTEGER REFERENCES communes(id);
ALTER TABLE reports ADD COLUMN IF NOT EXISTS zone_id     INTEGER REFERENCES zones(id);
ALTER TABLE reports ADD COLUMN IF NOT EXISTS colline_id  INTEGER REFERENCES collines(id);

CREATE INDEX IF NOT EXISTS idx_reports_province ON reports(province_id);
CREATE INDEX IF NOT EXISTS idx_reports_commune  ON reports(commune_id);
CREATE INDEX IF NOT EXISTS idx_reports_zone      ON reports(zone_id);
CREATE INDEX IF NOT EXISTS idx_reports_colline    ON reports(colline_id);

-- ============================================
-- Code de suivi public (permet à un citoyen de suivre son signalement
-- sans compte, en le partageant, sans exposer les autres signalements)
-- ============================================
ALTER TABLE reports ADD COLUMN IF NOT EXISTS tracking_code VARCHAR(12) UNIQUE;
CREATE INDEX IF NOT EXISTS idx_reports_tracking_code ON reports(tracking_code);

-- ============================================
-- SEED : les 5 provinces (Article 4 de la loi)
-- ============================================
INSERT INTO provinces (name, chef_lieu) VALUES
('BUHUMUZA', 'Cankuzo'),
('BUJUMBURA', 'Bujumbura'),
('BURUNGA', 'Makamba'),
('BUTANYERERA', 'Ngozi'),
('GITEGA', 'Gitega')
ON CONFLICT (name) DO NOTHING;

-- ============================================
-- SEED : les 42 communes (Article 5 de la loi), rattachées à leur province
-- ============================================
INSERT INTO communes (province_id, name) VALUES
-- Province de BUHUMUZA
((SELECT id FROM provinces WHERE name = 'BUHUMUZA'), 'Butaganzwa'),
((SELECT id FROM provinces WHERE name = 'BUHUMUZA'), 'Butihinda'),
((SELECT id FROM provinces WHERE name = 'BUHUMUZA'), 'Cankuzo'),
((SELECT id FROM provinces WHERE name = 'BUHUMUZA'), 'Gisagara'),
((SELECT id FROM provinces WHERE name = 'BUHUMUZA'), 'Gisuru'),
((SELECT id FROM provinces WHERE name = 'BUHUMUZA'), 'Muyinga'),
((SELECT id FROM provinces WHERE name = 'BUHUMUZA'), 'Ruyigi'),
-- Province de BUJUMBURA
((SELECT id FROM provinces WHERE name = 'BUJUMBURA'), 'Bubanza'),
((SELECT id FROM provinces WHERE name = 'BUJUMBURA'), 'Bukinanyana'),
((SELECT id FROM provinces WHERE name = 'BUJUMBURA'), 'Cibitoke'),
((SELECT id FROM provinces WHERE name = 'BUJUMBURA'), 'Isare'),
((SELECT id FROM provinces WHERE name = 'BUJUMBURA'), 'Mpanda'),
((SELECT id FROM provinces WHERE name = 'BUJUMBURA'), 'Mugere'),
((SELECT id FROM provinces WHERE name = 'BUJUMBURA'), 'Mugina'),
((SELECT id FROM provinces WHERE name = 'BUJUMBURA'), 'Muhuta'),
((SELECT id FROM provinces WHERE name = 'BUJUMBURA'), 'Mukaza'),
((SELECT id FROM provinces WHERE name = 'BUJUMBURA'), 'Ntahangwa'),
((SELECT id FROM provinces WHERE name = 'BUJUMBURA'), 'Rwibaga'),
-- Province de BURUNGA
((SELECT id FROM provinces WHERE name = 'BURUNGA'), 'Bururi'),
((SELECT id FROM provinces WHERE name = 'BURUNGA'), 'Makamba'),
((SELECT id FROM provinces WHERE name = 'BURUNGA'), 'Matana'),
((SELECT id FROM provinces WHERE name = 'BURUNGA'), 'Musongati'),
((SELECT id FROM provinces WHERE name = 'BURUNGA'), 'Nyanza'),
((SELECT id FROM provinces WHERE name = 'BURUNGA'), 'Rumonge'),
((SELECT id FROM provinces WHERE name = 'BURUNGA'), 'Rutana'),
-- Province de BUTANYERERA
((SELECT id FROM provinces WHERE name = 'BUTANYERERA'), 'Busoni'),
((SELECT id FROM provinces WHERE name = 'BUTANYERERA'), 'Kayanza'),
((SELECT id FROM provinces WHERE name = 'BUTANYERERA'), 'Kiremba'),
((SELECT id FROM provinces WHERE name = 'BUTANYERERA'), 'Kirundo'),
((SELECT id FROM provinces WHERE name = 'BUTANYERERA'), 'Matongo'),
((SELECT id FROM provinces WHERE name = 'BUTANYERERA'), 'Muhanga'),
((SELECT id FROM provinces WHERE name = 'BUTANYERERA'), 'Ngozi'),
((SELECT id FROM provinces WHERE name = 'BUTANYERERA'), 'Tangara'),
-- Province de GITEGA
((SELECT id FROM provinces WHERE name = 'GITEGA'), 'Bugendana'),
((SELECT id FROM provinces WHERE name = 'GITEGA'), 'Gishubi'),
((SELECT id FROM provinces WHERE name = 'GITEGA'), 'Gitega'),
((SELECT id FROM provinces WHERE name = 'GITEGA'), 'Karusi'),
((SELECT id FROM provinces WHERE name = 'GITEGA'), 'Kiganda'),
((SELECT id FROM provinces WHERE name = 'GITEGA'), 'Muramvya'),
((SELECT id FROM provinces WHERE name = 'GITEGA'), 'Mwaro'),
((SELECT id FROM provinces WHERE name = 'GITEGA'), 'Nyabihanga'),
((SELECT id FROM provinces WHERE name = 'GITEGA'), 'Shombo')
ON CONFLICT (province_id, name) DO NOT

