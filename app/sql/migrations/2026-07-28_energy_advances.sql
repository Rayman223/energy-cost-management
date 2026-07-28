-- ============================================================
-- Migration 2026-07-28 — barèmes d'acomptes mensuels (Issue #241)
-- NON baselinée : idempotente (CREATE TABLE IF NOT EXISTS), laissée hors du seed.
--
-- L'utilisateur paie un acompte mensuel fixe à son fournisseur, régularisé en fin
-- de cycle. On stocke le BARÈME (montant + plage de validité), et non chaque
-- prélèvement : un montant révisé une ou deux fois par an tient en deux lignes,
-- là où la saisie ligne à ligne en demanderait vingt-quatre.
--
-- `due_day` est le jour du prélèvement : le total payé sur une période se compte
-- en échéances tombées, pas au prorata des jours, pour que le montant annoncé
-- corresponde à ce qui a réellement été débité sur le compte bancaire. Un jour
-- au-delà de la fin du mois (31 en février) est ramené au dernier jour du mois
-- par le domaine (AdvanceSchedule).
--
-- Pas de clé unique : les barèmes se succèdent dans le temps. Le
-- non-chevauchement de deux barèmes d'une même énergie est validé côté service —
-- deux barèmes actifs le même mois compteraient deux prélèvements pour un seul
-- débit réel.
-- ============================================================

CREATE TABLE IF NOT EXISTS energy_advances (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id        BIGINT UNSIGNED NOT NULL,
    energy_type    VARCHAR(20) NOT NULL DEFAULT 'electricity' COMMENT 'electricity | gas | water',
    amount_monthly DECIMAL(12,4) NOT NULL COMMENT 'Acompte mensuel TTC (devise de la grille)',
    valid_from     DATE NOT NULL COMMENT 'Premier mois où ce barème s applique',
    valid_to       DATE NULL COMMENT 'Dernier mois couvert ; NULL = barème toujours en cours',
    due_day        TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Jour du prélèvement mensuel (1-31)',
    note           VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'Annotation libre (contrat, remarque)',
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_energy_advances_user (user_id, energy_type, valid_from),
    CONSTRAINT fk_energy_advances_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
