-- ============================================================
-- schema.sql — Manage Energy v2
-- Schéma complet. À importer sur une DB vierge.
-- mysql -u <user> -p <database> < app/sql/schema.sql
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ── Relevés électricité (compteur P1 Dries) ──────────────────────────────
CREATE TABLE IF NOT EXISTS Data_Dries (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    timestamp    DATETIME NOT NULL,
    Prelev_jour  DECIMAL(12,3) NOT NULL COMMENT 'Import T1 (jour) kWh cumulatif',
    Prelev_nuit  DECIMAL(12,3) NOT NULL COMMENT 'Import T2 (nuit) kWh cumulatif',
    Injec_jour   DECIMAL(12,3) NOT NULL COMMENT 'Export T1 (jour) kWh cumulatif',
    Injec_nuit   DECIMAL(12,3) NOT NULL COMMENT 'Export T2 (nuit) kWh cumulatif',
    UNIQUE KEY uq_data_dries_timestamp (timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Production solaire (dongle P1 sur onduleur) ───────────────────────────
-- Production = total_power_export_kwh, index cumulatif en kWh.
-- Data_Brusol est le fallback historique tant que Data_Solaire n'est pas fiable.
CREATE TABLE IF NOT EXISTS Data_Solaire (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    timestamp  DATETIME NOT NULL,
    production DECIMAL(12,3) NOT NULL COMMENT 'Index cumulatif kWh (total_power_export_kwh)',
    UNIQUE KEY uq_data_solaire_timestamp (timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS Data_Brusol (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    timestamp  DATETIME NOT NULL,
    production DECIMAL(12,3) NOT NULL COMMENT 'Production journalière Wh (source Brusol)',
    UNIQUE KEY uq_data_brusol_timestamp (timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Relevés gaz (encodage manuel) ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS Data_gaz (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reading_at  DATETIME NOT NULL,
    counter_m3  DECIMAL(12,3) NOT NULL COMMENT 'Index compteur gaz en m³',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_data_gaz_reading (reading_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Relevés eau (encodage manuel) ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS Data_eau (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reading_at  DATETIME NOT NULL,
    counter_m3  DECIMAL(12,3) NOT NULL COMMENT 'Index compteur eau en m³',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_data_eau_reading (reading_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Tarifs énergétiques ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS tariff_grids (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    energy_type      ENUM('electricity', 'gas', 'water') NOT NULL,
    name             VARCHAR(120) NOT NULL,
    valid_from       DATE NOT NULL,
    valid_to         DATE NULL COMMENT 'NULL = actif indéfiniment',
    pcs_coefficient  DECIMAL(8,4) NULL COMMENT 'Gaz uniquement : coefficient PCS m3->kWh.',
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tariff_grids_type_date (energy_type, valid_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tariff_grid_lines (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tariff_grid_id  BIGINT UNSIGNED NOT NULL,
    line_key        VARCHAR(100) NOT NULL COMMENT 'Cle tarifaire (energy_t1, distribution_fixed, prosumer_annual...)',
    amount_per_kwh  DECIMAL(12,7) NOT NULL COMMENT 'Montant en EUR. Unite reelle selon la cle (EUR/kWh ou EUR/jour ou EUR/an)',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_tariff_grid_lines_grid
        FOREIGN KEY (tariff_grid_id) REFERENCES tariff_grids (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Prix dynamiques day-ahead (marché spot, ex. ENTSO-E) ─────────────────
-- price_eur_kwh = prix spot BRUT HTVA (€/kWh) ; marge fournisseur et TVA sont
-- appliquées au moment du calcul, pas au stockage.
CREATE TABLE IF NOT EXISTS dynamic_prices (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    energy_type    ENUM('electricity') NOT NULL DEFAULT 'electricity',
    period_start   DATETIME NOT NULL COMMENT 'Début intervalle (timezone locale)',
    period_end     DATETIME NOT NULL,
    resolution_min SMALLINT UNSIGNED NOT NULL COMMENT '15 ou 60',
    price_eur_kwh  DECIMAL(12,7) NOT NULL COMMENT 'Prix spot day-ahead €/kWh (HTVA, hors marge)',
    source         VARCHAR(50) NOT NULL DEFAULT 'entsoe',
    fetched_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_dynamic_prices (energy_type, period_start),
    INDEX idx_dynamic_prices_period (period_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── État de synchronisation webhook EnergyID ─────────────────────────────
CREATE TABLE IF NOT EXISTS webhook_sync_state (
    source_name  VARCHAR(120) PRIMARY KEY COMMENT 'prelevement-jour | prelevement-nuit | injection-jour | injection-nuit | production-solaire | gas-index | water-index',
    last_sent_at DATETIME NULL,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
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

-- ── Suivi des migrations versionnées ─────────────────────────────────────
CREATE TABLE IF NOT EXISTS schema_migrations (
    version    VARCHAR(255) NOT NULL PRIMARY KEY,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Baseline : les migrations antérieures à l'introduction du runner sont déjà
-- reflétées dans ce schema.sql ; on les marque appliquées pour ne pas les rejouer
-- (certaines ne sont pas idempotentes, ex. DROP INDEX).
INSERT IGNORE INTO schema_migrations (version) VALUES
    ('2026-06-26_drop_redundant_reading_indexes.sql'),
    ('2026-06-27_dynamic_prices.sql');
