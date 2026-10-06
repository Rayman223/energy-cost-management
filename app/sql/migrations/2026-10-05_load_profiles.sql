-- ============================================================
-- Migration 2026-10-05 — profils de charge (RLP Synergrid) — Issue #93
-- NON baselinee : idempotente, laissee hors du seed de schema.sql.
--
-- Deuxieme niveau de la cascade de ponderation du tarif indexe mensuel. Pour
-- reproduire la facture d'un contrat a prix variable, il faut ponderer les
-- cotations par le profil que le FOURNISSEUR applique — le RLP publie par
-- Synergrid — et non par la courbe reelle du client, qui donne un montant plus
-- juste physiquement mais different de la facture.
--
-- SERIE MESUREE, PAS MILLESIME FIGE. Le RLP (Real Load Profile) repose sur la
-- consommation REELLEMENT MESUREE d'un groupe de clients : c'est ce qui le
-- distingue du SLP, synthetique et defini a l'avance sur des historiques (bascule
-- operee avec MIG6, fin 2021). Les coefficients du mois M ne sont donc connus
-- qu'APRES M — et c'est exactement pourquoi le parametre Belpex_RLP_M n'est publie
-- qu'une fois le mois clos. Cette table se remplit donc au fil de l'eau, comme
-- `dynamic_prices`, et un import « toute l'annee a venir » est impossible par
-- construction.
--
-- Pas de colonne `profile_year` : elle serait redondante avec `slot_start`, qui
-- porte deja la date. L'identite d'un point est (code, pays, resolution, instant).
--
-- `slot_start` en UTC, exactement comme `dynamic_prices.period_start` : la jointure
-- poids <-> cotations devient une egalite de cle, et les changements d'heure comme
-- les jours feries sont geres par les donnees plutot que par une regle applicative.
--
-- NE PAS confondre avec le fichier de POIDS MENSUELS du RLP publie par Synergrid
-- (12 pourcentages par an et par GRD, agreges depuis les valeurs 15 min) : ceux-la
-- repartissent une consommation ANNUELLE entre les mois, ils ne ponderent pas des
-- cotations intra-mensuelles. Les importer ici donnerait un prix faux et
-- silencieusement plausible.
--
-- Volume : 35 040 lignes par profil et par an au pas de 15 min, soit quelques Mo —
-- negligeable, et borne par la clause de couverture du calcul.
-- ============================================================

CREATE TABLE IF NOT EXISTS load_profiles (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code           VARCHAR(32) NOT NULL
                   COMMENT 'Code du profil tel que publie : RLP0N, RLP0E, SPP, ...',
    country        CHAR(2) NOT NULL DEFAULT 'BE'
                   COMMENT 'ISO 3166-1 alpha-2 : le profil est reglementaire, donc national',
    slot_start     DATETIME NOT NULL
                   COMMENT 'Debut du creneau en UTC, meme convention que dynamic_prices.period_start',
    resolution_min SMALLINT UNSIGNED NOT NULL DEFAULT 15
                   COMMENT 'Resolution du point : 15 ou 60',
    fraction       DECIMAL(14,12) NOT NULL
                   COMMENT 'Poids du creneau. L echelle est indifferente : seule la ponderation relative compte',
    source         VARCHAR(40) NOT NULL DEFAULT 'synergrid',
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_load_profiles_slot (code, country, resolution_min, slot_start),
    INDEX idx_load_profiles_window (code, country, slot_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Le profil est CONTRACTUEL : le fournisseur reference « RLP » dans sa formule
-- d'indexation. Il appartient donc a la grille, ou il est versionne par
-- valid_from/valid_to comme la TVA (#232) et le mode (#245) — un changement de
-- fournisseur ne reecrit pas les mois deja factures.
ALTER TABLE tariff_grids
    ADD COLUMN IF NOT EXISTS load_profile_code VARCHAR(32) NULL
        COMMENT 'Electricite indexee : code du profil de ponderation (NULL = courbe reelle, sinon baseload)'
        AFTER pricing_mode;
