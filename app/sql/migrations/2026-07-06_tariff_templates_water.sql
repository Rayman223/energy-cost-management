-- ============================================================
-- Migration 2026-07-06 — tarifs dynamiques par pays + eau (Issue #80)
--
-- 1. tariff_grids :
--    * energy_type : garantit la présence de 'water' dans l'ENUM (les bases
--      migrées avant 2026-07-03 ne l'avaient pas ; MODIFY est idempotent).
--    * vat_rate : taux de TVA de la grille (%). Les montants restent saisis TTC ;
--      la décomposition HT/TVA utilise ce taux (remplace la constante 21 % codée
--      en dur dans TariffCalculatorService).
--
-- 2. tariff_grid_lines : moteur de calcul générique. Chaque ligne devient
--    autoporteuse — elle connaît son TYPE de composante (component_kind) et son
--    libellé custom éventuel ; le calcul n'a plus besoin de connaître les clés
--    belges en dur. sort_order fige l'ordre d'affichage.
--
-- 3. tariff_templates / tariff_template_fields : templates de tarifs créés par
--    l'utilisateur (structure seule, sans montants). Les templates « fournis »
--    (belges + génériques) vivent en PHP (TariffTemplateCatalog), pas ici.
--
-- Backfill des component_kind : mappe les clés belges historiques vers les kinds
-- du moteur générique (voir CASE ci-dessous). Toute clé inconnue (ex. saisie via
-- l'API historique) retombe sur 'per_kwh'.
--
-- Baselinée dans schema.sql (jamais rejouée sur base fraîche).
-- ============================================================

-- 1. tariff_grids : ENUM water (idempotent) + vat_rate
ALTER TABLE tariff_grids
    MODIFY COLUMN energy_type ENUM('electricity', 'gas', 'water') NOT NULL,
    ADD COLUMN vat_rate DECIMAL(5,2) NOT NULL DEFAULT 21.00
        COMMENT 'Taux de TVA de la grille en % (montants saisis TTC)' AFTER currency;

-- 2. tariff_grid_lines : type de composante + libellé custom + ordre
ALTER TABLE tariff_grid_lines
    ADD COLUMN component_kind VARCHAR(30) NOT NULL DEFAULT 'per_kwh'
        COMMENT 'Type de composante (moteur générique) : energy_flat, per_kwh, per_m3, fixed_monthly, fixed_annual, injection_t1...' AFTER line_key,
    ADD COLUMN label VARCHAR(150) NULL
        COMMENT 'Libellé custom (NULL = libellé du catalogue déduit de line_key)' AFTER component_kind,
    ADD COLUMN sort_order SMALLINT NOT NULL DEFAULT 0 AFTER label;

-- Backfill des kinds pour les grilles existantes (clés belges élec/gaz).
UPDATE tariff_grid_lines l
    JOIN tariff_grids g ON g.id = l.tariff_grid_id
SET l.component_kind = CASE
    WHEN l.line_key IN ('energy_simple', 'energy')                       THEN 'energy_flat'
    WHEN g.energy_type = 'electricity' AND l.line_key = 'energy_t1'      THEN 'energy_t1'
    WHEN g.energy_type = 'electricity' AND l.line_key = 'energy_t2'      THEN 'energy_t2'
    WHEN l.line_key = 'subscription'                                     THEN 'fixed_monthly'
    WHEN g.energy_type = 'electricity' AND l.line_key = 'distribution_t1' THEN 'per_kwh_t1'
    WHEN g.energy_type = 'electricity' AND l.line_key = 'distribution_t2' THEN 'per_kwh_t2'
    WHEN l.line_key = 'injection_t1'                                     THEN 'injection_t1'
    WHEN l.line_key = 'injection_t2'                                     THEN 'injection_t2'
    WHEN l.line_key LIKE '%\_annual' OR l.line_key = 'distribution_fixed' THEN 'fixed_annual'
    ELSE 'per_kwh'
END;

-- 3. Templates de tarifs de l'utilisateur (structure de champs réutilisable).
-- Pas de FK vers users (comme tariff_grids.user_id) : le scoping par user_id est
-- applicatif (TariffTemplateRepository), et schema.sql crée les tables avant users.
CREATE TABLE IF NOT EXISTS tariff_templates (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     BIGINT UNSIGNED NOT NULL COMMENT 'Propriétaire (les templates fournis vivent en PHP, pas ici)',
    energy_type ENUM('electricity', 'gas', 'water') NOT NULL,
    country     VARCHAR(2) NULL COMMENT 'ISO 3166-1 alpha-2 (NULL = générique)',
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
