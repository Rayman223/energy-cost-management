-- ============================================================
-- Migration 2026-07-17 — TVA & marge fournisseur PAR UTILISATEUR (Issue #153, P3)
-- Sépare le marché (prix spot, token, zone : global au site) de l'économique
-- (TVA, marge fournisseur : propre à l'utilisateur, comme pricing_mode/bidding_zone).
--
-- Avant : dynamic_prices.vat_rate (fraction 0.21) + supplier_markup_per_kwh en
-- config globale → un membre FR hérite de la TVA BE de l'instance. Après : deux
-- colonnes user_profiles, unité alignée sur tariff_grids.vat_rate (pourcentage).
--
-- NOT NULL DEFAULT (pas NULL-able) : le fallback config est supprimé, il n'y a
-- plus rien à hériter. Type copié de tariff_grids.vat_rate (DECIMAL(5,2)) et
-- dynamic_prices.price_eur_kwh (DECIMAL(12,7)) — les deux s'additionnent dans la
-- formule de coût, une précision divergente = erreur d'arrondi silencieuse.
--
-- ALTER séparés + IF NOT EXISTS : idempotence (le DDL MySQL commit implicitement).
-- Baselinée dans schema.sql.
-- ============================================================

ALTER TABLE user_profiles
    ADD COLUMN IF NOT EXISTS vat_rate DECIMAL(5,2) NOT NULL DEFAULT 21.00
        COMMENT 'Taux de TVA en % applique au prix spot dynamique'
        AFTER pricing_mode;

ALTER TABLE user_profiles
    ADD COLUMN IF NOT EXISTS supplier_markup_per_kwh DECIMAL(12,7) NOT NULL DEFAULT 0.0000000
        COMMENT 'Marge fournisseur EUR/kWh ajoutee au prix spot TTC'
        AFTER vat_rate;
