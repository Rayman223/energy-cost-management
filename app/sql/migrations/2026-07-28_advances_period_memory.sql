-- ============================================================
-- Migration 2026-07-28 — période du bilan d'acomptes mémorisée (Issue #241)
-- NON baselinée : idempotente (ADD COLUMN IF NOT EXISTS), laissée hors du seed.
--
-- La page /advances porte sur une période à dates exactes que l'utilisateur
-- choisit — typiquement son cycle de facturation. La retaper à chaque visite n'a
-- pas de sens : on la mémorise pour la restituer telle quelle au retour, jusqu'à
-- ce qu'il en choisisse une autre.
--
-- Dans user_profiles plutôt que dans un cookie : la préférence suit le compte et
-- non le navigateur, comme le fuseau ou la langue. Un bilan consulté depuis le
-- téléphone puis depuis le poste fixe montre alors la même fenêtre.
--
-- NULL = aucune période mémorisée → la page retombe sur son défaut (l'année
-- écoulée jusqu'à aujourd'hui). Les deux colonnes vont par paire, mais rien ne
-- l'impose en base : une seule renseignée est traitée comme aucune.
--
-- ALTER séparés + IF NOT EXISTS : idempotence (le DDL MySQL commit implicitement).
-- ============================================================

ALTER TABLE user_profiles
    ADD COLUMN IF NOT EXISTS advances_period_from DATE NULL COMMENT 'Début du bilan d acomptes mémorisé (#241)';

ALTER TABLE user_profiles
    ADD COLUMN IF NOT EXISTS advances_period_to DATE NULL COMMENT 'Fin (exclue) du bilan d acomptes mémorisé (#241)';
