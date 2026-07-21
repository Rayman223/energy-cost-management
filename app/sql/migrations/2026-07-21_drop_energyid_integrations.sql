-- ============================================================
-- Migration 2026-07-21 — DROP energyid_integrations (Issue #166 / suivi #70)
-- NON baselinée : idempotente, laissée hors du seed de schema.sql.
--
-- La table est gelée depuis #70 (plus lue ni écrite par le code, remplacée par
-- user_integrations) et n'était conservée que pour un rollback simple. #70 étant
-- déployé et stable, on la supprime. Le backfill 2026-07-18 est désormais baseliné
-- dans schema.sql (source disparue → plus rejoué). DROP … IF EXISTS = idempotent
-- (le runner ne peut pas rollback du DDL) : no-op sur base fraîche, DROP réel sur
-- base existante.
-- ============================================================

DROP TABLE IF EXISTS energyid_integrations;
