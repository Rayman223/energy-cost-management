-- ============================================================
-- Migration 2026-07-31 — le mode tarifaire appartient a la grille (Issue #245)
-- NON baselinee : idempotente, laissee hors du seed de schema.sql.
--
-- `user_profiles.pricing_mode` (#88) etait un reglage GLOBAL et NON VERSIONNE :
-- basculer en dynamique recalculait tout l'historique en dynamique, y compris les
-- mois encore sous contrat fixe, et un retour au fixe effacait le passe dynamique.
-- Le mode rejoint donc `tariff_grids`, ou il est versionne par valid_from/valid_to
-- comme le reste du contrat — meme raisonnement que le deplacement de la TVA en
-- #232. Une periode a cheval sur deux grilles de modes differents est desormais
-- facturee sous-periode par sous-periode, chacune dans SON mode.
--
-- BACKFILL, sur les seules grilles PERSONNELLES (user_id IS NOT NULL) : c'est la
-- ou vit le contrat de l'utilisateur. Toutes ses grilles electricite recoivent son
-- mode — y compris les anciennes, car on ne sait pas a quelle date il a bascule :
-- reproduire le comportement actuel (mode global) est le seul report qui ne cree
-- pas d'information fausse. Il pourra ensuite dater sa bascule en scindant ses
-- grilles, ce que l'issue rend justement possible.
--
-- LIMITE ASSUMEE : rien n'est reporte sur les grilles PARTAGEES (user_id IS NULL,
-- catalogue admin) — les passer en dynamique les basculerait pour TOUS leurs
-- utilisateurs. Un compte en dynamique n'ayant AUCUNE grille electricite
-- personnelle repasse donc en tarif fixe et doit recreer une grille perso (ou
-- l'admin dupliquer la grille du catalogue). Pour les reperer AVANT d'appliquer :
--
--   SELECT p.user_id
--     FROM user_profiles p
--    WHERE p.pricing_mode <> 'fixed'
--      AND NOT EXISTS (SELECT 1 FROM tariff_grids g
--                       WHERE g.user_id = p.user_id AND g.energy_type = 'electricity');
--
-- IDEMPOTENCE : ADD/DROP COLUMN IF (NOT) EXISTS, et l'UPDATE devient un no-op des
-- que la colonne source a ete supprimee — a la reexecution, 2026-07-11 la recree
-- vide (defaut 'fixed'), donc le WHERE ne selectionne plus rien. L'ordre lexical
-- garantit aussi la convergence sur base fraiche : 2026-07-11 ajoute la colonne au
-- profil, l'UPDATE ne trouve aucune ligne (tables vides), le DROP la resupprime.
-- ============================================================

ALTER TABLE tariff_grids
    ADD COLUMN IF NOT EXISTS pricing_mode
        ENUM('fixed', 'dynamic_hourly', 'dynamic_quarter') NOT NULL DEFAULT 'fixed'
        COMMENT 'Electricite : mode de tarification du contrat (fixe / dynamique 1h / dynamique 15min)'
        AFTER energy_type;

UPDATE tariff_grids g
  JOIN user_profiles p ON p.user_id = g.user_id
   SET g.pricing_mode = p.pricing_mode
 WHERE g.energy_type = 'electricity'
   AND p.pricing_mode <> 'fixed';

ALTER TABLE user_profiles DROP COLUMN IF EXISTS pricing_mode;
