-- ============================================================
-- Migration 2026-07-05 — self-service compte, EnergyID opt-in, consentement
-- (Issue #56 / P5). Baselinée dans schema.sql.
--
-- - users.terms_accepted_at : horodatage d'acceptation des CGU/confidentialité.
-- - energyid_integrations : opt-in EnergyID PAR UTILISATEUR (BE/NL). Aucun
--   secret stocké — le flux « hello » re-provisionne à chaque run via les
--   credentials partenaire globaux ; on ne conserve que l'activation, le
--   deviceId (dérivé, non secret) et l'horodatage de claim (statut UI).
-- ============================================================

ALTER TABLE users ADD COLUMN terms_accepted_at DATETIME NULL AFTER status;

CREATE TABLE IF NOT EXISTS energyid_integrations (
    user_id    BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    enabled    TINYINT(1) NOT NULL DEFAULT 0,
    device_id  VARCHAR(120) NOT NULL,
    claimed_at DATETIME NULL COMMENT 'Renseigné quand le webhook a été obtenu (device réclamé côté EnergyID)',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_energyid_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
