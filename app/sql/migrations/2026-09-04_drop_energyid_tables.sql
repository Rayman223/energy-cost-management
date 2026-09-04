-- ============================================================
-- Migration 2026-09-04 — DROP user_integrations + webhook_sync_state (Issue #53)
-- NON baselinée : idempotente, laissée hors du seed de schema.sql.
--
-- EnergyID était le seul connecteur d'export (#70) : son retrait complet (#53)
-- emporte l'ossature « modules d'export » et les watermarks de synchro, donc
-- les deux tables qui les portaient. Plus aucun code ne les lit ni ne les écrit.
--
-- Destructif et assumé : les opt-in par utilisateur (user_integrations) et les
-- watermarks d'envoi (webhook_sync_state) disparaissent avec la fonctionnalité.
-- DROP … IF EXISTS = idempotent (le runner ne peut pas rollback du DDL) : no-op
-- sur base fraîche créée depuis schema.sql, DROP réel sur base existante.
-- ============================================================

DROP TABLE IF EXISTS user_integrations;
DROP TABLE IF EXISTS webhook_sync_state;
