-- ============================================================
-- Migration 2026-10-05 — mode tarifaire « indexe mensuel » (Issue #93)
-- NON baselinee : idempotente, laissee hors du seed de schema.sql.
--
-- Un contrat belge a prix VARIABLE n'est pas un contrat dynamique. Il est facture
-- `X x Belpex_RLP_M + Y`, ou Belpex_RLP_M est la moyenne des cotations day-ahead du
-- mois PONDEREE par un profil de consommation (profil RLP publie par Synergrid) :
-- un SEUL prix unitaire pour tout le mois, connu seulement en fin de mois. Les
-- modes existants ne savaient pas representer cela — `dynamic_quarter` facture
-- chaque creneau a son prix propre.
--
-- D'ou une quatrieme valeur d'ENUM. Le prefixe marque la famille :
--   `dynamic_*` → le prix change a chaque creneau (arbitrage temporel possible) ;
--   `indexed_*` → le prix est une moyenne de marche, plate sur la periode.
-- La place reste libre pour un futur `indexed_quarterly` (certains contrats BE
-- indexent sur un trimestre Belpex).
--
-- AUCUN BACKFILL, volontairement. Les coefficients X et Y d'un contrat ne sont pas
-- deductibles d'une grille fixe : toute valeur par defaut serait inventee et
-- fausserait silencieusement les historiques. L'utilisateur bascule lui-meme la
-- grille concernee et saisit X et Y depuis sa fiche tarifaire, sous /tariffs.
-- Comme le mode est versionne par valid_from/valid_to (#245), dater une bascule
-- consiste a creer une nouvelle grille, pas a modifier celle en cours.
--
-- IDEMPOTENCE : `MODIFY COLUMN` rejoue a l'identique est un no-op. La definition
-- ci-dessous doit rester le MIROIR EXACT de app/sql/schema.sql, sans quoi la garde
-- CI (`migrate.php --dry-run` apres import de schema.sql) ne converge plus.
-- ============================================================

ALTER TABLE tariff_grids
    MODIFY COLUMN pricing_mode
        ENUM('fixed', 'dynamic_hourly', 'dynamic_quarter', 'indexed_monthly')
        NOT NULL DEFAULT 'fixed'
        COMMENT 'Electricite : mode de tarification du contrat (fixe / dynamique 1h / dynamique 15min / indexe mensuel)';
