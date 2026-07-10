-- ============================================================
-- Migration 2026-07-07 — visibilité des templates + compteur d'utilisation (Issue #84)
--
-- 1. tariff_templates.visibility : un template perso devient partageable. Seuls
--    les templates 'public' sont visibles/importables par les autres comptes ;
--    'private' reste réservé au propriétaire. Défaut 'private' (les templates
--    existants restent privés).
--
-- 2. tariff_template_usages : compteur de popularité. Une ligne par
--    (template, utilisateur) → COUNT(*) groupé = nb d'utilisateurs distincts
--    (une utilisation = un utilisateur, quel que soit le nombre de grilles).
--    template_ref est polymorphe ('builtin:<code>' | 'user:<id>'). FK sur
--    user_id → users : la clôture d'un compte purge SES usages (RGPD) ; les
--    usages d'autres comptes sur un template public survivent.
--
-- RGPD : à la clôture d'un compte, les templates 'public' sont CONSERVÉS mais
-- ANONYMISÉS (user_id → NULL), seuls les 'private' sont supprimés (géré
-- applicativement dans AccountEraser). user_id devient donc nullable.
--
-- NON baselinée dans schema.sql : elle ALTER une table existante (tariff_templates),
-- donc doit être exécutée par migrate.php sur les bases déjà déployées (le
-- CREATE TABLE IF NOT EXISTS de schema.sql n'ajoute pas de colonne à une table
-- existante). Rendue idempotente (ADD COLUMN/INDEX IF NOT EXISTS, MODIFY) pour
-- être rejouable sans échec sur une base fraîche où schema.sql l'a déjà appliquée.
-- ============================================================

-- 1. Visibilité public / privé + user_id anonymisable (nullable). Idempotent.
ALTER TABLE tariff_templates
    MODIFY COLUMN user_id BIGINT UNSIGNED NULL
        COMMENT 'Proprietaire (NULL = anonymise : compte supprime, template public conserve)',
    ADD COLUMN IF NOT EXISTS visibility ENUM('private', 'public') NOT NULL DEFAULT 'private'
        COMMENT 'public = visible/importable par les autres comptes ; private = proprietaire seul'
        AFTER name,
    ADD INDEX IF NOT EXISTS idx_tariff_templates_visibility (visibility, energy_type);

-- 2. Compteur d'utilisation (utilisateurs distincts par template).
CREATE TABLE IF NOT EXISTS tariff_template_usages (
    template_ref  VARCHAR(80) NOT NULL COMMENT 'builtin:<code> | user:<id>',
    user_id       BIGINT UNSIGNED NOT NULL COMMENT 'Utilisateur ayant utilisé le template',
    first_used_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (template_ref, user_id),
    INDEX idx_tariff_template_usages_user (user_id),
    CONSTRAINT fk_ttu_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
