-- ============================================================
-- Migration 2026-07-11 — choix tarif fixe / dynamique + prix horaire natif (Issue #88)
--
-- 1) user_profiles.pricing_mode : type de tarification électricité choisi par
--    l'utilisateur.
--      * 'fixed'           = tarif classique (prix énergie de la grille tarifaire).
--      * 'dynamic_hourly'  = tarif dynamique au prix horaire (PT60M) ENTSO-E natif.
--      * 'dynamic_quarter' = tarif dynamique au quart d'heure (PT15M).
--    En mode dynamique, les lignes d'énergie fournisseur de la grille
--    (energy_simple/energy_t1/energy_t2) sont ignorées ; les autres lignes
--    (abonnement, distribution, taxes, injection) restent utilisées.
--    Défaut 'fixed' → aucun changement de comportement pour les profils existants.
--
-- 2) dynamic_prices : la clé unique passe de (energy_type, bidding_zone,
--    period_start) à (energy_type, bidding_zone, resolution_min, period_start).
--    ENTSO-E fournit un prix horaire natif (PT60M) DISTINCT de la moyenne des
--    points 15 min (PT15M) : sans resolution_min dans la clé, une ligne 60 min
--    et une ligne 15 min au même horodatage entrent en collision. La nouvelle
--    clé permet de conserver les deux résolutions côte à côte.
--
-- NON baselinée : elle ALTER des tables existantes (user_profiles,
-- dynamic_prices), donc exécutée par migrate.php sur les bases déjà déployées.
-- Idempotente (IF NOT EXISTS / IF EXISTS) pour être rejouable sans échec sur une
-- base fraîche où schema.sql l'a déjà appliquée.
-- ============================================================

ALTER TABLE user_profiles
    ADD COLUMN IF NOT EXISTS pricing_mode
        ENUM('fixed', 'dynamic_hourly', 'dynamic_quarter') NOT NULL DEFAULT 'fixed'
        COMMENT 'Type de tarification electricite choisi (fixe / dynamique 1h / dynamique 15min)'
        AFTER bidding_zone;

ALTER TABLE dynamic_prices
    DROP INDEX IF EXISTS uq_dynamic_prices;

ALTER TABLE dynamic_prices
    ADD UNIQUE KEY IF NOT EXISTS uq_dynamic_prices
        (energy_type, bidding_zone, resolution_min, period_start);
