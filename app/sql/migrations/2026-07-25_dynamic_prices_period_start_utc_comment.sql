-- ============================================================
-- Migration 2026-07-25 — dynamic_prices.period_start : COMMENT « (UTC) » (Issue #173)
-- NON baselinée : idempotente, laissée hors du seed de schema.sql.
--
-- La colonne a été créée avec COMMENT 'Début intervalle (timezone locale)'
-- (migration 2026-06-27_dynamic_prices.sql), libellé devenu faux avec le passage
-- au stockage UTC de bout en bout (#172) : EntsoePriceParser normalise en UTC et
-- DynamicPriceRepository écrit via Dates::toDbString, comme toutes les DATETIME
-- de la base. schema.sql porte déjà le libellé corrigé — cette migration aligne
-- les bases existantes, dont SHOW CREATE TABLE affichait encore l'ancien.
--
-- Métadonnée pure : aucune requête n'en dépend, aucune donnée n'est réécrite.
-- Le type reste DATETIME NOT NULL à l'identique, donc pas de conversion de
-- valeurs ni d'impact sur uq_dynamic_prices / idx_dynamic_prices_period.
-- Idempotent : re-poser le même COMMENT est un no-op (le runner ne rollback pas
-- le DDL). Sur base fraîche (schema.sql déjà en « UTC »), no-op ; sur base
-- existante, correction réelle du libellé.
-- ============================================================

ALTER TABLE dynamic_prices
    MODIFY COLUMN period_start DATETIME NOT NULL COMMENT 'Début intervalle (UTC)';
