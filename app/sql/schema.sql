CREATE TABLE IF NOT EXISTS energy_readings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reading_at DATETIME NOT NULL,
    meter_type ENUM('electricity', 'gas') NOT NULL,
    metric VARCHAR(100) NOT NULL,
    metric_kind VARCHAR(30) NOT NULL,
    unit VARCHAR(20) NOT NULL,
    interval_code VARCHAR(20) NOT NULL,
    value DECIMAL(12,3) NOT NULL,
    published_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_energy_readings_publish (meter_type, published_at, reading_at)
);

CREATE TABLE IF NOT EXISTS gas_manual_readings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reading_at DATETIME NOT NULL,
    counter_m3 DECIMAL(12,3) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_gas_manual_readings_date (reading_at)
);

CREATE TABLE IF NOT EXISTS tariff_grids (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    energy_type ENUM('electricity', 'gas') NOT NULL,
    name VARCHAR(120) NOT NULL,
    valid_from DATE NOT NULL,
    valid_to DATE NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS tariff_grid_lines (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tariff_grid_id BIGINT UNSIGNED NOT NULL,
    line_key VARCHAR(100) NOT NULL,
    amount_per_kwh DECIMAL(10,5) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_tariff_grid_lines_grid
        FOREIGN KEY (tariff_grid_id) REFERENCES tariff_grids (id)
        ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS webhook_sync_state (
    source_name VARCHAR(120) PRIMARY KEY,
    last_sent_at DATETIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
