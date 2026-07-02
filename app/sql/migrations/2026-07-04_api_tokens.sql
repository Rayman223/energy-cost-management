-- ============================================================
-- Migration 2026-07-04 — jetons API par utilisateur (Issue #55 / P4)
-- Authentification machine-to-machine de l'API d'ingestion : jeton révocable,
-- haché (SHA-256) en base — le secret n'est affiché qu'à la création.
-- Rate-limit à fenêtre fixe porté par le jeton (window_start / window_count).
-- Baselinée dans schema.sql. Idempotente (CREATE TABLE IF NOT EXISTS).
-- ============================================================

CREATE TABLE IF NOT EXISTS api_tokens (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      BIGINT UNSIGNED NOT NULL,
    name         VARCHAR(120) NOT NULL DEFAULT '' COMMENT 'Libellé choisi par l''utilisateur (ex. « Agent HomeWizard »)',
    token_hash   CHAR(64) NOT NULL COMMENT 'SHA-256 hex du jeton complet',
    prefix       CHAR(12) NOT NULL COMMENT 'Préfixe non-secret pour identification dans l''UI',
    scopes       VARCHAR(255) NOT NULL DEFAULT 'ingest' COMMENT 'Scopes CSV (P4 : ingest)',
    last_used_at DATETIME NULL,
    window_start DATETIME NULL COMMENT 'Début de la fenêtre de rate-limit courante',
    window_count INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Requêtes dans la fenêtre courante',
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at   DATETIME NULL,
    UNIQUE KEY uq_api_tokens_hash (token_hash),
    INDEX idx_api_tokens_user (user_id),
    CONSTRAINT fk_api_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
