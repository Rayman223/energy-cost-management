-- ============================================================
-- Migration 2026-08-21 — Bornes de fin exclues (Issue #1)
--
-- L'application mélangeait deux conventions de borne de fin, jusque sur la même
-- page : tariff_grids.valid_to et energy_advances.valid_to désignaient le DERNIER
-- jour couvert (borne incluse), tandis que la période du bilan d'acomptes
-- (user_profiles.advances_period_to, #241) désignait déjà le premier jour HORS
-- bilan (borne exclue). Sur /advances, le « Jusqu'au » d'un barème et le « Au » du
-- bilan se lisaient donc différemment à quelques centaines de pixels l'un de
-- l'autre — et une erreur d'un jour sur cette borne n'y décale pas un détail :
-- elle ajoute ou retire UN PRÉLÈVEMENT ENTIER au total payé.
--
-- La convention retenue (cf. app/docs/date-bounds.md) est la FIN EXCLUE : une date
-- est un instant à minuit, le jour pas encore commencé. C'est la convention des
-- factures de fournisseurs d'énergie, celle qu'employait déjà le calcul de coût,
-- et elle fait se recoller deux périodes successives sur la même date.
--
-- Les deux colonnes inclusives avancent donc d'un jour. AUCUNE AUTRE COLONNE n'est
-- touchée : advances_period_to était déjà exclusive, ce qui préserve au passage les
-- périodes mémorisées et les URL /advances?from=…&to=… mises en favori.
--
-- COMPENSATION EXACTE : ce décalage va de pair avec le passage de `<=` à `<` dans
-- TariffGrid::isActiveOn(), AdvanceSchedule et les requêtes des repositories. Pris
-- ensemble, migration et code sont un NO-OP sur toute valeur calculée — un coût,
-- un total payé, un solde doivent être identiques avant et après. Appliquer l'un
-- sans l'autre décale les résultats d'un jour, donc potentiellement d'un acompte.
--
-- NON IDEMPOTENTE, ET C'EST IRRATTRAPABLE. Un UPDATE relatif ne peut pas se
-- rejouer : un second passage décalerait les dates d'un jour de plus, en silence.
-- Le MigrationRunner garantit l'exécution unique via schema_migrations.
--
--   ⚠  NE JAMAIS supprimer la ligne de cette migration dans schema_migrations
--      pour la « réappliquer ». Il n'y a pas de garde possible côté SQL : rien ne
--      distingue une date déjà décalée d'une date qui ne l'est pas.
--
-- HORS SEED, VOLONTAIREMENT — et contrairement à ce que suggère la règle générale
-- de schema.sql pour les migrations non idempotentes. La baseliner l'inscrirait
-- dans l'INSERT IGNORE de schema.sql, or schema.sql est aussi rejoué sur base
-- EXISTANTE : la migration y serait marquée appliquée AVANT que migrate.php ne la
-- lance, et la production ne serait jamais migrée — toutes les grilles y
-- perdraient leur dernier jour. Sur base fraîche, les tables sont vides au moment
-- où le runner arrive ici : les deux UPDATE sont des no-op, et la garde CI C1
-- (convergence au 2e passage) est satisfaite par le suivi en schema_migrations.
-- ============================================================

UPDATE tariff_grids
   SET valid_to = valid_to + INTERVAL 1 DAY
 WHERE valid_to IS NOT NULL;

UPDATE energy_advances
   SET valid_to = valid_to + INTERVAL 1 DAY
 WHERE valid_to IS NOT NULL;
