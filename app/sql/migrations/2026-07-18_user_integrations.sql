-- ============================================================
-- Migration 2026-07-18 — système de modules « connecteurs d'export » (Issue #70)
-- Baselinée dans schema.sql.
--
-- Généralise l'opt-in EnergyID en une table par module. Les champs spécifiques
-- (device_id, claimed_at) passent dans une colonne JSON `settings`. Les données
-- existantes de energyid_integrations sont reprises telles quelles.
-- ============================================================

CREATE TABLE IF NOT EXISTS user_integrations (
    user_id    BIGINT UNSIGNED NOT NULL,
    module_key VARCHAR(60) NOT NULL COMMENT 'Clé du module (energyid, ...)',
    enabled    TINYINT(1) NOT NULL DEFAULT 0,
    settings   JSON NOT NULL COMMENT 'Réglages propres au module (device_id, claimed_at, ...)',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, module_key),
    CONSTRAINT fk_user_integrations_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Reprise des opt-in EnergyID existants (idempotent : ON DUPLICATE = no-op).
-- created_at et updated_at reprennent l'updated_at legacy pour préserver l'ancienneté.
INSERT INTO user_integrations (user_id, module_key, enabled, settings, created_at, updated_at)
SELECT user_id, 'energyid', enabled,
       JSON_OBJECT('device_id', device_id, 'claimed_at', claimed_at),
       updated_at, updated_at
FROM energyid_integrations
ON DUPLICATE KEY UPDATE user_id = user_integrations.user_id;

-- energyid_integrations : gelée (plus lue/écrite par le code) — DROP prévu dans
-- une migration ultérieure (issue de suivi #70).
