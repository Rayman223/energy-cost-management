-- ============================================================
-- Migration 2026-07-02 — webhook_sync_state multi-tenant (Issue #53 / P2, étape 2)
-- Ajoute user_id et passe la PK en composite (user_id, source_name).
-- Les lignes existantes reçoivent user_id=0 ; elles ont été réattribuées au
-- propriétaire lors de la finalisation multi-tenant (migration terminée).
-- Baselinée dans schema.sql (jamais rejouée sur base fraîche).
-- ============================================================

ALTER TABLE webhook_sync_state
    ADD COLUMN user_id BIGINT UNSIGNED NOT NULL DEFAULT 0 FIRST,
    DROP PRIMARY KEY,
    ADD PRIMARY KEY (user_id, source_name);
