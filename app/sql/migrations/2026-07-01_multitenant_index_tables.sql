-- ============================================================
-- Migration 2026-07-01 — tables d'index multi-tenant (Issue #53 / P2, étape 1)
-- Modèle générique à registres pour l'électricité/solaire + table unifiée
-- gaz/eau. Phase « expand » : créées À CÔTÉ des tables Data_* (non-cassant).
-- Idempotente (CREATE TABLE IF NOT EXISTS).
-- ============================================================

-- Compteur (électricité/solaire). Un compteur possède N registres.
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

-- Registre d'un compteur (import_t1, import_t2, export_t1, export_t2, production…).
CREATE TABLE IF NOT EXISTS meter_registers (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meter_id     BIGINT UNSIGNED NOT NULL,
    register_key VARCHAR(40) NOT NULL,
    unit         VARCHAR(16) NOT NULL DEFAULT 'kWh',
    UNIQUE KEY uq_meter_registers (meter_id, register_key),
    CONSTRAINT fk_meter_registers_meter FOREIGN KEY (meter_id) REFERENCES meters (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Relevé d'index horodaté pour un registre.
CREATE TABLE IF NOT EXISTS meter_readings (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    register_id BIGINT UNSIGNED NOT NULL,
    reading_at  DATETIME NOT NULL,
    index_value DECIMAL(12,3) NOT NULL,
    UNIQUE KEY uq_meter_readings (register_id, reading_at),
    CONSTRAINT fk_meter_readings_register FOREIGN KEY (register_id) REFERENCES meter_registers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Relevés gaz + eau fusionnés (un index m³ par relevé).
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
