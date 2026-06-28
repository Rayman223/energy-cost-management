-- ============================================================
-- Migration : table dynamic_prices (Issue #18 — tarif dynamique)
-- À appliquer sur une base existante :
--   mysql -u <user> -p <database> < app/sql/migrations/2026-06-27_dynamic_prices.sql
-- ============================================================

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
