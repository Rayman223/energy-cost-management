-- ============================================================
-- schema.sql — Manage Energy v3
-- Schéma complet. À importer sur une DB vierge.
-- mysql -u <user> -p <database> < app/sql/schema.sql
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- NB : les anciennes tables mono-tenant (Data_Dries, Data_Solaire, Data_Brusol,
-- Data_gaz, Data_eau) ne font plus partie du schéma. Sur une installation
-- existante, elles sont migrées puis supprimées par
-- app/scripts/finalize_multitenant.php (après backfill vers le compte owner).

-- ── Tarifs énergétiques (catalogue partagé + surcharges personnelles) ─────
CREATE TABLE IF NOT EXISTS tariff_grids (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id          BIGINT UNSIGNED NULL COMMENT 'NULL = grille du catalogue partagé (admin) ; sinon surcharge personnelle',
    energy_type      ENUM('electricity', 'gas', 'water') NOT NULL,
    country          VARCHAR(2) NULL COMMENT 'ISO 3166-1 alpha-2 (NULL = générique)',
    currency         CHAR(3) NOT NULL DEFAULT 'EUR' COMMENT 'Devise ISO 4217 (pas de conversion automatique)',
    vat_rate         DECIMAL(5,2) NOT NULL DEFAULT 21.00 COMMENT 'Taux de TVA de la grille en % (montants saisis TTC)',
    name             VARCHAR(120) NOT NULL,
    valid_from       DATE NOT NULL,
    valid_to         DATE NULL COMMENT 'NULL = actif indéfiniment',
    pcs_coefficient  DECIMAL(8,4) NULL COMMENT 'Gaz uniquement : coefficient PCS m3->kWh.',
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tariff_grids_type_date (energy_type, valid_from),
    INDEX idx_tariff_grids_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tariff_grid_lines (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tariff_grid_id  BIGINT UNSIGNED NOT NULL,
    line_key        VARCHAR(100) NOT NULL COMMENT 'Cle tarifaire (energy_t1, distribution_fixed, prosumer_annual...)',
    component_kind  VARCHAR(30) NOT NULL DEFAULT 'per_kwh' COMMENT 'Type de composante (moteur generique) : energy_flat, per_kwh, per_m3, fixed_monthly, fixed_annual, injection_t1...',
    label           VARCHAR(150) NULL COMMENT 'Libelle custom (NULL = libelle du catalogue deduit de line_key)',
    sort_order      SMALLINT NOT NULL DEFAULT 0,
    amount_per_kwh  DECIMAL(12,7) NOT NULL COMMENT 'Montant en EUR. Unite reelle selon le kind (EUR/kWh, EUR/m3, EUR/mois ou EUR/an)',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_tariff_grid_lines_grid
        FOREIGN KEY (tariff_grid_id) REFERENCES tariff_grids (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Templates de tarifs créés par l'utilisateur (structure réutilisable) ──
-- Pas de FK vers users (comme tariff_grids.user_id) : evite les contraintes
-- d'ordre de creation dans schema.sql ; le scoping par user_id est applicatif.
CREATE TABLE IF NOT EXISTS tariff_templates (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     BIGINT UNSIGNED NOT NULL COMMENT 'Proprietaire (les templates fournis vivent en PHP, pas ici)',
    energy_type ENUM('electricity', 'gas', 'water') NOT NULL,
    country     VARCHAR(2) NULL COMMENT 'ISO 3166-1 alpha-2 (NULL = generique)',
    name        VARCHAR(120) NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tariff_templates_user (user_id),
    INDEX idx_tariff_templates_type_country (energy_type, country)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tariff_template_fields (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    template_id    BIGINT UNSIGNED NOT NULL,
    line_key       VARCHAR(100) NOT NULL,
    component_kind VARCHAR(30) NOT NULL,
    label          VARCHAR(150) NULL,
    sort_order     SMALLINT NOT NULL DEFAULT 0,
    CONSTRAINT fk_ttf_template
        FOREIGN KEY (template_id) REFERENCES tariff_templates (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Prix dynamiques day-ahead (marché spot, ex. ENTSO-E) ─────────────────
-- price_eur_kwh = prix spot BRUT HTVA (€/kWh) ; marge fournisseur et TVA sont
-- appliquées au moment du calcul, pas au stockage.
CREATE TABLE IF NOT EXISTS dynamic_prices (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    energy_type    ENUM('electricity') NOT NULL DEFAULT 'electricity',
    bidding_zone   VARCHAR(32) NOT NULL DEFAULT '10YBE----------2' COMMENT 'Zone de marché ENTSO-E',
    period_start   DATETIME NOT NULL COMMENT 'Début intervalle (timezone locale)',
    period_end     DATETIME NOT NULL,
    resolution_min SMALLINT UNSIGNED NOT NULL COMMENT '15 ou 60',
    price_eur_kwh  DECIMAL(12,7) NOT NULL COMMENT 'Prix spot day-ahead €/kWh (HTVA, hors marge)',
    source         VARCHAR(50) NOT NULL DEFAULT 'entsoe',
    fetched_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_dynamic_prices (energy_type, bidding_zone, period_start),
    INDEX idx_dynamic_prices_period (period_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── État de synchronisation webhook EnergyID (scopé par utilisateur) ─────
CREATE TABLE IF NOT EXISTS webhook_sync_state (
    user_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
    source_name  VARCHAR(120) NOT NULL COMMENT 'prelevement-jour | prelevement-nuit | injection-jour | injection-nuit | production-solaire | gas-index | water-index',
    last_sent_at DATETIME NULL,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, source_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Comptes utilisateurs (identité OpenID Connect, sans mot de passe) ────
CREATE TABLE IF NOT EXISTS users (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    oidc_iss      VARCHAR(255) NOT NULL COMMENT 'Issuer OpenID (claim iss)',
    oidc_sub      VARCHAR(255) NOT NULL COMMENT 'Subject OpenID (claim sub)',
    provider      VARCHAR(60)  NOT NULL DEFAULT '' COMMENT 'Libellé court du fournisseur',
    display_name  VARCHAR(190) NOT NULL DEFAULT '' COMMENT 'Nom d''affichage (aucun e-mail)',
    role          ENUM('user', 'admin')     NOT NULL DEFAULT 'user',
    status        ENUM('active', 'blocked') NOT NULL DEFAULT 'active',
    terms_accepted_at DATETIME NULL COMMENT 'Acceptation CGU/confidentialité',
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at DATETIME NULL,
    UNIQUE KEY uq_users_oidc (oidc_iss, oidc_sub)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_profiles (
    user_id      BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    country      VARCHAR(2)  NULL COMMENT 'Code pays ISO 3166-1 alpha-2',
    timezone     VARCHAR(64) NOT NULL DEFAULT 'Europe/Brussels',
    currency     CHAR(3)     NOT NULL DEFAULT 'EUR' COMMENT 'Devise ISO 4217',
    bidding_zone VARCHAR(32) NULL COMMENT 'Zone de marché ENTSO-E',
    locale       VARCHAR(8)  NOT NULL DEFAULT 'fr',
    CONSTRAINT fk_user_profiles_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Tables d'index multi-tenant (modèle à registres + gaz/eau unifiés) ───
CREATE TABLE IF NOT EXISTS meters (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     BIGINT UNSIGNED NOT NULL,
    energy_type ENUM('electricity') NOT NULL DEFAULT 'electricity',
    label       VARCHAR(120) NOT NULL DEFAULT '',
    country     VARCHAR(2)  NULL,
    timezone    VARCHAR(64) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_meters_user (user_id),
    CONSTRAINT fk_meters_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS meter_registers (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meter_id     BIGINT UNSIGNED NOT NULL,
    register_key VARCHAR(40) NOT NULL,
    unit         VARCHAR(16) NOT NULL DEFAULT 'kWh',
    UNIQUE KEY uq_meter_registers (meter_id, register_key),
    CONSTRAINT fk_meter_registers_meter FOREIGN KEY (meter_id) REFERENCES meters (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS meter_readings (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    register_id BIGINT UNSIGNED NOT NULL,
    reading_at  DATETIME NOT NULL,
    index_value DECIMAL(12,3) NOT NULL,
    UNIQUE KEY uq_meter_readings (register_id, reading_at),
    CONSTRAINT fk_meter_readings_register FOREIGN KEY (register_id) REFERENCES meter_registers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS utility_readings (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     BIGINT UNSIGNED NOT NULL,
    energy_type ENUM('gas', 'water') NOT NULL,
    reading_at  DATETIME NOT NULL,
    counter_m3  DECIMAL(12,3) NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_utility_readings (user_id, energy_type, reading_at),
    CONSTRAINT fk_utility_readings_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Intégration EnergyID par utilisateur (opt-in, BE/NL) ─────────────────
CREATE TABLE IF NOT EXISTS energyid_integrations (
    user_id    BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    enabled    TINYINT(1) NOT NULL DEFAULT 0,
    device_id  VARCHAR(120) NOT NULL,
    claimed_at DATETIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_energyid_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Jetons API (authentification machine des agents) ─────────────────────
CREATE TABLE IF NOT EXISTS api_tokens (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      BIGINT UNSIGNED NOT NULL,
    name         VARCHAR(120) NOT NULL DEFAULT '' COMMENT 'Libellé choisi par l''utilisateur',
    token_hash   CHAR(64) NOT NULL COMMENT 'SHA-256 hex du jeton complet',
    prefix       CHAR(12) NOT NULL COMMENT 'Préfixe non-secret pour identification dans l''UI',
    scopes       VARCHAR(255) NOT NULL DEFAULT 'ingest',
    last_used_at DATETIME NULL,
    window_start DATETIME NULL COMMENT 'Début de la fenêtre de rate-limit courante',
    window_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at   DATETIME NULL,
    UNIQUE KEY uq_api_tokens_hash (token_hash),
    INDEX idx_api_tokens_user (user_id),
    CONSTRAINT fk_api_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Suivi des migrations versionnées ─────────────────────────────────────
CREATE TABLE IF NOT EXISTS schema_migrations (
    version    VARCHAR(255) NOT NULL PRIMARY KEY,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Baseline : toute migration déjà reflétée dans ce schema.sql est marquée
-- appliquée pour ne pas être rejouée sur une base fraîche (certaines ne sont
-- pas idempotentes, ex. DROP INDEX / ALTER). À maintenir à chaque migration.
INSERT IGNORE INTO schema_migrations (version) VALUES
    ('2026-06-26_drop_redundant_reading_indexes.sql'),
    ('2026-06-27_dynamic_prices.sql'),
    ('2026-06-30_users.sql'),
    ('2026-07-01_multitenant_index_tables.sql'),
    ('2026-07-02_webhook_sync_state_user.sql'),
    ('2026-07-03_tariffs_eu.sql'),
    ('2026-07-04_api_tokens.sql'),
    ('2026-07-05_account_energyid.sql');
