-- ============================================================
-- Migration 2026-07-10 — catégorie d'affichage choisie par l'utilisateur (Issue #87)
--
-- Jusqu'ici la catégorie d'une ligne (Énergie / Abonnements & forfaits / Taxes &
-- contributions / Injection) était dérivée à la volée du component_kind
-- (ComponentKind::group()) : non stockée, non modifiable. #87 la rend explicite
-- pour que l'utilisateur range chaque champ dans la catégorie de son choix
-- (ex. déplacer une distribution hors des taxes, cf. TariffCategory).
--
-- category : jeu fermé 'energy'|'distribution'|'fixed'|'taxes'|'injection'.
--   * NULL = pas de choix explicite → l'application retombe sur la catégorie
--     dérivée du kind (TariffCategory::defaultForKind). Pas de backfill : les
--     lignes existantes gardent leur affichage historique tant qu'elles ne sont
--     pas recatégorisées.
--
-- NON baselinée : elle ALTER des tables existantes (tariff_grid_lines,
-- tariff_template_fields), donc exécutée par migrate.php sur les bases déjà
-- déployées. Idempotente (ADD COLUMN IF NOT EXISTS) pour être rejouable sans
-- échec sur une base fraîche où schema.sql l'a déjà appliquée.
-- ============================================================

ALTER TABLE tariff_grid_lines
    ADD COLUMN IF NOT EXISTS category VARCHAR(30) NULL
        COMMENT 'Categorie d affichage choisie (NULL = derivee du component_kind)'
        AFTER component_kind;

ALTER TABLE tariff_template_fields
    ADD COLUMN IF NOT EXISTS category VARCHAR(30) NULL
        COMMENT 'Categorie d affichage choisie (NULL = derivee du component_kind)'
        AFTER component_kind;
