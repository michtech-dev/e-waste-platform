-- ============================================
-- MIGRATION : élargissement du référentiel de catégories
-- La liste faisant désormais autorité est helpers/Categories.php (PHP),
-- pas une contrainte SQL, pour pouvoir ajouter des catégories sans migration.
-- À exécuter une fois sur une base existante :
--   psql -U waste_app -d waste_platform -f migration_expand_categories.sql
-- ============================================

ALTER TABLE reports DROP CONSTRAINT IF EXISTS chk_reports_category;

-- Renomme les anciennes valeurs vers les nouveaux codes canoniques (optionnel,
-- les anciens codes restent acceptés pour rétrocompatibilité dans Categories.php)
UPDATE reports SET category = 'decharge_sauvage' WHERE category = 'dechets_sauvages';
