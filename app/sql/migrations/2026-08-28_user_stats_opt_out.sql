-- ============================================================
-- Migration 2026-08-28 — retrait des statistiques agrégées (Issue #8)
-- NON baselinée : idempotente (ADD COLUMN IF NOT EXISTS), laissée hors du seed.
--
-- La page /stats publie des moyennes par pays (prix du kWh, consommation par
-- foyer). Le foyer y contribue PAR DÉFAUT — un k-anonymat de 5 empêche déjà de
-- ré-identifier qui que ce soit — mais la contribution doit rester révocable
-- sans devoir fermer son compte : c'est ce que porte cette colonne, décochable
-- depuis /account.
--
-- 0 = contribue (défaut), 1 = retiré. TINYINT(1) NOT NULL DEFAULT 0 plutôt que
-- NULLABLE : « pas d'opinion » n'existe pas ici, l'absence de choix EST le choix
-- par défaut, et un NULL forcerait chaque agrégat à écrire un COALESCE.
--
-- Effet du retrait : le foyer disparaît du numérateur ET du dénominateur du
-- seuil k. Il ne fait donc plus passer un pays au-dessus du seuil, ce qu'une
-- simple exclusion de la moyenne aurait laissé faire (le pays serait resté
-- publié « grâce » à un foyer qui s'en était justement retiré).
--
-- ALTER + IF NOT EXISTS : idempotence (le DDL MySQL commit implicitement, la
-- transaction de MigrationRunner ne peut donc pas l'annuler).
-- ============================================================

ALTER TABLE user_profiles
    ADD COLUMN IF NOT EXISTS stats_opt_out TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Retrait des statistiques agregees (#8) : 1 = foyer non compte';
