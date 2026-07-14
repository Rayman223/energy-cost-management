-- ============================================================
-- Migration 2026-07-14 — identités OIDC multiples par compte (Issue #137)
-- Permet de lier plusieurs identités OpenID Connect (ex. Google + Microsoft) à
-- un même compte, pour éviter durablement les doublons.
--
-- `user_identities` devient la SOURCE DE VÉRITÉ de la recherche (iss, sub). Les
-- colonnes `users.oidc_iss/oidc_sub/provider` sont CONSERVÉES (identité primaire,
-- rétro-compat totale, non-cassant) : elles pointent toujours vers l'une des
-- lignes `user_identities` du compte (invariant maintenu au (dé)liage).
--
-- Backfill : une ligne d'identité par compte existant depuis `users`.
-- Baselinée dans schema.sql. Idempotente (CREATE TABLE IF NOT EXISTS + INSERT IGNORE).
-- ============================================================

CREATE TABLE IF NOT EXISTS user_identities (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    BIGINT UNSIGNED NOT NULL,
    oidc_iss   VARCHAR(255) NOT NULL COMMENT 'Issuer OpenID (claim iss)',
    oidc_sub   VARCHAR(255) NOT NULL COMMENT 'Subject OpenID (claim sub)',
    provider   VARCHAR(60)  NOT NULL DEFAULT '' COMMENT 'Libellé court du fournisseur (clé de config)',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_identities (oidc_iss, oidc_sub),
    INDEX idx_user_identities_user (user_id),
    CONSTRAINT fk_user_identities_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Backfill : reprend l'identité primaire de chaque compte existant. INSERT IGNORE
-- rend l'opération rejouable sans double insertion (contrainte uq_user_identities).
INSERT IGNORE INTO user_identities (user_id, oidc_iss, oidc_sub, provider)
    SELECT id, oidc_iss, oidc_sub, provider FROM users;
