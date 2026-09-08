-- ============================================
-- MIGRATION : ajout des catégories de signalement
-- À exécuter une fois sur une base déjà existante :
--   psql -U waste_app -d waste_platform -f migration_add_category.sql
-- ============================================

ALTER TABLE reports
    ADD COLUMN IF NOT EXISTS category VARCHAR(40) NOT NULL DEFAULT 'dechets_sauvages';

ALTER TABLE reports
    DROP CONSTRAINT IF EXISTS chk_reports_category;

ALTER TABLE reports
    ADD CONSTRAINT chk_reports_category CHECK (
        category IN (
            'dechets_sauvages',
            'decharge_illegale',
            'pollution',
            'nid_de_poule',
            'eclairage_defectueux'
        )
    );

CREATE INDEX IF NOT EXISTS idx_reports_category ON reports(category);
