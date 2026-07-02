-- ============================================================
-- Migration 2026-07-03 — catalogue tarifaire européen (Issue #54 / P3)
--
-- tariff_grids :
--   * user_id NULL = grille du catalogue communautaire partagé (gérée par un
--     admin) ; renseigné = surcharge personnelle de l'utilisateur.
--     Les grilles existantes (mono-tenant) deviennent le catalogue initial.
--   * country : ISO 3166-1 alpha-2 (NULL = générique).
--   * currency : ISO 4217 (défaut EUR, pas de conversion automatique).
--
-- dynamic_prices : prix spot par zone de marché ENTSO-E (bidding zone) ;
-- l'unicité devient (energy_type, bidding_zone, period_start).
--
-- Le premier compte devient admin (gestion du catalogue partagé).
-- Baselinée dans schema.sql (jamais rejouée sur base fraîche).
-- ============================================================

ALTER TABLE tariff_grids
    ADD COLUMN user_id BIGINT UNSIGNED NULL AFTER id,
    ADD COLUMN country VARCHAR(2) NULL AFTER energy_type,
    ADD COLUMN currency CHAR(3) NOT NULL DEFAULT 'EUR' AFTER country,
    ADD INDEX idx_tariff_grids_user (user_id);

ALTER TABLE dynamic_prices
    ADD COLUMN bidding_zone VARCHAR(32) NOT NULL DEFAULT '10YBE----------2' AFTER energy_type,
    DROP INDEX uq_dynamic_prices,
    ADD UNIQUE KEY uq_dynamic_prices (energy_type, bidding_zone, period_start);

-- Le premier compte (le propriétaire) administre le catalogue partagé.
UPDATE users SET role = 'admin' ORDER BY id LIMIT 1;
