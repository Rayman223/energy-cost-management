-- ============================================================
-- Migration 2026-06-30 — comptes utilisateurs (Issue #52 / P1)
-- Multi-tenant : identité OpenID Connect (sub + issuer), sans mot de passe ni
-- e-mail stocké. Idempotente (CREATE TABLE IF NOT EXISTS).
-- ============================================================

CREATE TABLE IF NOT EXISTS users (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    oidc_iss      VARCHAR(255) NOT NULL COMMENT 'Issuer OpenID (claim iss)',
    oidc_sub      VARCHAR(255) NOT NULL COMMENT 'Subject OpenID (claim sub)',
    provider      VARCHAR(60)  NOT NULL DEFAULT '' COMMENT 'Libellé court du fournisseur (ex. google)',
    display_name  VARCHAR(190) NOT NULL DEFAULT '' COMMENT 'Nom d''affichage (claim name), aucun e-mail',
    role          ENUM('user', 'admin')     NOT NULL DEFAULT 'user',
    status        ENUM('active', 'blocked') NOT NULL DEFAULT 'active',
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at DATETIME NULL,
    UNIQUE KEY uq_users_oidc (oidc_iss, oidc_sub)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_profiles (
    user_id      BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    country      VARCHAR(2)  NULL COMMENT 'Code pays ISO 3166-1 alpha-2',
    timezone     VARCHAR(64) NOT NULL DEFAULT 'Europe/Brussels',
    currency     CHAR(3)     NOT NULL DEFAULT 'EUR' COMMENT 'Devise ISO 4217',
    bidding_zone VARCHAR(32) NULL COMMENT 'Zone de marché ENTSO-E (prix dynamiques)',
    locale       VARCHAR(8)  NOT NULL DEFAULT 'fr',
    CONSTRAINT fk_user_profiles_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
