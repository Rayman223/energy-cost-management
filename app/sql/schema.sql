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
    UNIQUE KEY uq_data_gaz_reading (reading_at, counter_m3),
    INDEX idx_data_gaz_date (reading_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Tarifs énergétiques ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS tariff_grids (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    energy_type      ENUM('electricity', 'gas', 'water') NOT NULL,
    name             VARCHAR(120) NOT NULL,
    valid_from       DATE NOT NULL,
    valid_to         DATE NULL COMMENT 'NULL = actif indéfiniment',
    pcs_coefficient  DECIMAL(8,4) NULL COMMENT 'Gaz uniquement : coefficient PCS m3->kWh. NULL = valeur config (10.55)',
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tariff_grids_type_date (energy_type, valid_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tariff_grid_lines (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tariff_grid_id  BIGINT UNSIGNED NOT NULL,
    line_key        VARCHAR(100) NOT NULL COMMENT 'Cle tarifaire (energy_t1, distribution_fixed, prosumer_annual...)',
    amount_per_kwh  DECIMAL(10,5) NOT NULL COMMENT 'Montant en EUR. Unite reelle selon la cle (EUR/kWh ou EUR/jour ou EUR/an)',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_tariff_grid_lines_grid
        FOREIGN KEY (tariff_grid_id) REFERENCES tariff_grids (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── État de synchronisation webhook EnergyID ─────────────────────────────
CREATE TABLE IF NOT EXISTS webhook_sync_state (
    source_name  VARCHAR(120) PRIMARY KEY COMMENT 'prelevement-jour | prelevement-nuit | injection-jour | injection-nuit | production-solaire',
    last_sent_at DATETIME NULL,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================
-- Seeds tarifaires — adapter avant import, puis decommenter
-- ============================================================

/*
-- Electricite bihoraire (prosumer Bruxelles)
INSERT INTO tariff_grids (energy_type, name, valid_from, valid_to) VALUES
    ('electricity', 'Tarif bihoraire 2025', '2025-01-01', NULL);

SET @elec = LAST_INSERT_ID();

INSERT INTO tariff_grid_lines (tariff_grid_id, line_key, amount_per_kwh) VALUES
    (@elec, 'energy_t1',             0.2950),
    (@elec, 'energy_t2',             0.2350),
    (@elec, 'distribution_t1',       0.0821),
    (@elec, 'distribution_t2',       0.0412),
    (@elec, 'distribution_fixed',    0.3082),   -- EUR/jour
    (@elec, 'federal_contribution',  0.0054),
    (@elec, 'injection_t1',          0.0000),   -- 0 pour prosumer
    (@elec, 'injection_t2',          0.0000),   -- 0 pour prosumer
    (@elec, 'prosumer_annual',     185.0000);   -- EUR/an taxe prosumer BRUGEL

-- Gaz naturel
INSERT INTO tariff_grids (energy_type, name, valid_from, valid_to, pcs_coefficient) VALUES
    ('gas', 'Gaz naturel 2025', '2025-01-01', NULL, 10.55);

SET @gas = LAST_INSERT_ID();

INSERT INTO tariff_grid_lines (tariff_grid_id, line_key, amount_per_kwh) VALUES
    (@gas, 'energy',               0.0680),
    (@gas, 'distribution',         0.0185),
    (@gas, 'distribution_fixed',   0.1370),   -- EUR/jour
    (@gas, 'federal_contribution', 0.0000);
*/