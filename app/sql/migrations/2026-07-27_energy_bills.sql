-- ============================================================
-- Migration 2026-07-27 — factures énergie saisies (Issue #229)
-- NON baselinée : idempotente (CREATE TABLE IF NOT EXISTS), laissée hors du seed.
--
-- Support du rapprochement facture : l'utilisateur saisit, mois par mois, la part
-- ÉNERGIE réellement facturée par son fournisseur. Croisée avec le coût calculé,
-- elle permet de retrouver le couple (spot_coefficient, spot_offset) du contrat —
-- ces paramètres étant rarement lisibles sur une fiche tarifaire.
--
-- Les deux montants sont NULLABLE et non exclusifs : selon la facture, l'utilisateur
-- dispose du HTVA, du TTC, ou des deux. La conversion de l'un vers l'autre utilise le
-- taux TVA de la grille de la période (source unique depuis #232) et n'est donc pas
-- figée en base.
-- ============================================================

CREATE TABLE IF NOT EXISTS energy_bills (
    id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id            BIGINT UNSIGNED NOT NULL,
    energy_type        VARCHAR(20) NOT NULL DEFAULT 'electricity',
    period_year        SMALLINT UNSIGNED NOT NULL COMMENT 'Année calendaire de la période facturée',
    period_month       TINYINT UNSIGNED NOT NULL COMMENT 'Mois calendaire 1-12',
    amount_energy_htva DECIMAL(12,4) NULL COMMENT 'Part énergie facturée HTVA (devise de la grille)',
    amount_energy_ttc  DECIMAL(12,4) NULL COMMENT 'Part énergie facturée TTC (devise de la grille)',
    note               VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'Annotation libre (n° de facture, remarque)',
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_energy_bills_period (user_id, energy_type, period_year, period_month),
    INDEX idx_energy_bills_user (user_id),
    CONSTRAINT fk_energy_bills_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
